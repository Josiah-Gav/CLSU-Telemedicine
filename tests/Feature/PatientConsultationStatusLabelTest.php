<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * The consultation details page's eyebrow used to be hard-coded to "Active
 * Consultation" for every non-follow-up request regardless of its actual
 * request_status — a completed, rejected, or still-pending request all
 * displayed the same "Active Consultation" label as one that was genuinely
 * in progress, directly contradicting the status badge rendered right next
 * to it. The eyebrow is now derived from request_status.
 */
function statusLabelPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function statusLabelConsultation(User $patient, string $status): Consultation
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

it('labels a completed consultation as Completed, not Active', function () {
    $patient = statusLabelPatient();
    $consultation = statusLabelConsultation($patient, 'completed');

    $response = $this->actingAs($patient)->get(route('consultations.show', $consultation));

    $response->assertOk();
    $response->assertDontSee('Active Consultation', false);
    $response->assertSee('Completed Consultation', false);
});

it('labels an active consultation as Active', function () {
    $patient = statusLabelPatient();
    $consultation = statusLabelConsultation($patient, 'active');

    $response = $this->actingAs($patient)->get(route('consultations.show', $consultation));

    $response->assertOk();
    $response->assertSee('Active Consultation', false);
});

it('labels a rejected consultation as Rejected, not Active', function () {
    $patient = statusLabelPatient();
    $consultation = statusLabelConsultation($patient, 'rejected');

    $response = $this->actingAs($patient)->get(route('consultations.show', $consultation));

    $response->assertOk();
    $response->assertDontSee('Active Consultation', false);
    $response->assertSee('Rejected Consultation', false);
});

it('still labels a follow-up consultation by type regardless of status', function () {
    $patient = statusLabelPatient();
    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'type' => 'follow_up',
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($patient)->get(route('consultations.show', $consultation));

    $response->assertOk();
    $response->assertSee('Follow-up Consultation', false);
    $response->assertDontSee('Active Consultation', false);
});
