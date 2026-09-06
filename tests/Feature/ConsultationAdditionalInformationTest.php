<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * Coverage for the additional_information column added to
 * consultation_requests (Phase 3 UI overhaul of the nurse consultation
 * details modal). The patient's newconsultation.blade.php form has always
 * submitted this value under the "additional_notes" field name — nothing
 * validated or stored it until ConsultationController::store() was updated
 * alongside this migration.
 */
function aiPatient(array $overrides = []): User
{
    // Supplies the consultation-intake precondition store() now requires;
    // assertions in this suite are unchanged. See Pest.php.
    makeConsultationIntakeAvailable();

    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function aiNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function aiStorePayload(array $overrides = []): array
{
    return array_merge([
        'concern_category' => 'General',
        'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 2]]),
        'online_reason' => 'Need consultation',
    ], $overrides);
}

it('persists additional_notes into the additional_information column', function () {
    $patient = aiPatient();

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), aiStorePayload([
            'additional_notes' => 'I also have a mild fever since yesterday.',
        ]))
        ->assertStatus(201);

    $this->assertDatabaseHas('consultation_requests', [
        'patient_id' => $patient->user_id,
        'additional_information' => 'I also have a mild fever since yesterday.',
    ]);
});

it('stores a null additional_information when the field is omitted, since it is optional', function () {
    $patient = aiPatient();

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), aiStorePayload())
        ->assertStatus(201);

    $consultation = Consultation::where('patient_id', $patient->user_id)->firstOrFail();

    expect($consultation->additional_information)->toBeNull();
});

it('rejects an additional_notes value beyond the 1000 character limit', function () {
    $patient = aiPatient();

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), aiStorePayload([
            'additional_notes' => str_repeat('a', 1001),
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['additional_notes']);
});

it('shows Additional Information, not Concern Category, on the nurse consultation inbox modal', function () {
    $nurse = aiNurse();
    Consultation::forceCreate([
        'patient_id' => aiPatient()->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'additional_information' => 'Patient also reports mild dizziness.',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($nurse)
        ->get(route('nurse.consultation_inbox', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee('Additional Information');
    $response->assertSee('Patient also reports mild dizziness.');
    $response->assertDontSee('Concern Category');
});
