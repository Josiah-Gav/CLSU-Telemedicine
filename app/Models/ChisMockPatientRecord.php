<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture data standing in for what CHIS would hold on a patient. Not this
 * application's medical record — the "No Health Records Management"
 * limitation still holds: this table exists only so FakeChisClient has
 * something to return, and it is read through that class alone, never
 * queried directly from a controller. Keyed by clsu_id, the identifier a
 * real CHIS integration would also share, not by a users foreign key.
 */
class ChisMockPatientRecord extends Model
{
    protected $fillable = [
        'clsu_id',
        'blood_type',
        'height_cm',
        'weight_kg',
        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_number',
        'known_allergies',
        'chronic_conditions',
        'current_medications',
        'past_injuries_surgeries',
        'immunization_history',
        'family_medical_history',
    ];

    protected $casts = [
        'known_allergies' => 'array',
        'chronic_conditions' => 'array',
        'current_medications' => 'array',
        'past_injuries_surgeries' => 'array',
        'immunization_history' => 'array',
    ];
}
