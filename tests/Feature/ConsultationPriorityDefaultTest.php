<?php

use App\Models\Consultation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * consultation_requests.priority_level used to default to 'Normal', which
 * made a brand-new, not-yet-reviewed request look as if a nurse had already
 * triaged it. priority_level is only ever explicitly set by
 * ConsultationController::approveConsultation() -> claimByNurse() (or
 * inherited from an already-triaged parent on follow-up creation) — never
 * at initial submission — so the column now has no default and allows
 * NULL, via change_priority_level_default_to_null_on_consultation_requests_table.
 *
 * Skipped on SQLite: that migration is a MySQL-only raw ALTER (same pattern
 * as alter_consultations_status_enum.php) and returns early on SQLite, so
 * the test database still carries the original 'Normal' default there.
 * This is the same documented gotcha ConsultationCompletionVideoTest.php's
 * skip already covers for a sibling enum migration — see CLAUDE.md.
 */
it('leaves priority_level null on a freshly submitted consultation request', function () {
    // Consultation intake must be open for store() to accept a new request.
    makeConsultationIntakeAvailable();

    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), [
            'concern_category' => 'General',
            'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 2]]),
            'online_reason' => 'Need consultation',
        ])
        ->assertStatus(201);

    $consultation = Consultation::where('patient_id', $patient->user_id)->firstOrFail();

    expect($consultation->priority_level)->toBeNull();
})->skip(
    fn () => DB::connection()->getDriverName() === 'sqlite',
    'Pre-existing: priority_level still carries the original SQLite default of \'Normal\'. '
    .'change_priority_level_default_to_null_on_consultation_requests_table returns early on SQLite, so '
    .'the column keeps its old default there. This path works on MySQL, which is the production driver.'
);
