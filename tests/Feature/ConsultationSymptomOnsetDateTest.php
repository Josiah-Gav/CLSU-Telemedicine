<?php

use App\Models\User;

/**
 * Coverage for the symptom onset date/time restriction added to
 * ConsultationController::store(). The date/time picker in
 * newconsultation.blade.php only offers past-or-present values and the
 * client re-checks before submitting, but symptoms_payload is otherwise
 * unvalidated per-entry (see SymptomAnalytics' class docblock, finding
 * H-4) — a direct POST past the browser could still submit a future
 * onset, which this endpoint-level check is the only thing preventing.
 */
function onsetPatient(array $overrides = []): User
{
    // store() now refuses a new request unless consultation intake is open, so
    // every patient in this suite is created alongside that state. Assertions
    // below are unchanged; this only supplies the precondition they always
    // implicitly relied on. See makeConsultationIntakeAvailable() in Pest.php.
    makeConsultationIntakeAvailable();

    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function onsetStorePayload(array $symptoms, array $overrides = []): array
{
    return array_merge([
        'concern_category' => 'General',
        'symptoms_payload' => json_encode($symptoms),
        'online_reason' => 'Need consultation',
    ], $overrides);
}

it('rejects a symptom whose onset date is in the future', function () {
    $patient = onsetPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), onsetStorePayload([
        ['name' => 'Headache', 'severity' => 2, 'date' => now()->addDay()->toDateString(), 'time' => '09:00'],
    ]));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('cannot be in the future');
    $this->assertDatabaseMissing('consultation_requests', ['patient_id' => $patient->user_id]);
});

it('rejects a symptom dated today with a time later than now', function () {
    $patient = onsetPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), onsetStorePayload([
        ['name' => 'Headache', 'severity' => 2, 'date' => now()->toDateString(), 'time' => now()->addHour()->format('H:i')],
    ]));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('cannot be in the future');
});

it('accepts a symptom dated today with a time earlier than now', function () {
    $patient = onsetPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), onsetStorePayload([
        ['name' => 'Headache', 'severity' => 2, 'date' => now()->toDateString(), 'time' => now()->subHour()->format('H:i')],
    ]));

    $response->assertStatus(201);
});

it('accepts a symptom with a past onset date', function () {
    $patient = onsetPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), onsetStorePayload([
        ['name' => 'Headache', 'severity' => 2, 'date' => now()->subDays(2)->toDateString(), 'time' => '08:00'],
    ]));

    $response->assertStatus(201);
    $this->assertDatabaseHas('consultation_requests', ['patient_id' => $patient->user_id]);
});

it('still accepts a symptom with no onset date, since date/time stay optional', function () {
    $patient = onsetPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), onsetStorePayload([
        ['name' => 'Headache', 'severity' => 2, 'date' => '', 'time' => ''],
    ]));

    $response->assertStatus(201);
});

it('rejects an unparseable onset date with a 422 instead of a server error', function () {
    $patient = onsetPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), onsetStorePayload([
        ['name' => 'Headache', 'severity' => 2, 'date' => 'not-a-date', 'time' => ''],
    ]));

    $response->assertStatus(422);
});

it('rejects the whole request when any one of several symptoms has a future onset', function () {
    $patient = onsetPatient();

    $response = $this->actingAs($patient)->postJson(route('consultations.store'), onsetStorePayload([
        ['name' => 'Headache', 'severity' => 2, 'date' => now()->subDay()->toDateString()],
        ['name' => 'Fever', 'severity' => 3, 'date' => now()->addWeek()->toDateString()],
    ]));

    $response->assertStatus(422);
    $this->assertDatabaseMissing('consultation_requests', ['patient_id' => $patient->user_id]);
});
