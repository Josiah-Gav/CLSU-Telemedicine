<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Services\NotificationService;
use App\Services\PhysicianAvailabilityService;
use Illuminate\Console\Command;

/**
 * The intake janitor. Every minute it closes sessions past their planned end +
 * grace, expires sessions whose heartbeat has gone quiet, and warns physicians
 * whose planned end has arrived.
 *
 * Deliberately thin: every rule and every write lives in
 * PhysicianAvailabilityService, which stays the single writer of
 * physician_availability_sessions. Duplicating a threshold here would let the
 * janitor and the read path disagree.
 *
 * One command rather than a second one for the planned end: both steps write
 * the same table on the same cadence, and running them in one process gives
 * them a fixed order under one withoutOverlapping() lock instead of two
 * janitors racing on the same rows. The order matters — closing first means
 * a session that is already past its grace period is never warned, and a
 * session both stale and past its planned end is recorded with the more
 * specific planned-end reason.
 *
 * This command is a tidier, not the authority. The service already treats a
 * stale or past-grace session as unavailable at read time, so intake is
 * correctly closed to new requests whether or not this command has run yet.
 * What it adds is an honest stored status, the end-of-hours warning, and the
 * auto-closed notification for a physician who is no longer heartbeating.
 *
 * Safe to run repeatedly: each step only matches rows it has not already
 * handled.
 */
class ExpireStaleIntakeSessions extends Command
{
    protected $signature = 'consultations:expire-intake-sessions';

    protected $description = 'Close intake sessions past their planned end, expire stale ones, and warn at the planned end.';

    public function handle(PhysicianAvailabilityService $availabilityService): int
    {
        // Sends its own INTAKE_AUTO_CLOSED notifications (afterCommit, and
        // there is no transaction here, so immediately after each write).
        $autoClosedCount = $availabilityService->closeSessionsPastPlannedEnd()->count();

        $expiredCount = $availabilityService->expireStaleSessions();

        $warned = $availabilityService->claimDueEndWarnings();

        // After every write, outside any transaction or lock — the same shape
        // as MarkMissedScheduleSlots.
        foreach ($warned as $session) {
            NotificationService::send(
                $session->physician_id,
                NotificationType::INTAKE_END_WARNING,
                $session->mode === 'scheduled' ? 'Scheduled intake has ended' : 'Overtime limit reached',
                $availabilityService->endWarningMessage($session),
            );
        }

        $this->info('Closed '.$autoClosedCount.' intake session(s) past their planned end.');
        $this->info('Expired '.$expiredCount.' stale intake session(s).');
        $this->info('Warned '.$warned->count().' physician(s) at their planned end.');

        return self::SUCCESS;
    }
}
