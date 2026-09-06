<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\PhysicianAvailabilitySession;
use App\Models\PhysicianSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Owns physician consultation-intake availability — the single writer of
 * physician_availability_sessions.
 *
 * "Intake is open" means the telemedicine service is currently accepting NEW
 * patient consultation requests. It says nothing about whether a physician is
 * assigned to anyone, whether a nurse is working the queue, or whether any
 * consultation is running. In particular, closing or expiring intake never
 * touches a consultation: this class has no method that can claim, assign,
 * start, schedule, complete or cancel one, and PhysicianAvailabilitySession has
 * no relationship into the consultation tables. That is deliberate — an active
 * consultation must survive its physician going offline.
 *
 * Intake is also not presence. users.online_status is written automatically by
 * TrackUserPresence on every authenticated request and answers "is this person
 * connected?"; it can never express intent. Intake changes only through an
 * explicit open/close, a logout, or expiry.
 *
 * Authorization is the caller's job. These methods take a User model rather
 * than an id so a controller cannot accidentally address another physician's
 * session by passing a bare integer, but they do not check who is asking.
 */
class PhysicianAvailabilityService
{
    /**
     * How recently a physician must have been seen by the existing presence
     * system to count toward service availability.
     *
     * Deliberately a constant here rather than a new config key: this mirrors
     * the freshness rule NurseController::isUserOnline() and
     * PhysicianController::isUserOnline() already apply, and the existing
     * presence system is not being changed by this feature. Only the intake
     * session's own staleness is configurable, via
     * consultations.intake.stale_after_seconds.
     */
    private const PRESENCE_FRESHNESS_MINUTES = 2;

    /**
     * Open consultation intake for this physician, or return the session they
     * already have open.
     *
     * Idempotent on purpose. A physician with four tabs, or one who
     * double-clicks, must end up with exactly one open session — so an
     * existing open session is refreshed and returned rather than duplicated.
     *
     * Concurrency: the physician's users row is locked first. That row always
     * exists, whereas the session row may not, and a lock cannot be taken on a
     * row that has yet to be inserted — so locking the user is what actually
     * serialises two simultaneous opens. Same pattern as
     * ConsultationVideoService::startForPhysician() and
     * ConsultationOwnershipService. Locking one user row leaves every other
     * physician free to open intake concurrently.
     */
    public function open(User $physician): PhysicianAvailabilitySession
    {
        return DB::transaction(function () use ($physician) {
            $lockedPhysician = User::query()
                ->whereKey($physician->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            // A data-integrity guard, not an authorization check: whether this
            // physician may act is settled by the controller. This only keeps a
            // non-physician row out of a physician_ table.
            if ($lockedPhysician->role !== 'physician') {
                throw new RuntimeException('Only a physician can open consultation intake.');
            }

            $now = CarbonImmutable::now();

            $existing = $this->openSessionQuery($lockedPhysician)->lockForUpdate()->first();

            if ($existing) {
                $existing->update(['last_seen_at' => $now]);

                return $existing->fresh();
            }

            return PhysicianAvailabilitySession::create([
                'physician_id' => $lockedPhysician->user_id,
                'started_at' => $now,
                'last_seen_at' => $now,
                'ended_at' => null,
                'status' => 'open',
                'mode' => $this->evaluateMode($lockedPhysician, $now),
            ]);
        });
    }

    /**
     * Stop accepting new consultation requests. Returns null when there was
     * nothing open, which is a success, not an error — a second Stop click, or
     * a stop from a stale tab, must not fail.
     *
     * Closes intake and nothing else. Pending, reviewed, scheduled and active
     * consultations all continue through the normal workflow, and the
     * physician's presence fields are left alone.
     */
    public function close(User $physician): ?PhysicianAvailabilitySession
    {
        return DB::transaction(function () use ($physician) {
            $lockedPhysician = User::query()
                ->whereKey($physician->user_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPhysician) {
                return null;
            }

            $session = $this->openSessionQuery($lockedPhysician)->lockForUpdate()->first();

            if (! $session) {
                return null;
            }

            $session->update([
                'status' => 'closed',
                'ended_at' => CarbonImmutable::now(),
            ]);

            return $session->fresh();
        });
    }

    /**
     * Keep an already-open session alive. Called by the presence heartbeat.
     *
     * This can only ever bump a session that is already open. It never creates
     * one and never reopens a closed or expired one, because the endpoint that
     * will call it is CSRF-exempt — a CSRF-exempt request must not be able to
     * bring intake into existence. Opening intake is always a deliberate,
     * CSRF-protected action.
     *
     * No lock is taken: concurrent heartbeats from several tabs all write the
     * same value to the same row, so there is nothing to serialise.
     */
    public function touch(User $physician): ?PhysicianAvailabilitySession
    {
        $session = $this->openSessionQuery($physician)->first();

        if (! $session) {
            return null;
        }

        $session->update(['last_seen_at' => CarbonImmutable::now()]);

        return $session->fresh();
    }

    /**
     * The physician's currently open session, or null. Read-only.
     */
    public function currentSessionFor(User $physician): ?PhysicianAvailabilitySession
    {
        return $this->openSessionQuery($physician)->first();
    }

    /**
     * Expire every open session whose heartbeat has gone quiet, and report how
     * many were expired. Phase 5's scheduled command is the intended caller.
     *
     * One statement against one table on purpose. There is no cross-table
     * invariant to hold here — unlike MarkMissedScheduleSlots, which locks and
     * re-checks because it spans session, request and slot — so a bulk update
     * is both simpler and structurally incapable of reaching a consultation,
     * a schedule slot, or a presence field. It is idempotent: a second run
     * matches no rows because the first already moved them off 'open'. The only
     * possible race is a heartbeat bumping last_seen_at at the same instant,
     * and the WHERE is re-evaluated under the row lock at write time, so the
     * session is either expired (and the next heartbeat correctly finds nothing
     * open) or spared. Both outcomes are safe.
     */
    public function expireStaleSessions(): int
    {
        return PhysicianAvailabilitySession::query()
            ->where('status', 'open')
            ->where('last_seen_at', '<=', $this->staleBefore())
            ->update([
                'status' => 'expired',
                'ended_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * Can a patient submit a NEW consultation request right now?
     *
     * Returns a plain boolean: no physician identity, no counts, no session
     * details. Callers that need to tell a full queue apart from a closed
     * service can ask the two questions below separately.
     */
    public function isServiceAvailable(): bool
    {
        return $this->hasOpenIntake() && $this->pendingQueueHasCapacity();
    }

    /**
     * Does at least one eligible physician currently have a valid open intake
     * session?
     *
     * Eligible is the definition the application already uses for staff
     * fan-out (NotificationService::sendToRole): role plus an active account.
     *
     * Freshness is evaluated here, at read time, rather than trusting
     * status = 'open' alone. The expiry command is only a janitor — if it never
     * ran, a session left open by a laptop that closed hours ago would still
     * read as open, and the clinic would appear to be accepting requests all
     * day. Presence is checked as a second, cheap guard: the same heartbeat
     * feeds both clocks, so it is near-redundant, but it catches a logout whose
     * session close failed to persist.
     */
    public function hasOpenIntake(): bool
    {
        $presenceCutoff = CarbonImmutable::now()->subMinutes(self::PRESENCE_FRESHNESS_MINUTES);

        return PhysicianAvailabilitySession::query()
            ->where('status', 'open')
            ->where('last_seen_at', '>', $this->staleBefore())
            ->whereHas('physician', function ($query) use ($presenceCutoff) {
                $query->where('role', 'physician')
                    ->where('account_status', 'active')
                    ->where('online_status', 'online')
                    ->where('last_seen_at', '>', $presenceCutoff);
            })
            ->exists();
    }

    /**
     * Is the nurse-review queue below its configured ceiling?
     *
     * Counted through the canonical Consultation::pending() scope, so this can
     * never disagree with the queue a nurse actually sees. Global, not
     * per-physician: a request has no assigned physician when it is created.
     * Only pending requests count — reviewed, scheduled and active ones have
     * already left the queue.
     */
    public function pendingQueueHasCapacity(): bool
    {
        return Consultation::pending()->count() < (int) config('consultations.intake.queue_limit');
    }

    /**
     * Label a session about to be opened as normal hours or an out-of-schedule
     * override, from the physician's own recurring schedule.
     *
     * Called once, when the session is created, and never again — a session
     * opened at 16:55 inside a 14:00-17:00 window stays 'scheduled' afterwards,
     * and editing the schedule later changes only future sessions.
     *
     * Windows are half-open, [start, end): a window of 08:00-12:00 includes
     * 08:00:00 and excludes 12:00:00. This matches how
     * PhysicianController::overlapsExistingSlots() already treats slot ranges,
     * so adjacent windows never both match.
     *
     * The label must never be able to stop a physician from opening intake, so
     * anything unexpected here falls back to 'overtime' rather than
     * propagating: no schedule rows, none matching, or a failure of the lookup
     * itself. A genuine failure to persist the session is different and is
     * allowed to surface — that happens in open(), outside this try.
     */
    private function evaluateMode(User $physician, CarbonImmutable $now): string
    {
        try {
            $windows = PhysicianSchedule::query()
                ->where('physician_id', $physician->user_id)
                // dayOfWeek is 0=Sunday..6=Saturday, which is exactly what
                // physician_schedules.day_of_week stores. See PhysicianSchedule.
                ->where('day_of_week', $now->dayOfWeek)
                ->where('is_active', true)
                ->get(['start_time', 'end_time']);

            $today = $now->toDateString();

            foreach ($windows as $window) {
                // The stored values are local wall-clock times ('14:00:00'),
                // so they are anchored to today's date and compared as real
                // instants rather than as strings.
                $start = CarbonImmutable::parse($today.' '.$window->start_time);
                $end = CarbonImmutable::parse($today.' '.$window->end_time);

                if ($now->greaterThanOrEqualTo($start) && $now->lessThan($end)) {
                    return 'scheduled';
                }
            }
        } catch (Throwable $exception) {
            Log::error('Physician schedule evaluation failed, defaulting intake session to overtime: '.$exception->getMessage());
        }

        return 'overtime';
    }

    /**
     * The instant an intake session must have been seen after to still count as
     * alive. Read from config so the margin can be tuned per deployment.
     */
    private function staleBefore(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds((int) config('consultations.intake.stale_after_seconds'));
    }

    /**
     * This physician's open session, newest first. The ordering is defensive:
     * open() makes a second open row impossible, and this guarantees that if
     * one ever appeared anyway, every method here would agree on which session
     * is current.
     */
    private function openSessionQuery(User $physician)
    {
        return PhysicianAvailabilitySession::query()
            ->where('physician_id', $physician->user_id)
            ->where('status', 'open')
            ->latest('id');
    }
}
