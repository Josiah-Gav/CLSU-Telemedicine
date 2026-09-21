<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stand-in for data that would live in CHIS, not here. Deliberately not
     * linked to `users` by a foreign key: this table represents an external
     * system's records, so it is keyed only by the shared identifier
     * (clsu_id) a real CHIS integration would also key on, and it must never
     * be treated as this application's own medical-record store — the
     * ChisClient contract is the only thing allowed to read it. See
     * App\Contracts\ChisClient and App\Services\Chis\FakeChisClient.
     */
    public function up(): void
    {
        Schema::create('chis_mock_patient_records', function (Blueprint $table) {
            $table->id();
            $table->string('clsu_id')->unique();
            $table->string('blood_type')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_relationship')->nullable();
            $table->string('emergency_contact_number')->nullable();
            $table->json('known_allergies')->nullable();
            $table->json('chronic_conditions')->nullable();
            $table->json('current_medications')->nullable();
            $table->json('past_injuries_surgeries')->nullable();
            $table->json('immunization_history')->nullable();
            $table->text('family_medical_history')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chis_mock_patient_records');
    }
};
