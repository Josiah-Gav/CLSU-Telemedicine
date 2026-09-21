<?php

namespace App\Contracts;

/**
 * What this application needs from the future Comprehensive Health
 * Information System (CHIS): identity/eligibility confirmation and limited
 * clinical context (allergies, past injuries/surgeries, etc.) for a patient,
 * looked up by their shared identifier (clsu_id).
 *
 * Bound to App\Services\Chis\FakeChisClient in AppServiceProvider until CHIS
 * exposes a real API — at that point a RealChisClient implementing this same
 * contract replaces the binding, and nothing else in the application changes.
 *
 * Deliberately read-only and narrow: this is identity + clinical *context*
 * for display during an active consultation, not an official medical
 * record. Nothing behind this contract may be persisted into this
 * application's own tables — see the "No Health Records Management"
 * limitation, which this contract exists to keep true.
 */
interface ChisClient
{
    /**
     * Null when CHIS has no record of this identifier.
     *
     * @return array{clsu_id: string, full_name: string, department: ?string, eligibility_status: string}|null
     */
    public function getIdentity(string $clsuId): ?array;

    /**
     * Null when CHIS has no record of this identifier.
     *
     * @return array{
     *     clsu_id: string,
     *     blood_type: ?string,
     *     height_cm: ?int,
     *     weight_kg: ?int,
     *     emergency_contact: array{name: ?string, relationship: ?string, contact_number: ?string},
     *     known_allergies: array<int, string>,
     *     chronic_conditions: array<int, string>,
     *     current_medications: array<int, string>,
     *     past_injuries_surgeries: array<int, string>,
     *     immunization_history: array<int, string>,
     *     family_medical_history: ?string,
     * }|null
     */
    public function getMedicalProfile(string $clsuId): ?array;
}
