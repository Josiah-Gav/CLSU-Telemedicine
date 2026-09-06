<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ConsultationVideoSession;
use App\Models\FollowUpRequest;
use App\Models\PhysicianAvailabilitySession;
use App\Models\PhysicianSchedule;
use App\Models\ScheduleSlot;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Phase 4: the physician-facing open/close/heartbeat controls wired to
| PhysicianAvailabilityService.
|
| Every state transition here goes through that service — these tests assert
| the endpoints' behaviour and, just as importantly, that nothing outside
| physician_availability_sessions moves as a result.
|
| 2026-09-07 is a Monday, so day_of_week = 1 in the schedule fixtures.
*/

const INTAKE_CONTROLS_MONDAY = '2026-09-07';

/**
 * Named distinctly from the other suites' physician helpers (makePhysician,
 * makeIntakePhysician, makeAvailabilityPhysician, makeScheduleManagingPhysician)
 * so the whole suite can load in one run without a redeclaration fatal.
 */
function makeIntakeControlPhysician(array $overrides = []): User
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

function intakeControlRoute(string $name, User $physician): string
{
    return route($name, ['physician' => $physician->user_id]);
}

function openIntakeSessionFor(User $physician, string $mode = 'overtime', string $status = 'open'): PhysicianAvailabilitySession
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
| Open endpoint
|--------------------------------------------------------------------------
*/

it('lets a physician open consultation intake', function () {
    $physician = makeIntakeControlPhysician();

    $response = $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))
        ->assertOk();

    expect($response->json('success'))->toBeTrue()
        ->and($response->json('intake.state'))->toBe('open');
});

it('creates exactly one open availability session when intake is opened', function () {
    $physician = makeIntakeControlPhysician();

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))
        ->assertOk();

    $session = PhysicianAvailabilitySession::where('physician_id', $physician->user_id)->sole();

    expect($session->status)->toBe('open')
        ->and($session->started_at)->not->toBeNull()
        ->and($session->last_seen_at)->not->toBeNull()
        ->and($session->ended_at)->toBeNull();
});

it('does not create a duplicate session when intake is opened repeatedly', function () {
    // The multiple-tab / double-click case: the service reuses the open
    // session, so the endpoint must keep reporting success.
    $physician = makeIntakeControlPhysician();

    foreach (range(1, 4) as $ignored) {
        $this->actingAs($physician)
            ->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))
            ->assertOk()
            ->assertJsonPath('intake.state', 'open');
    }

    expect(PhysicianAvailabilitySession::where('physician_id', $physician->user_id)->count())->toBe(1);
});

it('opens intake as scheduled inside a recurring schedule window', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 09:00:00'));

    $physician = makeIntakeControlPhysician();
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))
        ->assertOk()
        ->assertJsonPath('intake.mode', 'scheduled')
        ->assertJsonPath('intake.mode_label', 'Scheduled Intake');
});

it('opens intake as overtime outside a recurring schedule window', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 19:00:00'));

    $physician = makeIntakeControlPhysician();
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))
        ->assertOk()
        ->assertJsonPath('intake.mode', 'overtime')
        ->assertJsonPath('intake.mode_label', 'Overtime Intake');
});

it('refuses one physician opening another physician\'s intake', function () {
    $owner = makeIntakeControlPhysician();
    $intruder = makeIntakeControlPhysician();

    $this->actingAs($intruder)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $owner))
        ->assertForbidden();

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

it('refuses a non-physician opening physician intake', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'user_type' => 'staff']);

    $this->actingAs($user)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $user))
        ->assertForbidden();

    expect(PhysicianAvailabilitySession::count())->toBe(0);
})->with(['nurse', 'admin', 'patient']);

/*
|--------------------------------------------------------------------------
| Close endpoint
|--------------------------------------------------------------------------
*/

it('lets a physician close their own intake', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 09:00:00'));

    $physician = makeIntakeControlPhysician();
    $session = openIntakeSessionFor($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 11:00:00'));

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.close', $physician))
        ->assertOk()
        ->assertJsonPath('intake.state', 'closed');

    $session = $session->fresh();

    expect($session->status)->toBe('closed')
        ->and($session->ended_at)->not->toBeNull()
        ->and($session->ended_at->format('H:i:s'))->toBe('11:00:00');
});

it('treats closing with no open session as harmless', function () {
    $physician = makeIntakeControlPhysician();

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.close', $physician))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.state', 'closed');

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

it('refuses one physician closing another physician\'s intake', function () {
    $owner = makeIntakeControlPhysician();
    $intruder = makeIntakeControlPhysician();
    $session = openIntakeSessionFor($owner);

    $this->actingAs($intruder)
        ->postJson(intakeControlRoute('physician.consultation_intake.close', $owner))
        ->assertForbidden();

    expect($session->fresh()->status)->toBe('open');
});

it('does not let a physician close another physician\'s session from their own endpoint', function () {
    // Even acting entirely within their own authorized route, a physician can
    // only ever reach their own session — identity comes from the session,
    // never the URL or the payload.
    $owner = makeIntakeControlPhysician();
    $intruder = makeIntakeControlPhysician();
    $ownerSession = openIntakeSessionFor($owner);

    $this->actingAs($intruder)
        ->postJson(intakeControlRoute('physician.consultation_intake.close', $intruder), [
            'physician_id' => $owner->user_id,
            'session_id' => $ownerSession->id,
        ])
        ->assertOk();

    expect($ownerSession->fresh()->status)->toBe('open');
});

it('closing intake leaves consultations, slots and presence untouched', function () {
    $physician = makeIntakeControlPhysician();
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultationRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Unaffected by closing intake',
        'request_status' => 'active',
    ]);

    $session = ConsultationSession::create([
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
        'online_reason' => 'Still waiting for a nurse',
        'request_status' => 'pending',
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'booked',
    ]);

    openIntakeSessionFor($physician);

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.close', $physician))
        ->assertOk();

    expect($consultationRequest->fresh()->request_status)->toBe('active')
        ->and($session->fresh()->consultation_status)->toBe('active')
        ->and($pending->fresh()->request_status)->toBe('pending')
        ->and($slot->fresh()->status)->toBe('booked')
        ->and($physician->fresh()->online_status)->toBe('online')
        ->and($physician->fresh()->last_seen_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Heartbeat
|--------------------------------------------------------------------------
*/

it('bumps last_seen_at on an open session', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 09:00:00'));

    $physician = makeIntakeControlPhysician();
    $session = openIntakeSessionFor($physician);

    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 09:01:00'));

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertOk()
        ->assertJsonPath('open', true);

    $session = $session->fresh();

    expect($session->last_seen_at->format('H:i:s'))->toBe('09:01:00')
        // started_at records when intake began and must never move.
        ->and($session->started_at->format('H:i:s'))->toBe('09:00:00');
});

it('never creates a session from a heartbeat', function () {
    $physician = makeIntakeControlPhysician();

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertOk()
        ->assertJsonPath('open', false);

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

it('does not reopen a closed session on heartbeat', function () {
    $physician = makeIntakeControlPhysician();
    $session = openIntakeSessionFor($physician, status: 'closed');

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertOk()
        ->assertJsonPath('open', false);

    expect($session->fresh()->status)->toBe('closed')
        ->and(PhysicianAvailabilitySession::count())->toBe(1);
});

it('does not reopen an expired session on heartbeat', function () {
    $physician = makeIntakeControlPhysician();
    $session = openIntakeSessionFor($physician, status: 'expired');

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertOk()
        ->assertJsonPath('open', false)
        ->assertJsonPath('intake.state', 'expired');

    expect($session->fresh()->status)->toBe('expired')
        ->and(PhysicianAvailabilitySession::count())->toBe(1);
});

it('cannot heartbeat another physician\'s session', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 09:00:00'));

    $owner = makeIntakeControlPhysician();
    $intruder = makeIntakeControlPhysician();
    $ownerSession = openIntakeSessionFor($owner);

    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 09:05:00'));

    // Through the owner's route: blocked by authorization.
    $this->actingAs($intruder)
        ->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $owner))
        ->assertForbidden();

    // Through their own route while naming the owner: identity comes from the
    // authenticated session, so the owner's row is never reached.
    $this->actingAs($intruder)
        ->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $intruder), [
            'physician_id' => $owner->user_id,
            'session_id' => $ownerSession->id,
        ])
        ->assertOk()
        ->assertJsonPath('open', false);

    expect($ownerSession->fresh()->last_seen_at->format('H:i:s'))->toBe('09:00:00');
});

it('refuses a non-physician heartbeat and creates no intake state', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'user_type' => 'staff']);

    $this->actingAs($user)
        ->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $user))
        ->assertForbidden();

    expect(PhysicianAvailabilitySession::count())->toBe(0);
})->with(['nurse', 'admin', 'patient']);

/*
|--------------------------------------------------------------------------
| Session display on the Consultation Intake page
|--------------------------------------------------------------------------
*/

it('renders a closed state when the physician has never opened intake', function () {
    $physician = makeIntakeControlPhysician();

    $response = $this->actingAs($physician)
        ->get(intakeControlRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->assertSee('Current Consultation Intake');

    expect($response->viewData('intake')['state'])->toBe('closed')
        ->and($response->viewData('intake')['status_label'])->toBe('Not Accepting New Consultations');
});

it('renders an open state with its stored scheduled mode', function () {
    $physician = makeIntakeControlPhysician();
    openIntakeSessionFor($physician, mode: 'scheduled');

    $intake = $this->actingAs($physician)
        ->get(intakeControlRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->viewData('intake');

    expect($intake['state'])->toBe('open')
        ->and($intake['mode'])->toBe('scheduled')
        ->and($intake['mode_label'])->toBe('Scheduled Intake')
        ->and($intake['status_label'])->toBe('Accepting New Consultations')
        ->and($intake['started_at'])->not->toBeNull();
});

it('renders an open session\'s stored overtime mode', function () {
    $physician = makeIntakeControlPhysician();
    openIntakeSessionFor($physician, mode: 'overtime');

    $intake = $this->actingAs($physician)
        ->get(intakeControlRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->viewData('intake');

    expect($intake['mode'])->toBe('overtime')
        ->and($intake['mode_label'])->toBe('Overtime Intake');
});

it('never recomputes an open session\'s mode after the recurring schedule changes', function () {
    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 09:00:00'));

    $physician = makeIntakeControlPhysician();
    $window = PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))
        ->assertOk()
        ->assertJsonPath('intake.mode', 'scheduled');

    // Move the window away and step past its old end. The stored mode is
    // authoritative and must survive both.
    $window->update(['start_time' => '13:00:00', 'end_time' => '17:00:00']);
    $this->travelTo(CarbonImmutable::parse(INTAKE_CONTROLS_MONDAY.' 12:30:00'));

    $intake = $this->actingAs($physician)
        ->get(intakeControlRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->viewData('intake');

    expect($intake['state'])->toBe('open')
        ->and($intake['mode'])->toBe('scheduled');
});

it('does not treat an expired session as currently open', function () {
    $physician = makeIntakeControlPhysician();
    openIntakeSessionFor($physician, status: 'expired');

    $intake = $this->actingAs($physician)
        ->get(intakeControlRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->viewData('intake');

    expect($intake['state'])->toBe('expired')
        ->and($intake['status_label'])->toBe('Session Expired');
});

it('does not treat a closed session as currently open', function () {
    $physician = makeIntakeControlPhysician();
    openIntakeSessionFor($physician, status: 'closed');

    $intake = $this->actingAs($physician)
        ->get(intakeControlRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->viewData('intake');

    expect($intake['state'])->toBe('closed');
});

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
*/

it('closes an open intake session when the physician logs out', function () {
    $physician = makeIntakeControlPhysician();
    $session = openIntakeSessionFor($physician);

    $this->actingAs($physician)->post('/logout')->assertRedirect('/');

    $session = $session->fresh();

    expect($session->status)->toBe('closed')
        ->and($session->ended_at)->not->toBeNull();
});

it('creates no intake session when a physician without one logs out', function () {
    $physician = makeIntakeControlPhysician();

    $this->actingAs($physician)->post('/logout')->assertRedirect('/');

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

it('leaves logout unchanged for non-physicians', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'user_type' => 'staff', 'online_status' => 'online']);

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    // The existing presence cleanup still runs, and no intake state appears.
    expect($user->fresh()->online_status)->toBe('offline')
        ->and(PhysicianAvailabilitySession::count())->toBe(0);
})->with(['nurse', 'admin', 'patient']);

it('leaves an active consultation active when the physician logs out', function () {
    $physician = makeIntakeControlPhysician();
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultationRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Survives logout',
        'request_status' => 'active',
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    openIntakeSessionFor($physician);

    $this->actingAs($physician)->post('/logout')->assertRedirect('/');

    expect($consultationRequest->fresh()->request_status)->toBe('active')
        ->and($session->fresh()->consultation_status)->toBe('active')
        ->and($session->fresh()->completed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Isolation
|--------------------------------------------------------------------------
*/

it('intake controls never touch consultations, slots, schedules, video or follow-ups', function () {
    $physician = makeIntakeControlPhysician();
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultationRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Untouched by intake controls',
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

    $video = ConsultationVideoSession::create([
        'consultation_id' => $consultationSession->id,
        'room_name' => 'room-phase-4-isolation',
    ]);

    $followUp = FollowUpRequest::create([
        'consultation_id' => $consultationSession->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Untouched by intake controls',
        'status' => 'pending',
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'available',
    ]);

    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    $consultationCountBefore = Consultation::count();

    $this->actingAs($physician)->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))->assertOk();
    $this->actingAs($physician)->postJson(intakeControlRoute('physician.consultation_intake.heartbeat', $physician))->assertOk();
    $this->actingAs($physician)->postJson(intakeControlRoute('physician.consultation_intake.close', $physician))->assertOk();

    expect(Consultation::count())->toBe($consultationCountBefore)
        ->and($consultationRequest->fresh()->request_status)->toBe('active')
        ->and($consultationSession->fresh()->consultation_status)->toBe('active')
        ->and($video->fresh()->ended_at)->toBeNull()
        ->and($followUp->fresh()->status)->toBe('pending')
        ->and($slot->fresh()->status)->toBe('available')
        ->and($schedule->fresh()->is_active)->toBeTrue()
        ->and($schedule->fresh()->start_time)->toBe('08:00:00');
});

it('opening intake creates no consultation request', function () {
    $physician = makeIntakeControlPhysician();

    $this->actingAs($physician)
        ->postJson(intakeControlRoute('physician.consultation_intake.open', $physician))
        ->assertOk();

    expect(Consultation::count())->toBe(0)
        ->and(ConsultationSession::count())->toBe(0);
});
