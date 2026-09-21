<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;

it('rejects an encounter-summary request with no token', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'type' => 'initial',
        'concern_category' => 'general',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'request_status' => 'completed',
        'priority_level' => 'Normal',
    ]);

    $this->getJson("/api/v1/consultations/{$consultation->request_id}/encounter-summary")
        ->assertStatus(401);
});

it('rejects a token that lacks the chis:read-encounters ability', function () {
    $token = issueChisToken(abilities: ['chis:read-patients']);
    $patient = User::factory()->create(['role' => 'patient']);
    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'type' => 'initial',
        'concern_category' => 'general',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'request_status' => 'completed',
        'priority_level' => 'Normal',
    ]);

    $this->withToken($token)
        ->getJson("/api/v1/consultations/{$consultation->request_id}/encounter-summary")
        ->assertStatus(403);
});

it('returns the encounter summary for a completed consultation session', function () {
    $token = issueChisToken();

    $patient = User::factory()->create(['role' => 'patient', 'clsu_id' => '2021012345']);
    $physician = User::factory()->create(['role' => 'physician', 'clsu_id' => '2010099999']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'fever',
        'symptoms_desc' => [['name' => 'Fever', 'severity' => 'moderate']],
        'request_status' => 'completed',
        'priority_level' => 'Normal',
    ]);

    ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'Viral fever, no complications.',
        'plan' => 'Rest and hydration.',
        'recommendations' => 'Return if fever persists beyond 3 days.',
        'diagnosis' => 'Viral fever',
        'follow_up_required' => false,
        'assigned_at' => now()->subHours(3),
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHour(),
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/v1/consultations/{$consultation->request_id}/encounter-summary")
        ->assertOk();

    $response->assertJson([
        'encounter_reference' => $consultation->request_id,
        'patient_identifier' => '2021012345',
        'attending_physician' => '2010099999',
        'diagnosis' => 'Viral fever',
        'consultation_status' => 'completed',
        'follow_up_required' => false,
    ]);
});

it('returns 404 for a consultation with no clinical session yet', function () {
    $token = issueChisToken();
    $patient = User::factory()->create(['role' => 'patient']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'type' => 'initial',
        'concern_category' => 'general',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'request_status' => 'pending',
        'priority_level' => 'Normal',
    ]);

    $this->withToken($token)
        ->getJson("/api/v1/consultations/{$consultation->request_id}/encounter-summary")
        ->assertStatus(404);
});
