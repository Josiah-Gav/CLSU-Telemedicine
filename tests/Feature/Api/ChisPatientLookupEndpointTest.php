<?php

use App\Models\ChisMockPatientRecord;
use App\Models\User;

it('rejects a patient identity request with no token', function () {
    $this->getJson('/api/v1/patients/2021012345/identity')
        ->assertStatus(401);
});

it('returns identity for a known clsu_id', function () {
    $token = issueChisToken();
    User::factory()->create([
        'role' => 'patient',
        'clsu_id' => '2021012345',
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'department' => 'College of Engineering',
    ]);

    $this->withToken($token)
        ->getJson('/api/v1/patients/2021012345/identity')
        ->assertOk()
        ->assertJson([
            'clsu_id' => '2021012345',
            'full_name' => 'Maya Reyes',
            'department' => 'College of Engineering',
            'eligibility_status' => 'active',
        ]);
});

it('returns 404 identity for an unknown clsu_id', function () {
    $token = issueChisToken();

    $this->withToken($token)
        ->getJson('/api/v1/patients/0000000000/identity')
        ->assertStatus(404);
});

it('returns the medical profile for a known clsu_id, including allergies and past surgeries', function () {
    $token = issueChisToken();
    ChisMockPatientRecord::create([
        'clsu_id' => '2021012345',
        'blood_type' => 'O+',
        'known_allergies' => ['Penicillin', 'Shellfish'],
        'past_injuries_surgeries' => ['Appendectomy (2019)'],
    ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/2021012345/medical-profile')
        ->assertOk();

    $response->assertJson([
        'clsu_id' => '2021012345',
        'blood_type' => 'O+',
        'known_allergies' => ['Penicillin', 'Shellfish'],
        'past_injuries_surgeries' => ['Appendectomy (2019)'],
    ]);
});

it('returns an empty-but-found medical profile when no clinical fields are on file', function () {
    $token = issueChisToken();
    ChisMockPatientRecord::create(['clsu_id' => '2022067890']);

    $this->withToken($token)
        ->getJson('/api/v1/patients/2022067890/medical-profile')
        ->assertOk()
        ->assertJson([
            'clsu_id' => '2022067890',
            'known_allergies' => [],
            'past_injuries_surgeries' => [],
        ]);
});

it('returns 404 medical profile for an unknown clsu_id', function () {
    $token = issueChisToken();

    $this->withToken($token)
        ->getJson('/api/v1/patients/0000000000/medical-profile')
        ->assertStatus(404);
});

it('rejects a token that lacks the chis:read-patients ability', function () {
    $token = issueChisToken(abilities: ['chis:read-encounters']);

    $this->withToken($token)
        ->getJson('/api/v1/patients/2021012345/identity')
        ->assertStatus(403);
});
