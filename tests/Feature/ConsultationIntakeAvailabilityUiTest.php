<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\PhysicianAvailabilitySession;
use App\Models\PhysicianSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Phase 7: the patient-facing availability indicator on the new-consultation
| page, and the graceful handling of a submission-time 503.
|
| The indicator is purely informational — PhysicianAvailabilityService::
| isServiceAvailable() is computed once per page render and handed to the
| view as a plain boolean. These tests do not re-derive that boolean's rules
| (that is Phase 2/6's job); they assert the two view routes render it
| correctly and that nothing about an individual physician leaks into the
| page.
*/

function uiGatePatient(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'patient',
        'user_type' => 'student',
    ], $overrides));
}

function uiGatePhysician(bool $available = true): User
{
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'account_status' => 'active',
        'online_status' => $available ? 'online' : 'offline',
    ]);

    DB::table('users')->where('user_id', $physician->user_id)->update(['last_seen_at' => now()]);

    if ($available) {
        PhysicianAvailabilitySession::create([
            'physician_id' => $physician->user_id,
            'started_at' => now(),
            'last_seen_at' => now(),
            'status' => 'open',
            'mode' => 'overtime',
        ]);
    }

    return $physician->refresh();
}

/*
|--------------------------------------------------------------------------
| GET /newconsultation (DashboardController::newconsultation)
|--------------------------------------------------------------------------
*/

it('renders the newconsultation page as available when the service can accept requests', function () {
    uiGatePhysician();

    $response = $this->actingAs(uiGatePatient())
        ->get(route('newconsultation'))
        ->assertOk();

    expect($response->viewData('intakeAvailable'))->toBeTrue();
    $response->assertSee('Consultations Available');
});

it('ships the client-side guard that confines the patient to step 1 while intake is closed', function () {
    // canAdvanceToStep() is Alpine/client-side, so a PHP feature test cannot
    // drive the form itself — this only guards against the check, or the
    // wiring into it, being silently deleted: every control that can change
    // currentStep (the four sidebar step bullets, Back, Next, and the
    // type-selection card) must route through goToStep()/canAdvanceToStep()
    // rather than assigning currentStep directly, or a patient could bypass
    // the step >= 2 block entirely. intakeAvailable itself is already
    // covered by the tests above/below.
    $response = $this->actingAs(uiGatePatient())
        ->get(route('newconsultation'))
        ->assertOk();

    $response->assertSee('if (step >= 2 && !this.intakeAvailable)', false)
        ->assertSee("selectedType = 'general'; goToStep(2)", false)
        ->assertSee('if(currentStep < 5) goToStep(1)', false)
        ->assertSee('if(currentStep < 5) goToStep(2)', false)
        ->assertSee('if(currentStep < 5) goToStep(3)', false)
        ->assertSee('if(currentStep < 5) goToStep(4)', false)
        ->assertSee('goToStep(Math.max(currentStep - 1, 1))', false)
        ->assertSee('goToStep(currentStep + 1)', false);

    // Every historical direct assignment except goToStep()'s own body and
    // the post-submission success transition to step 5 must be gone —
    // those two are the only currentStep changes allowed to skip the guard.
    expect(substr_count($response->getContent(), 'currentStep = '))->toBe(2);
});

it('renders the newconsultation page as unavailable when no physician has intake open', function () {
    $response = $this->actingAs(uiGatePatient())
        ->get(route('newconsultation'))
        ->assertOk();

    expect($response->viewData('intakeAvailable'))->toBeFalse();
    $response->assertSee('Consultations Currently Unavailable');
});

it('does not leak any physician identity onto the newconsultation page', function () {
    $physician = uiGatePhysician(available: false);

    $response = $this->actingAs(uiGatePatient())
        ->get(route('newconsultation'))
        ->assertOk();

    // Not 'heartbeat': the shared layout's presence/intake-continuity script
    // (Phase 5) legitimately mentions it on every authenticated page,
    // regardless of this physician. What must never appear is this specific
    // physician's identity or their raw offline/config state.
    $response->assertDontSee($physician->first_name)
        ->assertDontSee($physician->last_name)
        ->assertDontSee('offline')
        ->assertDontSee('queue_limit');
});

/*
|--------------------------------------------------------------------------
| GET /dashboard (DashboardController::index, patient branch)
|--------------------------------------------------------------------------
*/

it('renders the patient dashboard as available when the service can accept requests', function () {
    uiGatePhysician();

    $response = $this->actingAs(uiGatePatient())
        ->get(route('dashboard'))
        ->assertOk();

    expect($response->viewData('intakeAvailable'))->toBeTrue()
        ->and($response->viewData('nextScheduledWindow'))->toBeNull();
    $response->assertSee('Consultations Available');
});

it('renders the patient dashboard as unavailable with the next scheduled window when intake is closed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 19:00:00')); // Monday, after hours

    $physician = uiGatePhysician(available: false);
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 2, // Tuesday
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    $response = $this->actingAs(uiGatePatient())
        ->get(route('dashboard'))
        ->assertOk();

    expect($response->viewData('intakeAvailable'))->toBeFalse()
        ->and($response->viewData('nextScheduledWindow'))->toBe([
            'day_name' => 'Tuesday, Sep 8',
            'time_label' => '8:00 AM - 12:00 PM',
            'starts_at_iso' => CarbonImmutable::parse('2026-09-08 08:00:00')->toIso8601String(),
        ]);
    $response->assertSee('Consultations Currently Unavailable')
        ->assertSee('Tuesday, Sep 8')
        ->assertSee('8:00 AM - 12:00 PM');
});

it('renders the patient dashboard as unavailable with no schedule hint when no physician has one', function () {
    $response = $this->actingAs(uiGatePatient())
        ->get(route('dashboard'))
        ->assertOk();

    expect($response->viewData('intakeAvailable'))->toBeFalse()
        ->and($response->viewData('nextScheduledWindow'))->toBeNull();
    $response->assertSee('Please try again later');
});

it('lists this week\'s recurring hours on the patient dashboard, deduplicated and without physician identity', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 09:00:00')); // Monday

    $physicianA = uiGatePhysician(available: false);
    $physicianB = uiGatePhysician(available: false);

    // Same window as physician A, on the same day — must collapse to one line.
    PhysicianSchedule::create([
        'physician_id' => $physicianA->user_id,
        'day_of_week' => 1, // Monday
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);
    PhysicianSchedule::create([
        'physician_id' => $physicianB->user_id,
        'day_of_week' => 1, // Monday
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);
    PhysicianSchedule::create([
        'physician_id' => $physicianB->user_id,
        'day_of_week' => 3, // Wednesday
        'start_time' => '13:00:00',
        'end_time' => '17:00:00',
    ]);
    // Inactive window must never surface.
    PhysicianSchedule::create([
        'physician_id' => $physicianB->user_id,
        'day_of_week' => 5, // Friday
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'is_active' => false,
    ]);

    $response = $this->actingAs(uiGatePatient())
        ->get(route('dashboard'))
        ->assertOk();

    $week = $response->viewData('weeklySchedule');
    expect($week)->toHaveCount(7)
        ->and($week[0])->toMatchArray(['day_name' => 'Sunday', 'date_label' => 'Sep 6', 'is_today' => false, 'windows' => []])
        ->and($week[1])->toMatchArray(['day_name' => 'Monday', 'date_label' => 'Sep 7', 'is_today' => true, 'windows' => ['8:00 AM - 12:00 PM']])
        ->and($week[3])->toMatchArray(['day_name' => 'Wednesday', 'date_label' => 'Sep 9', 'is_today' => false, 'windows' => ['1:00 PM - 5:00 PM']])
        ->and($week[5])->toMatchArray(['day_name' => 'Friday', 'date_label' => 'Sep 11', 'is_today' => false, 'windows' => []]);

    $response->assertSee("This Week's Consultation Hours")
        ->assertSee('8:00 AM - 12:00 PM')
        ->assertSee('1:00 PM - 5:00 PM')
        ->assertDontSee($physicianA->first_name)
        ->assertDontSee($physicianB->first_name);

    // The deduplicated Monday window must appear exactly once in the markup,
    // not once per physician who holds it.
    expect(substr_count($response->getContent(), '8:00 AM - 12:00 PM'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| GET /consultations/create (ConsultationController::create)
|--------------------------------------------------------------------------
*/

it('renders the consultations.create page as available when the service can accept requests', function () {
    uiGatePhysician();

    $response = $this->actingAs(uiGatePatient())
        ->get(route('consultations.create'))
        ->assertOk();

    expect($response->viewData('intakeAvailable'))->toBeTrue();
});

it('renders the consultations.create page as unavailable when intake is closed', function () {
    $response = $this->actingAs(uiGatePatient())
        ->get(route('consultations.create'))
        ->assertOk();

    expect($response->viewData('intakeAvailable'))->toBeFalse();
});

it('does not compute intake availability at all for a patient with a duplicate request', function () {
    // create()/newconsultation() redirect before rendering the form when a
    // duplicate exists — availability is never even part of that response.
    uiGatePhysician();
    $patient = uiGatePatient();

    Consultation::create([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Already waiting',
        'request_status' => 'pending',
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.create'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'You already have an active consultation request.');
});

/*
|--------------------------------------------------------------------------
| The backend gate remains authoritative regardless of page state
|--------------------------------------------------------------------------
*/

it('still refuses submission with 503 even though the page was rendered as available', function () {
    // Simulates the race the prompt describes: the page loaded while intake
    // was open, then it closed before the patient submitted.
    $physician = uiGatePhysician();
    $patient = uiGatePatient();

    $this->actingAs($patient)->get(route('newconsultation'))
        ->assertOk()
        ->assertSee('Consultations Available');

    // Intake closes between page load and submission.
    PhysicianAvailabilitySession::where('physician_id', $physician->user_id)
        ->update(['status' => 'closed', 'ended_at' => now()]);

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), [
            'concern_category' => 'General',
            'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 2]]),
            'online_reason' => 'Need a consultation',
        ])
        ->assertStatus(503)
        ->assertJson([
            'success' => false,
            'message' => 'Consultations are currently unavailable. Please try again later.',
        ]);

    expect(Consultation::count())->toBe(0);
});

it('exposes the exact 503 response the frontend recognizes by status code', function () {
    $patient = uiGatePatient();

    // No physician at all: unavailable from the first request.
    $response = $this->actingAs($patient)
        ->postJson(route('consultations.store'), [
            'concern_category' => 'General',
            'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 2]]),
            'online_reason' => 'Need a consultation',
        ]);

    $response->assertStatus(503);

    // The frontend keys off the status code alone (never message text), so
    // the response shape Phase 6 established must not have drifted.
    expect($response->json())->toBe([
        'success' => false,
        'message' => 'Consultations are currently unavailable. Please try again later.',
    ]);
});

/*
|--------------------------------------------------------------------------
| Regression: duplicate precedence, lifecycle, and follow-ups
|--------------------------------------------------------------------------
*/

it('still returns the duplicate-request response ahead of the availability gate', function () {
    // Both are true: an existing request AND no available physician. The more
    // specific, more useful answer must win, unchanged from Phase 6.
    $patient = uiGatePatient();

    Consultation::create([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Already waiting',
        'request_status' => 'pending',
    ]);

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), [
            'concern_category' => 'General',
            'symptoms_payload' => json_encode([['name' => 'Cough', 'severity' => 1]]),
            'online_reason' => 'Second attempt',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You may only have one active consultation request at a time.');
});

it('leaves an active consultation active while new intake is unavailable', function () {
    $physician = uiGatePhysician(available: false);
    $otherPatient = uiGatePatient();

    $active = Consultation::create([
        'patient_id' => $otherPatient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'In progress',
        'request_status' => 'active',
    ]);

    $session = ConsultationSession::create([
        'request_id' => $active->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $this->actingAs(uiGatePatient())
        ->postJson(route('consultations.store'), [
            'concern_category' => 'General',
            'symptoms_payload' => json_encode([['name' => 'Cough', 'severity' => 1]]),
            'online_reason' => 'New patient',
        ])
        ->assertStatus(503);

    expect($active->fresh()->request_status)->toBe('active')
        ->and($session->fresh()->consultation_status)->toBe('active');
});

it('leaves physician-created follow-ups unaffected while new intake is unavailable', function () {
    $physician = uiGatePhysician(available: false);
    $patient = uiGatePatient();

    $completedRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Original consultation',
        'request_status' => 'completed',
    ]);

    $completedSession = ConsultationSession::create([
        'request_id' => $completedRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'Done.',
        'plan' => 'Done.',
        'recommendations' => 'Done.',
        'assigned_at' => now()->subDay(),
        'started_at' => now()->subDay(),
        'completed_at' => now()->subHour(),
    ]);

    // Follow-ups never touch consultations.store or the intake gate.
    $this->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $completedSession->id]), [
            'reason' => 'Symptoms returned',
        ])
        ->assertSessionHasNoErrors();

    expect(FollowUpRequest::where('consultation_id', $completedSession->id)->count())->toBe(1);
});
