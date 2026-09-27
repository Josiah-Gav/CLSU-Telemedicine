<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\Notification;
use App\Models\PhysicianAvailabilitySession;
use App\Models\PhysicianSchedule;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\PhysicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
| The planned intake end: every session records when it is planned to end,
| the physician is warned at that moment, can confirm they are present
| (Continue as overtime), and is closed automatically after the grace period.
|
| Defaults under test: 15-minute grace, 120-minute overtime cap (pinned in
| phpunit.xml so a developer's .env cannot change them).
|
| 2026-09-07 is a Monday.
*/

const PLANNED_END_MONDAY = '2026-09-07';

function plannedEndAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse(PLANNED_END_MONDAY.' '.$time);
}

function makePlannedEndPhysician(): User
{
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
        'account_status' => 'active',
        'online_status' => 'online',
    ]);

    keepPlannedEndPresenceFresh($physician);

    return $physician->refresh();
}

/**
 * Presence and the intake heartbeat both go stale in two minutes. Tests that
 * travel forward in time refresh both, so the only thing that can close the
 * session is the rule under test.
 */
function keepPlannedEndPresenceFresh(User $physician): void
{
    DB::table('users')->where('user_id', $physician->user_id)->update([
        'online_status' => 'online',
        'last_seen_at' => now(),
    ]);

    PhysicianAvailabilitySession::query()
        ->where('physician_id', $physician->user_id)
        ->where('status', 'open')
        ->update(['last_seen_at' => now()]);
}

function mondayWindow(User $physician, string $start, string $end): PhysicianSchedule
{
    return PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

function openPlannedEndIntake(User $physician): PhysicianAvailabilitySession
{
    return app(PhysicianAvailabilityService::class)->open($physician);
}

/**
 * Opens a session in the given mode: scheduled inside an 08:00-12:00 window
 * at 09:00 (planned end 12:00), overtime at 19:00 (planned end 21:00).
 */
function openPlannedEndIntakeInMode(User $physician, string $mode): PhysicianAvailabilitySession
{
    if ($mode === 'scheduled') {
        mondayWindow($physician, '08:00:00', '12:00:00');
        test()->travelTo(plannedEndAt('09:00:00'));
    } else {
        test()->travelTo(plannedEndAt('19:00:00'));
    }

    return openPlannedEndIntake($physician);
}

function runIntakeJanitor(): void
{
    test()->artisan('consultations:expire-intake-sessions')->assertSuccessful();
}

function intakeNotificationCount(User $physician, string $type): int
{
    return Notification::query()->where('user_id', $physician->user_id)->where('type', $type)->count();
}

function plannedEndRoute(string $name, User $physician): string
{
    return route($name, ['physician' => $physician->user_id]);
}

dataset('intake modes', [
    // mode, planned end, auto-close reason
    'scheduled' => ['scheduled', '12:00:00', 'schedule_ended'],
    'overtime' => ['overtime', '21:00:00', 'overtime_limit_reached'],
]);

/*
|--------------------------------------------------------------------------
| Schema and configuration
|--------------------------------------------------------------------------
*/

it('adds nullable planned-end columns to physician_availability_sessions', function () {
    expect(Schema::hasColumns('physician_availability_sessions', [
        'planned_end_at',
        'end_reason',
        'end_warning_sent_at',
    ]))->toBeTrue();

    $columns = collect(Schema::getColumns('physician_availability_sessions'))->keyBy('name');

    expect($columns['planned_end_at']['nullable'])->toBeTrue()
        ->and($columns['end_reason']['nullable'])->toBeTrue()
        ->and($columns['end_warning_sent_at']['nullable'])->toBeTrue();
});

it('reads the grace period and overtime cap through config so they can be overridden', function () {
    config()->set('consultations.intake.schedule_end_grace_minutes', 5);
    config()->set('consultations.intake.max_overtime_minutes', 30);

    $physician = makePlannedEndPhysician();
    $this->travelTo(plannedEndAt('19:00:00'));
    $session = openPlannedEndIntake($physician);

    expect($session->planned_end_at->format('H:i:s'))->toBe('19:30:00');

    $this->travelTo(plannedEndAt('19:34:00'));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();
    expect($session->fresh()->status)->toBe('open');

    $this->travelTo(plannedEndAt('19:35:00'));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();
    expect($session->fresh()->end_reason)->toBe('overtime_limit_reached');
});

/*
|--------------------------------------------------------------------------
| Planned end recorded at open
|--------------------------------------------------------------------------
*/

it('plans a scheduled session to end at the end of its window', function () {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, 'scheduled');

    expect($session->mode)->toBe('scheduled')
        ->and($session->planned_end_at->toDateTimeString())->toBe(PLANNED_END_MONDAY.' 12:00:00');
});

it('treats back-to-back windows as one block when planning the end', function () {
    $physician = makePlannedEndPhysician();
    mondayWindow($physician, '12:00:00', '13:00:00');
    mondayWindow($physician, '13:00:00', '15:00:00');
    // Separated by a gap, so not part of the block.
    mondayWindow($physician, '16:00:00', '17:00:00');

    $this->travelTo(plannedEndAt('12:30:00'));

    expect(openPlannedEndIntake($physician)->planned_end_at->format('H:i:s'))->toBe('15:00:00');
});

it('plans an overtime session to end after the overtime cap', function () {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, 'overtime');

    expect($session->mode)->toBe('overtime')
        ->and($session->planned_end_at->toDateTimeString())->toBe(PLANNED_END_MONDAY.' 21:00:00');
});

it('uses the window end even when it is sooner than the overtime cap', function () {
    $physician = makePlannedEndPhysician();
    mondayWindow($physician, '14:00:00', '17:00:00');
    $this->travelTo(plannedEndAt('16:55:00'));

    $session = openPlannedEndIntake($physician);

    expect($session->mode)->toBe('scheduled')
        ->and($session->planned_end_at->format('H:i:s'))->toBe('17:00:00');

    // Warning is not due at 16:59, is due at 17:00.
    $this->travelTo(plannedEndAt('16:59:00'));
    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertJsonPath('intake.end_warning', null);

    $this->travelTo(plannedEndAt('17:00:00'));
    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertJsonPath('open', true)
        ->assertJsonPath('intake.end_warning.mode', 'scheduled');

    $this->travelTo(plannedEndAt('17:14:00'));
    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertJsonPath('open', true);

    $this->travelTo(plannedEndAt('17:15:00'));
    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertJsonPath('open', false)
        ->assertJsonPath('intake.state', 'auto_closed');
});

it('never changes an open session\'s planned end when the schedule is edited', function () {
    $physician = makePlannedEndPhysician();
    $window = mondayWindow($physician, '08:00:00', '12:00:00');
    $this->travelTo(plannedEndAt('09:00:00'));
    $session = openPlannedEndIntake($physician);

    $window->update(['end_time' => '17:00:00']);
    mondayWindow($physician, '12:00:00', '13:00:00');

    // Neither a heartbeat nor a second open recomputes it.
    app(PhysicianAvailabilityService::class)->touch($physician);
    openPlannedEndIntake($physician);

    expect($session->fresh()->planned_end_at->format('H:i:s'))->toBe('12:00:00');
});

it('never auto-closes or warns a session from before planned ends existed', function () {
    $physician = makePlannedEndPhysician();
    $this->travelTo(plannedEndAt('09:00:00'));

    $legacy = PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'status' => 'open',
        'mode' => 'overtime',
    ]);

    $this->travelTo(plannedEndAt('23:00:00'));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();

    $legacy = $legacy->fresh();

    expect($legacy->status)->toBe('open')
        ->and($legacy->planned_end_at)->toBeNull()
        ->and($legacy->end_reason)->toBeNull()
        ->and($legacy->end_warning_sent_at)->toBeNull()
        ->and(app(PhysicianAvailabilityService::class)->hasOpenIntake())->toBeTrue()
        ->and(Notification::count())->toBe(0);

    // Continue has nothing to confirm on a legacy row.
    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertOk()
        ->assertJsonPath('continued', false);

    expect(PhysicianAvailabilitySession::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Warning at the planned end
|--------------------------------------------------------------------------
*/

it('sends exactly one end warning, and none before the planned end', function (string $mode, string $plannedEnd) {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, $mode);
    $end = plannedEndAt($plannedEnd);

    $this->travelTo($end->subMinute());
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();
    expect(intakeNotificationCount($physician, 'intake_end_warning'))->toBe(0);

    foreach ([0, 1, 5, 14] as $minutesAfter) {
        $this->travelTo($end->addMinutes($minutesAfter));
        keepPlannedEndPresenceFresh($physician);
        runIntakeJanitor();
    }

    expect(intakeNotificationCount($physician, 'intake_end_warning'))->toBe(1)
        ->and($session->fresh()->end_warning_sent_at->toDateTimeString())->toBe($end->toDateTimeString());
})->with('intake modes');

it('words the warning for each mode, in the heartbeat and the notification alike', function () {
    // Both open at 15:00 and reach their planned end at 17:00: one at the
    // end of its 14:00-17:00 window, the other at the 2-hour overtime cap.
    $scheduled = makePlannedEndPhysician();
    mondayWindow($scheduled, '14:00:00', '17:00:00');
    $overtime = makePlannedEndPhysician();

    $this->travelTo(plannedEndAt('15:00:00'));
    openPlannedEndIntake($scheduled);
    openPlannedEndIntake($overtime);

    $this->travelTo(plannedEndAt('17:00:00'));
    keepPlannedEndPresenceFresh($scheduled);
    keepPlannedEndPresenceFresh($overtime);
    runIntakeJanitor();

    $scheduledMessage = 'Your scheduled intake ended at 5:00 PM. You are still accepting new consultation requests. Intake will close automatically at 5:15 PM.';
    $overtimeMessage = 'You have been in overtime intake for 2 hours. Intake will close automatically at 5:15 PM.';

    $this->actingAs($scheduled)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $scheduled))
        ->assertJsonPath('intake.end_warning.mode', 'scheduled')
        ->assertJsonPath('intake.end_warning.title', 'Scheduled intake has ended')
        ->assertJsonPath('intake.end_warning.message', $scheduledMessage);

    $this->actingAs($overtime)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $overtime))
        ->assertJsonPath('intake.end_warning.mode', 'overtime')
        ->assertJsonPath('intake.end_warning.title', 'Overtime limit reached')
        ->assertJsonPath('intake.end_warning.message', $overtimeMessage);

    $warning = fn (User $physician) => Notification::query()
        ->where('user_id', $physician->user_id)
        ->where('type', 'intake_end_warning')
        ->value('message');

    expect($warning($scheduled))->toBe($scheduledMessage)
        ->and($warning($overtime))->toBe($overtimeMessage);
});

it('keeps the session alive through the heartbeat without moving its planned end', function () {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, 'overtime');

    $this->travelTo(plannedEndAt('21:05:00'));

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertOk()
        ->assertJsonPath('open', true)
        ->assertJsonPath('intake.until_label', 'Overtime until 9:00 PM');

    $session = $session->fresh();

    expect($session->last_seen_at->format('H:i:s'))->toBe('21:05:00')
        ->and($session->planned_end_at->format('H:i:s'))->toBe('21:00:00')
        ->and(PhysicianAvailabilitySession::count())->toBe(1);
});

it('shows the planned end on the intake card while open', function (string $mode, string $plannedEnd) {
    $physician = makePlannedEndPhysician();
    openPlannedEndIntakeInMode($physician, $mode);

    $intake = $this->actingAs($physician)
        ->get(plannedEndRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->viewData('intake');

    $label = ($mode === 'scheduled' ? 'Scheduled' : 'Overtime').' until '.plannedEndAt($plannedEnd)->format('g:i A');

    expect($intake['until_label'])->toBe($label)
        ->and($intake['end_warning'])->toBeNull();
})->with('intake modes');

it('renders the planned-end banner on every authenticated physician page', function () {
    $physician = makePlannedEndPhysician();

    $this->actingAs($physician)
        ->get(route('physician.consultation_inbox', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertSee('physicianIntakeEndBanner', false)
        // @json in the layout, hence json_encode's escaped slashes.
        ->assertSee(json_encode(plannedEndRoute('physician.consultation_intake.continue', $physician)), false);
});

it('renders no planned-end banner for non-physicians', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('physicianIntakeEndBanner', false);
});

/*
|--------------------------------------------------------------------------
| Continue (overtime)
|--------------------------------------------------------------------------
*/

it('treats Continue before the planned end as a no-op', function () {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, 'scheduled');

    $this->travelTo(plannedEndAt('11:59:00'));

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('continued', false)
        ->assertJsonPath('message', 'Your intake is open. You\'ll be asked to confirm at 12:00 PM.');

    $session = $session->fresh();

    expect(PhysicianAvailabilitySession::count())->toBe(1)
        ->and($session->status)->toBe('open')
        ->and($session->mode)->toBe('scheduled')
        ->and($session->planned_end_at->format('H:i:s'))->toBe('12:00:00');
});

it('continues as a fresh overtime session once the planned end has passed', function (string $mode, string $plannedEnd) {
    $physician = makePlannedEndPhysician();
    $original = openPlannedEndIntakeInMode($physician, $mode);

    $continueAt = plannedEndAt($plannedEnd)->addMinutes(5);
    $this->travelTo($continueAt);

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertOk()
        ->assertJsonPath('continued', true)
        ->assertJsonPath('intake.state', 'open')
        ->assertJsonPath('intake.mode', 'overtime')
        ->assertJsonPath('intake.end_warning', null);

    $original = $original->fresh();
    $open = PhysicianAvailabilitySession::where('status', 'open')->get();

    expect($original->status)->toBe('closed')
        ->and($original->end_reason)->toBe('continued_as_overtime')
        ->and($original->mode)->toBe($mode)
        ->and($original->planned_end_at->format('H:i:s'))->toBe($plannedEnd)
        ->and($open)->toHaveCount(1)
        ->and($open->first()->mode)->toBe('overtime')
        ->and($open->first()->started_at->toDateTimeString())->toBe($continueAt->toDateTimeString())
        ->and($open->first()->planned_end_at->toDateTimeString())->toBe($continueAt->addMinutes(120)->toDateTimeString());
})->with('intake modes');

it('is idempotent under a double submit', function () {
    $physician = makePlannedEndPhysician();
    openPlannedEndIntakeInMode($physician, 'scheduled');
    $this->travelTo(plannedEndAt('12:05:00'));

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertJsonPath('continued', true);

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertOk()
        ->assertJsonPath('continued', false);

    expect(PhysicianAvailabilitySession::count())->toBe(2)
        ->and(PhysicianAvailabilitySession::where('status', 'open')->count())->toBe(1)
        ->and(PhysicianAvailabilitySession::where('end_reason', 'continued_as_overtime')->count())->toBe(1);
});

it('refuses Continue with 422 when nothing is open', function () {
    $physician = makePlannedEndPhysician();

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

it('refuses Continue once the grace period has passed', function () {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, 'scheduled');
    $this->travelTo(plannedEndAt('12:15:00'));

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertStatus(422)
        ->assertJsonPath('intake.state', 'auto_closed');

    expect($session->fresh()->end_reason)->toBe('schedule_ended')
        ->and(PhysicianAvailabilitySession::where('status', 'open')->count())->toBe(0);
});

it('refuses one physician continuing another physician\'s intake', function () {
    $owner = makePlannedEndPhysician();
    $other = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($owner, 'scheduled');
    $this->travelTo(plannedEndAt('12:05:00'));

    $this->actingAs($other)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $owner))
        ->assertForbidden();

    expect($session->fresh()->status)->toBe('open')
        ->and(PhysicianAvailabilitySession::count())->toBe(1);
});

it('refuses a non-physician continuing physician intake', function (string $role) {
    $physician = makePlannedEndPhysician();
    $user = User::factory()->create(['role' => $role, 'user_type' => $role === 'patient' ? 'student' : 'staff']);

    $this->actingAs($user)
        ->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertForbidden();
})->with(['patient', 'nurse', 'admin']);

it('refuses an unauthenticated Continue', function () {
    $physician = makePlannedEndPhysician();

    $this->postJson(plannedEndRoute('physician.consultation_intake.continue', $physician))
        ->assertUnauthorized();
});

it('requires a CSRF token for Continue', function () {
    $physician = makePlannedEndPhysician();
    $url = plannedEndRoute('physician.consultation_intake.continue', $physician);

    $route = Route::getRoutes()->getByName('physician.consultation_intake.continue');
    expect($route->gatherMiddleware())->toContain('web');

    // The framework skips CSRF under unit tests; this instance does not, so
    // it proves the path is not in the validateCsrfTokens() except list.
    $middleware = new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };

    $tokenlessPost = function (string $url) {
        $request = Request::create($url, 'POST');
        $request->setLaravelSession(app('session.store'));

        return $request;
    };

    expect(fn () => $middleware->handle($tokenlessPost($url), fn () => response('ok')))
        ->toThrow(TokenMismatchException::class);

    // Control: the one CSRF-exempt path passes the same middleware, so the
    // exception above is really about the except list.
    expect($middleware->handle($tokenlessPost(url('/presence/heartbeat')), fn () => response('ok'))->getContent())
        ->toBe('ok');
});

/*
|--------------------------------------------------------------------------
| Auto-close after the grace period
|--------------------------------------------------------------------------
*/

it('closes a session automatically at planned end plus grace, not a minute before', function (string $mode, string $plannedEnd, string $reason) {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, $mode);
    $end = plannedEndAt($plannedEnd);

    $this->travelTo($end->addMinutes(14));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();

    expect($session->fresh()->status)->toBe('open');

    $this->travelTo($end->addMinutes(15));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();

    $session = $session->fresh();

    expect($session->status)->toBe('closed')
        ->and($session->end_reason)->toBe($reason)
        ->and($session->ended_at->toDateTimeString())->toBe($end->addMinutes(15)->toDateTimeString());
})->with('intake modes');

it('stops counting a session past planned end plus grace even if the job never runs', function () {
    $physician = makePlannedEndPhysician();
    openPlannedEndIntakeInMode($physician, 'scheduled');
    $service = app(PhysicianAvailabilityService::class);

    $this->travelTo(plannedEndAt('12:14:00'));
    keepPlannedEndPresenceFresh($physician);
    expect($service->hasOpenIntake())->toBeTrue();

    $this->travelTo(plannedEndAt('12:15:00'));
    keepPlannedEndPresenceFresh($physician);

    expect($service->hasOpenIntake())->toBeFalse()
        ->and($service->currentSessionFor($physician))->toBeNull();

    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), [
            'concern_category' => 'General',
            'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 2]]),
            'online_reason' => 'Arrived after hours',
        ])
        ->assertStatus(503);

    expect(Consultation::count())->toBe(0);
});

it('auto-closes only intake sessions, never consultations, slots or presence', function () {
    $physician = makePlannedEndPhysician();
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $this->travelTo(plannedEndAt('19:00:00'));

    $consultationRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Survives intake auto-close',
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
        'slot_date' => plannedEndAt('00:00:00')->addDay()->toDateString(),
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'booked',
    ]);

    $session = openPlannedEndIntake($physician);

    $this->travelTo(plannedEndAt('21:15:00'));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();

    expect($session->fresh()->end_reason)->toBe('overtime_limit_reached')
        ->and($consultationRequest->fresh()->request_status)->toBe('active')
        ->and($consultationSession->fresh()->consultation_status)->toBe('active')
        ->and($consultationSession->fresh()->completed_at)->toBeNull()
        ->and($pending->fresh()->request_status)->toBe('pending')
        ->and($slot->fresh()->status)->toBe('booked')
        ->and($physician->fresh()->online_status)->toBe('online')
        ->and($physician->fresh()->last_seen_at->toDateTimeString())->toBe(plannedEndAt('21:15:00')->toDateTimeString());
});

it('sends exactly one auto-closed notification per session', function () {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, 'scheduled');

    $this->travelTo(plannedEndAt('12:15:00'));
    keepPlannedEndPresenceFresh($physician);

    // The heartbeat gets there first, then the job runs repeatedly.
    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertJsonPath('open', false);

    runIntakeJanitor();
    $this->travelTo(plannedEndAt('12:16:00'));
    runIntakeJanitor();

    expect($session->fresh()->end_reason)->toBe('schedule_ended')
        ->and(intakeNotificationCount($physician, 'intake_auto_closed'))->toBe(1)
        ->and(Notification::where('type', 'intake_auto_closed')->value('message'))
        ->toBe('Intake closed automatically at 12:15 PM because your scheduled hours ended.');
});

it('still sends the auto-closed notification when no warning was ever sent', function () {
    $physician = makePlannedEndPhysician();
    $session = openPlannedEndIntakeInMode($physician, 'overtime');

    // Scheduler down from open until well past the grace period.
    $this->travelTo(plannedEndAt('21:40:00'));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();
    runIntakeJanitor();

    expect($session->fresh()->end_reason)->toBe('overtime_limit_reached')
        ->and($session->fresh()->end_warning_sent_at)->toBeNull()
        ->and(intakeNotificationCount($physician, 'intake_end_warning'))->toBe(0)
        ->and(intakeNotificationCount($physician, 'intake_auto_closed'))->toBe(1)
        ->and(Notification::where('type', 'intake_auto_closed')->value('message'))
        ->toBe('Intake closed automatically at 9:15 PM because the 2-hour overtime limit was reached.');
});

it('reports the auto-close through the heartbeat so the banner can show it', function (string $mode, string $plannedEnd) {
    $physician = makePlannedEndPhysician();
    openPlannedEndIntakeInMode($physician, $mode);

    // The job closes it first; the next heartbeat finds nothing open.
    $this->travelTo(plannedEndAt($plannedEnd)->addMinutes(15));
    keepPlannedEndPresenceFresh($physician);
    runIntakeJanitor();

    $expected = $mode === 'scheduled'
        ? 'Intake closed automatically at 12:15 PM because your scheduled hours ended.'
        : 'Intake closed automatically at 9:15 PM because the 2-hour overtime limit was reached.';

    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.heartbeat', $physician))
        ->assertOk()
        ->assertJsonPath('open', false)
        ->assertJsonPath('intake.state', 'auto_closed')
        ->assertJsonPath('intake.status_label', 'Intake Closed Automatically')
        ->assertJsonPath('intake.message', $expected);
})->with('intake modes');

it('explains an auto-close on the intake card, distinct from an expired session', function () {
    $physician = makePlannedEndPhysician();
    openPlannedEndIntakeInMode($physician, 'scheduled');

    // Before the job has recorded anything: already auto_closed on read.
    $this->travelTo(plannedEndAt('12:20:00'));
    $intake = app(PhysicianAvailabilityService::class)->serializeIntakeState($physician, null);
    expect($intake['state'])->toBe('auto_closed');

    runIntakeJanitor();

    $this->actingAs($physician)
        ->get(plannedEndRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->assertViewHas('intake', fn (array $intake) => $intake['state'] === 'auto_closed'
            && $intake['message'] === 'Intake closed automatically at 12:15 PM because your scheduled hours ended.')
        ->assertSee('Intake closed automatically at 12:15 PM because your scheduled hours ended.');

    // A heartbeat that went stale is still reported as 'expired'.
    $stale = makePlannedEndPhysician();
    PhysicianAvailabilitySession::create([
        'physician_id' => $stale->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'status' => 'expired',
        'end_reason' => 'stale_expiry',
        'ended_at' => now(),
        'mode' => 'overtime',
    ]);

    expect(app(PhysicianAvailabilityService::class)->serializeIntakeState($stale, null)['state'])->toBe('expired');
});

it('opens a fresh session when intake is reopened after an auto-close', function () {
    $physician = makePlannedEndPhysician();
    $first = openPlannedEndIntakeInMode($physician, 'overtime');

    $this->travelTo(plannedEndAt('21:30:00'));

    // No job run: open() records the auto-close itself before creating anew.
    $this->actingAs($physician)
        ->postJson(plannedEndRoute('physician.consultation_intake.open', $physician))
        ->assertOk()
        ->assertJsonPath('intake.state', 'open');

    expect($first->fresh()->end_reason)->toBe('overtime_limit_reached')
        ->and(PhysicianAvailabilitySession::where('status', 'open')->count())->toBe(1)
        ->and(PhysicianAvailabilitySession::where('status', 'open')->first()->planned_end_at->format('H:i:s'))->toBe('23:30:00')
        ->and(intakeNotificationCount($physician, 'intake_auto_closed'))->toBe(1);
});
