<?php

namespace Database\Seeders;

use App\Models\ChisMockPatientRecord;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fixture data for the simulated CHIS "receive" endpoints
 * (GET /api/v1/patients/{clsu_id}/identity and .../medical-profile), read
 * through FakeChisClient. Invoked directly with
 * `db:seed --class=ChisMockPatientRecordSeeder`, same as QaTestUsersSeeder,
 * which this depends on for the two demo patient accounts it attaches a
 * clsu_id to.
 *
 * Two records on purpose: one with allergies/history populated, one empty,
 * so a Postman demo can show both a "found, here's the context" response
 * and a "found, nothing on file" response — not just the happy path.
 */
class ChisMockPatientRecordSeeder extends Seeder
{
    public function run(): void
    {
        $this->attachClsuIdAndRecord(
            email: 'patient.test1@clsu.edu.ph',
            clsuId: '2021012345',
            record: [
                'blood_type' => 'O+',
                'height_cm' => 165,
                'weight_kg' => 58,
                'emergency_contact_name' => 'Maria Santos',
                'emergency_contact_relationship' => 'Mother',
                'emergency_contact_number' => '09171234567',
                'known_allergies' => ['Penicillin', 'Shellfish'],
                'chronic_conditions' => ['Asthma'],
                'current_medications' => ['Salbutamol inhaler (as needed)'],
                'past_injuries_surgeries' => ['Appendectomy (2019)'],
                'immunization_history' => ['COVID-19 (Pfizer, 3 doses)', 'Tetanus (2022)'],
                'family_medical_history' => 'Father: hypertension. Mother: none reported.',
            ],
        );

        $this->attachClsuIdAndRecord(
            email: 'patient.test2@clsu.edu.ph',
            clsuId: '2022067890',
            record: [
                'blood_type' => 'A+',
                'height_cm' => 172,
                'weight_kg' => 70,
                'emergency_contact_name' => 'Juan Dela Cruz',
                'emergency_contact_relationship' => 'Father',
                'emergency_contact_number' => '09189876543',
                'known_allergies' => [],
                'chronic_conditions' => [],
                'current_medications' => [],
                'past_injuries_surgeries' => [],
                'immunization_history' => ['COVID-19 (Pfizer, 2 doses)'],
                'family_medical_history' => null,
            ],
        );
    }

    private function attachClsuIdAndRecord(string $email, string $clsuId, array $record): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user && $user->clsu_id !== $clsuId) {
            $user->forceFill(['clsu_id' => $clsuId])->save();
        }

        ChisMockPatientRecord::query()->updateOrCreate(
            ['clsu_id' => $clsuId],
            $record,
        );
    }
}
