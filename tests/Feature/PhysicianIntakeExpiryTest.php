<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\PhysicianAvailabilitySession;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\PhysicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/*
| Phase 5: the scheduled expiry of stale intake sessions, and the heartbeat
| continuity that keeps a working physician's session alive across pages.
|
| The command is deliberately thin — the staleness rule lives in
| PhysicianAvailabilityService::expireStaleSessions() — so these tests assert
| the command's wiring and its blast radius rather than re-testing the rule.
|
| 2026-09-07 is a Monday.
*/

const INTAKE_EXPIRY_MONDAY = '2026-09-07';

/**
 * Named distinctly from the other suites' physician helpers so the whole
 * suite can load in one run without a redeclaration fatal.
 */
function makeExpiringPhysician(array $overrides = []): User
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

function makeIntakeSession(User $physician, string $status = 'open', string $mode = 'overtime'): PhysicianAvailabilitySession
{
    return PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'ended_at' => $status === 'open' ? null : now(),
        'status' => $status,
        'mode' => $mode,
    ]);
}

/*
|--------------------------------------------------------------------------
| The expiry command
|--------------------------------------------------------------------------
*/

it('succeeds when there is nothing stale to expire', function () {
    $this->artisan('consultations:expire-intake-sessions')
        ->expectsOutputToContain('Expired 0 stale intake session(s).')
        ->assertSuccessful();
});

it('expires a single stale open session', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));

    $this->artisan('consultations:expire-intake-sessions')
        ->expectsOutputToContain('Expired 1 stale intake session(s).')
        ->assertSuccessful();

    $session = $session->fresh();

    expect($session->status)->toBe('expired')
        ->and($session->ended_at)->not->toBeNull()
        ->and($session->ended_at->format('H:i:s'))->toBe('09:05:00');
});

it('expires every stale session in one run', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $sessions = collect(range(1, 3))->map(fn () => makeIntakeSession(makeExpiringPhysician()));

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));

    $this->artisan('consultations:expire-intake-sessions')
        ->expectsOutputToContain('Expired 3 stale intake session(s).')
        ->assertSuccessful();

    $sessions->each(fn (PhysicianAvailabilitySession $session) => expect($session->fresh()->status)->toBe('expired'));
});

it('leaves a session that is still being heartbeaten open', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician);

    // 60 seconds in, comfortably inside the configured threshold.
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:01:00'));

    $this->artisan('consultations:expire-intake-sessions')
        ->expectsOutputToContain('Expired 0 stale intake session(s).')
        ->assertSuccessful();

    expect($session->fresh()->status)->toBe('open');
});

it('leaves a closed session untouched', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician, status: 'closed');
    $endedAt = $session->ended_at->format('H:i:s');

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));

    $this->artisan('consultations:expire-intake-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe('closed')
        ->and($session->fresh()->ended_at->format('H:i:s'))->toBe($endedAt);
});

it('leaves an already expired session untouched and is safe to run repeatedly', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));
    $this->artisan('consultations:expire-intake-sessions')->assertSuccessful();
    $firstEndedAt = $session->fresh()->ended_at->format('H:i:s');

    // A second run in a later minute must find nothing and change nothing.
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:10:00'));
    $this->artisan('consultations:expire-intake-sessions')
        ->expectsOutputToContain('Expired 0 stale intake session(s).')
        ->assertSuccessful();

    expect($session->fresh()->status)->toBe('expired')
        ->and($session->fresh()->ended_at->format('H:i:s'))->toBe($firstEndedAt);
});

it('honours the configured stale threshold rather than a hardcoded one', function () {
    config()->set('consultations.intake.stale_after_seconds', 600);

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician);

    // Five minutes is stale at the default 120s but fresh at 600s.
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));

    $this->artisan('consultations:expire-intake-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe('open');
});

it('expires only intake sessions, never consultations or slots', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultationRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Survives intake expiry',
        'request_status' => 'active',
    ]);

    $consultationSession = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $pending = Consultation::create([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Cough']],
        'online_reason' => 'Still queued for a nurse',
        'request_status' => 'pending',
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY)->addDay()->toDateString(),
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'booked',
    ]);

    makeIntakeSession($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));
    $this->artisan('consultations:expire-intake-sessions')->assertSuccessful();

    expect($consultationRequest->fresh()->request_status)->toBe('active')
        ->and($consultationSession->fresh()->consultation_status)->toBe('active')
        ->and($consultationSession->fresh()->completed_at)->toBeNull()
        ->and($pending->fresh()->request_status)->toBe('pending')
        ->and($slot->fresh()->status)->toBe('booked')
        ->and($physician->fresh()->online_status)->toBe('online');
});

/*
|--------------------------------------------------------------------------
| Scheduler registration
|--------------------------------------------------------------------------
*/

it('registers the expiry command on the scheduler every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'consultations:expire-intake-sessions'));

    expect($event)->not->toBeNull()
        // Laravel's cron expression for everyMinute().
        ->and($event->expression)->toBe('* * * * *');
});

/*
|--------------------------------------------------------------------------
| Heartbeat continuity across authenticated pages
|--------------------------------------------------------------------------
*/

it('keeps intake alive while the physician works on another authenticated page', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician);

    // The physician has navigated away from the Consultation Intake page; the
    // layout heartbeat on whatever page they are on now issues the touch.
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:01:30'));

    $this->actingAs($physician)
        ->postJson(route('physician.consultation_intake.heartbeat', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertJsonPath('open', true);

    expect($session->fresh()->last_seen_at->format('H:i:s'))->toBe('09:01:30');

    // Because the touch landed, the session is no longer stale when the
    // scheduler runs a moment later.
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:02:00'));
    $this->artisan('consultations:expire-intake-sessions')
        ->expectsOutputToContain('Expired 0 stale intake session(s).')
        ->assertSuccessful();

    expect($session->fresh()->status)->toBe('open');
});

it('lets intake expire once the physician stops using the application', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician);

    // No heartbeat from any page.
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));
    $this->artisan('consultations:expire-intake-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe('expired');
});

it('renders the shared heartbeat flag as open for a physician with intake open', function () {
    $physician = makeExpiringPhysician();
    makeIntakeSession($physician);

    // Any authenticated physician page carries the layout, so the flag travels
    // with them off the Consultation Intake page.
    $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertSee('telemedIntakeHeartbeat', false)
        ->assertSee('open: true', false);
});

it('renders the shared heartbeat flag as closed when the physician has no open session', function (string $status) {
    $physician = makeExpiringPhysician();

    if ($status !== 'none') {
        makeIntakeSession($physician, status: $status);
    }

    $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertSee('open: false', false);
})->with(['none', 'closed', 'expired']);

it('gives non-physicians no intake heartbeat url', function () {
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    $this->actingAs($nurse)
        ->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]))
        ->assertOk()
        ->assertSee('url: null', false);
});

it('does not reopen a closed or expired session through the heartbeat', function (string $status) {
    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician, status: $status);

    $this->actingAs($physician)
        ->postJson(route('physician.consultation_intake.heartbeat', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertJsonPath('open', false);

    expect($session->fresh()->status)->toBe($status)
        ->and(PhysicianAvailabilitySession::count())->toBe(1);
})->with(['closed', 'expired']);

it('never touches another physician\'s session through the heartbeat', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $owner = makeExpiringPhysician();
    $intruder = makeExpiringPhysician();
    $ownerSession = makeIntakeSession($owner);

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:03:00'));

    $this->actingAs($intruder)
        ->postJson(route('physician.consultation_intake.heartbeat', ['physician' => $owner->user_id]))
        ->assertForbidden();

    expect($ownerSession->fresh()->last_seen_at->format('H:i:s'))->toBe('09:00:00');
});

it('refuses an unauthenticated intake heartbeat', function () {
    $physician = makeExpiringPhysician();
    $session = makeIntakeSession($physician);

    $this->postJson(route('physician.consultation_intake.heartbeat', ['physician' => $physician->user_id]))
        ->assertUnauthorized();

    expect($session->fresh()->status)->toBe('open');
});

it('refuses a non-physician intake heartbeat', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'user_type' => 'staff']);

    $this->actingAs($user)
        ->postJson(route('physician.consultation_intake.heartbeat', ['physician' => $user->user_id]))
        ->assertForbidden();

    expect(PhysicianAvailabilitySession::count())->toBe(0);
})->with(['nurse', 'admin', 'patient']);

/*
|--------------------------------------------------------------------------
| Reopening after expiry
|--------------------------------------------------------------------------
*/

it('requires an explicit reopen after expiry and leaves the expired row intact', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:00:00'));

    $physician = makeExpiringPhysician();
    $expiredSession = makeIntakeSession($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:05:00'));
    $this->artisan('consultations:expire-intake-sessions')->assertSuccessful();

    $availability = app(PhysicianAvailabilityService::class);
    expect($availability->currentSessionFor($physician))->toBeNull();

    // A heartbeat must not bring it back.
    $this->actingAs($physician)
        ->postJson(route('physician.consultation_intake.heartbeat', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertJsonPath('open', false);

    expect(PhysicianAvailabilitySession::count())->toBe(1);

    // Only the physician's explicit action opens intake again, and it does so
    // as a brand-new session; the expired one is history and stays that way.
    $this->travelTo(CarbonImmutable::parse(INTAKE_EXPIRY_MONDAY.' 09:10:00'));
    $this->actingAs($physician)
        ->postJson(route('physician.consultation_intake.open', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertJsonPath('intake.state', 'open');

    $expiredSession = $expiredSession->fresh();
    $newSession = $availability->currentSessionFor($physician);

    expect(PhysicianAvailabilitySession::count())->toBe(2)
        ->and($expiredSession->status)->toBe('expired')
        ->and($expiredSession->ended_at->format('H:i:s'))->toBe('09:05:00')
        ->and($newSession->id)->not->toBe($expiredSession->id)
        ->and($newSession->status)->toBe('open')
        ->and($newSession->started_at->format('H:i:s'))->toBe('09:10:00');
});
