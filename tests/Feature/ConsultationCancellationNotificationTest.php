<?php

use App\Models\Consultation;
use App\Models\User;

it('notifies the assigned nurse with a dedicated type when a patient cancels', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => $nurse->user_id,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($patient)
        ->postJson(route('consultations.cancel', $consultation))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('consultation_requests', [
        'request_id' => $consultation->request_id,
        'request_status' => 'cancelled',
    ]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $nurse->user_id,
        'type' => 'consultation_cancelled',
    ]);
});

it('does not notify anyone when a cancelled request has no assigned nurse', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($patient)
        ->postJson(route('consultations.cancel', $consultation))
        ->assertOk();

    $this->assertDatabaseMissing('notifications', [
        'type' => 'consultation_cancelled',
    ]);
});
