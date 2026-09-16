<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;

/**
 * The header notification dropdown and the full notifications page both
 * navigate through window.notificationNav.resolveUrl() (resources/views/
 * layouts/navigation.blade.php), which builds a destination URL client-side
 * from the notification's own `data` (consultation_id / session_id) and the
 * viewer's role. That resolver has no way to check ownership itself — it is
 * plain string concatenation — so the safety property that actually matters
 * is that every destination it can produce re-authorizes server-side
 * regardless of which notification's data pointed at it. These tests pin
 * that property on the two id-driven destinations a patient or physician can
 * be sent to: the consultation details page (ConsultationPolicy::view) and
 * the consultation messaging page (ConsultationSessionPolicy::viewMessaging).
 */
it('refuses a patient the consultation details page for a consultation that is not theirs', function () {
    $owner = User::factory()->create(['role' => 'patient']);
    $intruder = User::factory()->create(['role' => 'patient']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $owner->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($intruder)
        ->get(route('consultations.show', $consultation))
        ->assertForbidden();
});

it('refuses a nurse or physician the consultation details page even though they may be assigned', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($nurse)->get(route('consultations.show', $consultation))->assertForbidden();
    $this->actingAs($physician)->get(route('consultations.show', $consultation))->assertForbidden();
});

it('refuses an unrelated physician the messaging page for a consultation session that is not theirs', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $assignedPhysician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);
    $otherPhysician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $assignedPhysician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $assignedPhysician->user_id,
        'slot_id' => null,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $this->actingAs($otherPhysician)
        ->get(route('consultations.messaging.show', $session))
        ->assertForbidden();
});

it('refuses a nurse the messaging page even for a consultation they triaged', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $this->actingAs($nurse)
        ->get(route('consultations.messaging.show', $session))
        ->assertForbidden();
});
