<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\PhysicianAvailabilitySession;
use App\Models\User;

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
