<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;

/*
| DashboardController::getPatientMostRecentCompletedConsultationSessionId()
| hands the patient dashboard a session id (or null) which the Alpine
| component patientDashboard() uses to show/hide the TAM evaluation card.
| Visibility itself is a client-side x-show ("tamEvaluationSessionId &&
| !tamEvaluationHandled"), so — following this repo's existing no-browser-
| runner precedent (see ConsultationMessagingUiTest.php) — these assert on
| the server-rendered session id and markup rather than runtime visibility.
*/

function completedConsultationForPatient(User $patient, User $physician): ConsultationSession
{
    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    return ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'Assessment notes',
        'plan' => 'Plan notes',
        'recommendations' => 'Recommendations',
        'assigned_at' => now(),
        'started_at' => now(),
        'completed_at' => now(),
    ]);
}

it('shows the patient a TAM evaluation card for a completed consultation', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);
    $session = completedConsultationForPatient($patient, $physician);

    $html = $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Help Us Evaluate the Telemedicine System')
        ->toContain('Answer Evaluation')
        ->toContain('href="https://forms.gle/UFHjvbFmUnXHdeMCA"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('window.tamEvaluationSessionId = '.$session->id);

    // No patient/consultation identifiers appended to the form URL.
    expect($html)->not->toContain('forms.gle/UFHjvbFmUnXHdeMCA?');
});

it('does not show the TAM evaluation card for a patient with no consultations', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $html = $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    // The card's own markup is always present in the DOM for a patient
    // (gated client-side by x-show, matching messaging.blade.php's TAM modal
    // precedent), so the server-rendered assertion is on the id the x-show
    // guard reads, not on the always-present text.
    expect($html)->toContain('window.tamEvaluationSessionId = null');
});

it('does not show the TAM evaluation card while the patient only has an active consultation', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $html = $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('window.tamEvaluationSessionId = null');
});

it('does not give the physician dashboard a patient TAM evaluation card', function () {
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    $html = $this->actingAs($physician)
        ->get(route('physician.dashboard', ['physician' => $physician->user_id]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Help Us Evaluate the Telemedicine System');
});

it('does not give the nurse dashboard a patient TAM evaluation card', function () {
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    $html = $this->actingAs($nurse)
        ->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Help Us Evaluate the Telemedicine System');
});

it('does not give the admin dashboard a patient TAM evaluation card', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $html = $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Help Us Evaluate the Telemedicine System');
});

it('shares the evaluation-handled localStorage key format with the messaging page rather than a global flag', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);
    completedConsultationForPatient($patient, $physician);

    $html = $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain("localStorage.getItem('tam_evaluation_handled_' + this.tamEvaluationSessionId)")
        ->toContain("localStorage.setItem('tam_evaluation_handled_' + this.tamEvaluationSessionId, '1')")
        ->not->toContain('tam_evaluation_completed');
});
