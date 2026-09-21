<?php

namespace App\Services\Chis;

use App\Contracts\ChisClient;
use App\Models\ChisMockPatientRecord;
use App\Models\User;

/**
 * Stand-in for CHIS while it does not exist yet. Reads from
 * chis_mock_patient_records (seeded fixture data, not real patient
 * history) instead of making an HTTP call, so the rest of the application
 * — the API endpoints in Api\ChisIntegrationController and the CHIS tab in
 * consultations.messaging — can be built and demoed against a real
 * ChisClient contract today. Swapping this for a RealChisClient that calls
 * an actual CHIS endpoint later requires changing only the binding in
 * AppServiceProvider.
 */
class FakeChisClient implements ChisClient
{
    public function getIdentity(string $clsuId): ?array
    {
        $user = User::query()->where('clsu_id', $clsuId)->first();

        if (! $user) {
            return null;
        }

        return [
            'clsu_id' => $clsuId,
            'full_name' => trim("{$user->first_name} {$user->last_name}"),
            'department' => $user->department,
            'eligibility_status' => 'active',
        ];
    }

    public function getMedicalProfile(string $clsuId): ?array
    {
        $record = ChisMockPatientRecord::query()->where('clsu_id', $clsuId)->first();

        if (! $record) {
            return null;
        }

        return [
            'clsu_id' => $record->clsu_id,
            'blood_type' => $record->blood_type,
            'height_cm' => $record->height_cm,
            'weight_kg' => $record->weight_kg,
            'emergency_contact' => [
                'name' => $record->emergency_contact_name,
                'relationship' => $record->emergency_contact_relationship,
                'contact_number' => $record->emergency_contact_number,
            ],
            'known_allergies' => $record->known_allergies ?? [],
            'chronic_conditions' => $record->chronic_conditions ?? [],
            'current_medications' => $record->current_medications ?? [],
            'past_injuries_surgeries' => $record->past_injuries_surgeries ?? [],
            'immunization_history' => $record->immunization_history ?? [],
            'family_medical_history' => $record->family_medical_history,
        ];
    }
}
