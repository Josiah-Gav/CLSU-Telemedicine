<?php

use App\Models\Consultation;
use App\Models\User;

it('allows a patient to view only their own consultation details', function () {
    $owner = User::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Example',
        'email' => 'alice@example.com',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $otherPatient = User::factory()->create([
        'first_name' => 'Bob',
        'last_name' => 'Example',
        'email' => 'bob@example.com',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $consultation = Consultation::create([
        'patient_id' => $owner->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'fever',
        'symptoms_desc' => [
            ['name' => 'Headache', 'severity' => 'mild'],
        ],
        'file_attachments' => null,
        'request_status' => 'pending',
    ]);

    $this->actingAs($owner)
        ->get(route('consultations.show', $consultation))
        ->assertOk();

    $this->actingAs($otherPatient)
        ->get(route('consultations.show', $consultation))
        ->assertForbidden();
});

it('shows a follow-up badge and a link back to the original consultation', function () {
    $patient = User::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Example',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $originalRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'fever',
        'symptoms_desc' => [['name' => 'Fever', 'severity' => 'mild']],
        'file_attachments' => null,
        'request_status' => 'completed',
    ]);

    $originalSession = \App\Models\ConsultationSession::create([
        'request_id' => $originalRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'Initial assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subDays(2),
        'started_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),
    ]);

    $followUpConsultation = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => $originalSession->id,
        'concern_category' => 'fever',
        'symptoms_desc' => [['name' => 'Fever', 'severity' => 'mild']],
        'file_attachments' => null,
        'request_status' => 'scheduled',
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.show', $followUpConsultation))
        ->assertOk()
        ->assertSee('Follow-up Consultation')
        ->assertSee('View original consultation')
        ->assertSee(route('consultations.show', $originalRequest), false);
});

it('shows the assigned physician\'s name and specialization on the consultation details page', function () {
    $patient = User::factory()->create([
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultation = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'fever',
        'symptoms_desc' => [['name' => 'Fever', 'severity' => 'mild']],
        'file_attachments' => null,
        'request_status' => 'assigned',
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.show', $consultation))
        ->assertOk()
        ->assertSee('Assigned Physician')
        ->assertSee('Dr. Noah Flores')
        ->assertSee('General Medicine');
});

it('omits the assigned physician section when no physician has been assigned yet', function () {
    $patient = User::factory()->create([
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $consultation = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'fever',
        'symptoms_desc' => [['name' => 'Fever', 'severity' => 'mild']],
        'file_attachments' => null,
        'request_status' => 'pending',
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.show', $consultation))
        ->assertOk()
        ->assertDontSee('Assigned Physician');
});
