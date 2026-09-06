<?php

use App\Models\Consultation;
use App\Models\PhysicianAvailabilitySession;
use App\Models\PhysicianSchedule;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\PhysicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/*
| Phase 2: the intake availability service layer. No routes, controllers or UI
| exist yet, so every test drives the service directly.
|
| Time is frozen with travelTo() in every test that depends on it — the same
| convention StaffInvitationCleanupTest uses — because schedule classification,
| staleness and presence freshness are all clock-sensitive.
|
| 2026-09-07 is a Monday, so day_of_week = 1 throughout.
*/

const INTAKE_MONDAY = '2026-09-07';

/**
 * Named distinctly from ConsultationConcurrencyTest's global makePhysician()
 * and PhysicianIntakeFoundationTest's makeIntakePhysician(), which would
 * otherwise redeclare when the suite loads every file in one run.
 */
function makeAvailabilityPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
        'account_status' => 'active',
        'online_status' => 'online',
        'last_seen_at' => now(),
    ], $overrides));
}

function intakeService(): PhysicianAvailabilityService
{
    return app(PhysicianAvailabilityService::class);
}

function makeMondayWindow(User $physician, string $start, string $end, bool $active = true): PhysicianSchedule
{
    return PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => $start,
        'end_time' => $end,
        'is_active' => $active,
    ]);
}

function makePendingConsultations(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

        Consultation::create([
            'patient_id' => $patient->user_id,
            'concern_category' => 'General',
            'symptoms_desc' => [['name' => 'Headache']],
            'online_reason' => 'Testing queue capacity',
            'request_status' => 'pending',
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Opening intake
|--------------------------------------------------------------------------
*/

it('opens an intake session for a physician', function () {
    $physician = makeAvailabilityPhysician();

    $session = intakeService()->open($physician);

    expect($session->physician_id)->toBe($physician->user_id)
        ->and($session->status)->toBe('open')
        ->and($session->started_at)->not->toBeNull()
        ->and($session->last_seen_at)->not->toBeNull()
        ->and($session->ended_at)->toBeNull();
});

it('reuses the same session when a physician opens intake twice', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();

    $first = $service->open($physician);
    $second = $service->open($physician);

    expect($second->id)->toBe($first->id)
        ->and(PhysicianAvailabilitySession::where('physician_id', $physician->user_id)->count())->toBe(1);
});

it('never leaves a physician with two open sessions across repeated opens', function () {
    // Stands in for the multiple-tab case: several opens arriving for the same
    // physician must converge on one row.
    $physician = makeAvailabilityPhysician();
    $service = intakeService();

    foreach (range(1, 5) as $ignored) {
        $service->open($physician);
    }

    expect(PhysicianAvailabilitySession::where('physician_id', $physician->user_id)
        ->where('status', 'open')
        ->count())->toBe(1);
});

it('refreshes last_seen_at when reopening an already open session', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $first = $service->open($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:45'));
    $second = $service->open($physician);

    expect($second->id)->toBe($first->id)
        ->and($second->last_seen_at->format('H:i:s'))->toBe('09:00:45')
        // started_at records when intake actually began and must not move.
        ->and($second->started_at->format('H:i:s'))->toBe('09:00:00');
});

it('lets separate physicians hold their own open sessions at once', function () {
    $first = makeAvailabilityPhysician();
    $second = makeAvailabilityPhysician();
    $service = intakeService();

    $service->open($first);
    $service->open($second);

    expect(PhysicianAvailabilitySession::where('status', 'open')->count())->toBe(2);
});

it('refuses to open intake for a user who is not a physician', function () {
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    expect(fn () => intakeService()->open($nurse))->toThrow(RuntimeException::class);

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Schedule classification
|--------------------------------------------------------------------------
*/

it('opens intake as overtime for a physician with no recurring schedule', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();

    expect(intakeService()->open($physician)->mode)->toBe('overtime');
});

it('opens intake as scheduled inside an active schedule window', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    makeMondayWindow($physician, '08:00:00', '12:00:00');

    expect(intakeService()->open($physician)->mode)->toBe('scheduled');
});

it('opens intake as overtime outside the schedule window', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 19:00:00'));

    $physician = makeAvailabilityPhysician();
    makeMondayWindow($physician, '08:00:00', '12:00:00');

    expect(intakeService()->open($physician)->mode)->toBe('overtime');
});

it('includes the start of a window and excludes its end', function (string $time, string $expected) {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' '.$time));

    $physician = makeAvailabilityPhysician();
    makeMondayWindow($physician, '08:00:00', '12:00:00');

    expect(intakeService()->open($physician)->mode)->toBe($expected);
})->with([
    'start is inside' => ['08:00:00', 'scheduled'],
    'a second after start' => ['08:00:01', 'scheduled'],
    'just before the end' => ['11:59:59', 'scheduled'],
    'the end itself is outside' => ['12:00:00', 'overtime'],
    'after the end' => ['12:00:01', 'overtime'],
    'before the start' => ['07:59:59', 'overtime'],
]);

it('evaluates several windows on the same day', function (string $time, string $expected) {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' '.$time));

    $physician = makeAvailabilityPhysician();
    makeMondayWindow($physician, '08:00:00', '12:00:00');
    makeMondayWindow($physician, '14:00:00', '17:00:00');
    makeMondayWindow($physician, '19:00:00', '21:00:00');

    expect(intakeService()->open($physician)->mode)->toBe($expected);
})->with([
    'morning window' => ['09:00:00', 'scheduled'],
    'lunch gap' => ['13:00:00', 'overtime'],
    'afternoon window' => ['15:30:00', 'scheduled'],
    'dinner gap' => ['18:00:00', 'overtime'],
    'evening window' => ['20:00:00', 'scheduled'],
    'after hours' => ['22:00:00', 'overtime'],
]);

it('ignores inactive schedule windows', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    makeMondayWindow($physician, '08:00:00', '12:00:00', active: false);

    expect(intakeService()->open($physician)->mode)->toBe('overtime');
});

it('ignores schedule windows belonging to another weekday', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();

    // Tuesday 08:00-12:00 must not classify a Monday session.
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 2,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    expect(intakeService()->open($physician)->mode)->toBe('overtime');
});

it('ignores schedule windows belonging to another physician', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    makeMondayWindow(makeAvailabilityPhysician(), '08:00:00', '12:00:00');

    expect(intakeService()->open($physician)->mode)->toBe('overtime');
});

it('still opens intake as overtime when schedule evaluation fails', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    makeMondayWindow($physician, '08:00:00', '12:00:00');

    // Force the lookup itself to fail. Classification is only a label, so the
    // physician must still end up with an open session.
    Schema::drop('physician_schedules');

    $session = intakeService()->open($physician);

    expect($session->status)->toBe('open')
        ->and($session->mode)->toBe('overtime');
});

it('keeps the mode stamped at open even after the schedule changes', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $window = makeMondayWindow($physician, '08:00:00', '12:00:00');
    $service = intakeService();

    $session = $service->open($physician);
    expect($session->mode)->toBe('scheduled');

    // The physician moves their hours, then time passes out of the old window.
    $window->update(['start_time' => '13:00:00', 'end_time' => '17:00:00']);
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 12:30:00'));

    $service->touch($physician);

    expect($session->fresh()->mode)->toBe('scheduled');
});

/*
|--------------------------------------------------------------------------
| Closing intake
|--------------------------------------------------------------------------
*/

it('closes an open session', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 11:00:00'));
    $closed = $service->close($physician);

    expect($closed->status)->toBe('closed')
        ->and($closed->ended_at)->not->toBeNull()
        ->and($closed->ended_at->format('H:i:s'))->toBe('11:00:00');
});

it('treats closing with nothing open as a no-op', function () {
    $physician = makeAvailabilityPhysician();

    expect(intakeService()->close($physician))->toBeNull()
        ->and(PhysicianAvailabilitySession::count())->toBe(0);
});

it('does not close another physician session', function () {
    $first = makeAvailabilityPhysician();
    $second = makeAvailabilityPhysician();
    $service = intakeService();

    $service->open($first);
    $secondSession = $service->open($second);

    $service->close($first);

    expect($secondSession->fresh()->status)->toBe('open');
});

it('leaves consultations and presence untouched when closing intake', function () {
    $physician = makeAvailabilityPhysician();
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultation = Consultation::create([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Unchanged by intake',
        'request_status' => 'active',
    ]);

    $service = intakeService();
    $service->open($physician);
    $service->close($physician);

    expect($consultation->fresh()->request_status)->toBe('active')
        ->and($physician->fresh()->online_status)->toBe('online')
        ->and($physician->fresh()->last_seen_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Heartbeat / touch
|--------------------------------------------------------------------------
*/

it('bumps last_seen_at on the open session', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:01:00'));
    $touched = $service->touch($physician);

    expect($touched->last_seen_at->format('H:i:s'))->toBe('09:01:00')
        ->and($touched->started_at->format('H:i:s'))->toBe('09:00:00');
});

it('does nothing when touching with no open session', function () {
    $physician = makeAvailabilityPhysician();

    expect(intakeService()->touch($physician))->toBeNull()
        ->and(PhysicianAvailabilitySession::count())->toBe(0);
});

it('never creates a session from a heartbeat', function () {
    // The heartbeat endpoint will be CSRF-exempt, so it must not be able to
    // bring intake into existence.
    $physician = makeAvailabilityPhysician();

    intakeService()->touch($physician);
    intakeService()->touch($physician);

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

it('does not reopen a closed session on heartbeat', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();

    $service->open($physician);
    $closed = $service->close($physician);

    expect($service->touch($physician))->toBeNull()
        ->and($closed->fresh()->status)->toBe('closed')
        ->and(PhysicianAvailabilitySession::count())->toBe(1);
});

it('does not reopen an expired session on heartbeat', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $session = $service->open($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:10:00'));
    $service->expireStaleSessions();

    expect($service->touch($physician))->toBeNull()
        ->and($session->fresh()->status)->toBe('expired')
        ->and(PhysicianAvailabilitySession::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

it('expires an open session whose heartbeat has gone quiet', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $session = intakeService()->open($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:05:00'));
    $expiredCount = intakeService()->expireStaleSessions();

    $session = $session->fresh();

    expect($expiredCount)->toBe(1)
        ->and($session->status)->toBe('expired')
        ->and($session->ended_at)->not->toBeNull()
        ->and($session->ended_at->format('H:i:s'))->toBe('09:05:00');
});

it('leaves a session that is still being heartbeaten open', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $session = intakeService()->open($physician);

    // 60 seconds in, well inside the 120-second threshold.
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:01:00'));

    expect(intakeService()->expireStaleSessions())->toBe(0)
        ->and($session->fresh()->status)->toBe('open');
});

it('uses the configured stale threshold rather than a hardcoded one', function () {
    config()->set('consultations.intake.stale_after_seconds', 600);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $session = intakeService()->open($physician);

    // Five minutes would be stale at the default 120s, but not at 600s.
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:05:00'));

    expect(intakeService()->expireStaleSessions())->toBe(0)
        ->and($session->fresh()->status)->toBe('open');
});

it('leaves closed and already expired sessions alone', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $closedPhysician = makeAvailabilityPhysician();
    $expiredPhysician = makeAvailabilityPhysician();
    $service = intakeService();

    $service->open($closedPhysician);
    $closed = $service->close($closedPhysician);
    $closedEndedAt = $closed->ended_at;

    $expiredSession = $service->open($expiredPhysician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:05:00'));
    $service->expireStaleSessions();
    $firstExpiredAt = $expiredSession->fresh()->ended_at;

    // A second run must change nothing — the operation is idempotent.
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:10:00'));
    $secondRunCount = $service->expireStaleSessions();

    expect($secondRunCount)->toBe(0)
        ->and($closed->fresh()->status)->toBe('closed')
        ->and($closed->fresh()->ended_at->format('H:i:s'))->toBe($closedEndedAt->format('H:i:s'))
        ->and($expiredSession->fresh()->status)->toBe('expired')
        ->and($expiredSession->fresh()->ended_at->format('H:i:s'))->toBe($firstExpiredAt->format('H:i:s'));
});

it('leaves consultations, schedule slots and presence untouched when expiring', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultation = Consultation::create([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Cough']],
        'online_reason' => 'Unchanged by expiry',
        'request_status' => 'active',
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => INTAKE_MONDAY,
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'booked',
    ]);

    intakeService()->open($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:05:00'));
    intakeService()->expireStaleSessions();

    expect($consultation->fresh()->request_status)->toBe('active')
        ->and($slot->fresh()->status)->toBe('booked')
        ->and($physician->fresh()->online_status)->toBe('online');
});

/*
|--------------------------------------------------------------------------
| Service-level availability
|--------------------------------------------------------------------------
*/

it('reports the service available for an eligible, present physician with intake open', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    expect($service->isServiceAvailable())->toBeTrue()
        ->and($service->hasOpenIntake())->toBeTrue()
        ->and($service->pendingQueueHasCapacity())->toBeTrue();
});

it('reports the service unavailable when nobody has opened intake', function () {
    makeAvailabilityPhysician();

    expect(intakeService()->isServiceAvailable())->toBeFalse();
});

it('reports the service unavailable once the only session goes stale', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:00:00'));

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    expect($service->isServiceAvailable())->toBeTrue();

    // Deliberately without running expireStaleSessions(): the read path must
    // decide this on its own, so a scheduler that never ran cannot leave the
    // clinic looking open.
    $this->travelTo(CarbonImmutable::parse(INTAKE_MONDAY.' 09:05:00'));
    // forceFill, because last_seen_at is not in User::$fillable — which is why
    // TrackUserPresence and PresenceController both write it through the query
    // builder rather than Eloquent. Kept fresh here so the assertion below can
    // only be caused by the session going stale, not by presence.
    $physician->forceFill(['last_seen_at' => now()])->save();

    expect($service->isServiceAvailable())->toBeFalse()
        ->and(PhysicianAvailabilitySession::where('status', 'open')->count())->toBe(1);
});

it('reports the service unavailable when the physician account is not active', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    $physician->update(['account_status' => 'suspended']);

    expect($service->isServiceAvailable())->toBeFalse();
});

it('reports the service unavailable when the session owner is no longer a physician', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    $physician->update(['role' => 'nurse']);

    expect($service->isServiceAvailable())->toBeFalse();
});

it('reports the service unavailable when the physician is offline', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    $physician->update(['online_status' => 'offline']);

    expect($service->isServiceAvailable())->toBeFalse();
});

it('reports the service unavailable when physician presence is stale', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    // Still 'online', but not seen inside the existing 2-minute convention.
    // forceFill because last_seen_at is not mass-assignable on User.
    $physician->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();

    expect($service->isServiceAvailable())->toBeFalse();
});

it('reports the service unavailable when the pending queue reaches the limit', function () {
    config()->set('consultations.intake.queue_limit', 3);

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    makePendingConsultations(3);

    expect($service->hasOpenIntake())->toBeTrue()
        ->and($service->pendingQueueHasCapacity())->toBeFalse()
        ->and($service->isServiceAvailable())->toBeFalse();
});

it('reports the service available again once the queue drops below the limit', function () {
    config()->set('consultations.intake.queue_limit', 3);

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    makePendingConsultations(3);
    expect($service->isServiceAvailable())->toBeFalse();

    // A nurse claiming a request moves it off 'pending' and frees capacity.
    Consultation::query()->pending()->first()->update(['request_status' => 'reviewed']);

    expect($service->isServiceAvailable())->toBeTrue();
});

it('counts only pending requests toward the queue limit', function () {
    config()->set('consultations.intake.queue_limit', 2);

    $physician = makeAvailabilityPhysician();
    $service = intakeService();
    $service->open($physician);

    makePendingConsultations(5);
    Consultation::query()->pending()->limit(4)->get()
        ->each(fn (Consultation $consultation) => $consultation->update(['request_status' => 'active']));

    // Four are active, one is pending: capacity remains.
    expect($service->pendingQueueHasCapacity())->toBeTrue();
});

it('needs only one eligible physician even when others are offline', function () {
    $online = makeAvailabilityPhysician();
    $offline = makeAvailabilityPhysician(['online_status' => 'offline']);
    $service = intakeService();

    $service->open($online);
    $service->open($offline);

    expect($service->isServiceAvailable())->toBeTrue();
});

it('reports the service unavailable when every physician session has been closed', function () {
    $first = makeAvailabilityPhysician();
    $second = makeAvailabilityPhysician();
    $service = intakeService();

    $service->open($first);
    $service->open($second);
    expect($service->isServiceAvailable())->toBeTrue();

    $service->close($first);
    expect($service->isServiceAvailable())->toBeTrue();

    $service->close($second);
    expect($service->isServiceAvailable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Read helper
|--------------------------------------------------------------------------
*/

it('returns the current open session for a physician and null otherwise', function () {
    $physician = makeAvailabilityPhysician();
    $service = intakeService();

    expect($service->currentSessionFor($physician))->toBeNull();

    $opened = $service->open($physician);
    expect($service->currentSessionFor($physician)->id)->toBe($opened->id);

    $service->close($physician);
    expect($service->currentSessionFor($physician))->toBeNull();
});
