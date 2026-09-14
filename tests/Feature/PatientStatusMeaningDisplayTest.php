<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * Phase 2 (IA/UX): the dashboard card and the consultation-details page both
 * now surface StatusBadge::patientMeaning() alongside the raw status badge,
 * so a first-time patient isn't left to guess what "Reviewed" or "Scheduled"
 * actually means for them.
 */
function meaningPatient(): User
{
    return User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
}

function meaningConsultation(User $patient, string $status): Consultation
{
    return Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => $status,
        'submitted_at' => now(),
    ]);
}

it('embeds the status meaning sentence in the dashboard payload', function () {
    $patient = meaningPatient();
    meaningConsultation($patient, 'reviewed');

    $response = $this->actingAs($patient)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('A nurse has reviewed your request and is arranging a physician for you.', false);
});

it('shows the status meaning sentence on the consultation details page', function () {
    $patient = meaningPatient();
    $consultation = meaningConsultation($patient, 'scheduled');

    $response = $this->actingAs($patient)->get(route('consultations.show', $consultation));

    $response->assertOk();
    $response->assertSee('Your consultation has been scheduled', false);
});
