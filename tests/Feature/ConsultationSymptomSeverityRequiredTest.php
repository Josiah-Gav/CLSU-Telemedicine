<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * Coverage for the symptom severity requirement added to
 * ConsultationController::store(). The severity picker in
 * newconsultation.blade.php used to initialize every symptom at
 * severity: 3 the instant it was selected — indistinguishable from a
 * patient who deliberately chose "Moderate" — and symptoms_payload was
 * otherwise unvalidated per-entry (see SymptomAnalytics' class docblock).
 * Severity now starts unset client-side, and this endpoint-level check is
 * what actually stops an unset (or out-of-scale) severity from being
 * recorded as data, the same way ConsultationSymptomOnsetDateTest guards
 * the onset date/time fields.
 */
function severityPatient(array $overrides = []): User
{
    makeConsultationIntakeAvailable();

    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function severityStorePayload(array $symptoms, array $overrides = []): array
{
    return array_merge([
        'concern_category' => 'General',
        'symptoms_payload' => json_encode($symptoms),
        'online_reason' => 'Need consultation',
    ], $overrides);
}

it('rejects a symptom with no severity selected', function () {
    $patient = severityPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), severityStorePayload([
        ['name' => 'Headache', 'severity' => null],
    ]));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('severity');
    $this->assertDatabaseMissing('consultation_requests', ['patient_id' => $patient->user_id]);
});

it('rejects a symptom whose severity key is missing entirely', function () {
    $patient = severityPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), severityStorePayload([
        ['name' => 'Headache'],
    ]));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('severity');
});

it('rejects a severity value outside the 1-4 scale', function () {
    $patient = severityPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), severityStorePayload([
        ['name' => 'Headache', 'severity' => 5],
    ]));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('severity');
});

it('rejects the whole request when any one of several symptoms has no severity', function () {
    $patient = severityPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), severityStorePayload([
        ['name' => 'Headache', 'severity' => 2],
        ['name' => 'Fever', 'severity' => null],
    ]));

    $response->assertStatus(422);
    $this->assertDatabaseMissing('consultation_requests', ['patient_id' => $patient->user_id]);
});

it('accepts every valid severity value on the 1-4 scale', function (int $severity) {
    $patient = severityPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), severityStorePayload([
        ['name' => 'Headache', 'severity' => $severity],
    ]));

    $response->assertStatus(201);
    $consultation = Consultation::where('patient_id', $patient->user_id)->firstOrFail();
    expect($consultation->symptoms_desc[0]['severity'])->toBe($severity);
})->with([1, 2, 3, 4]);
