<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * The dashboard's Active Consultation card carried a hard-coded
 * "Active Consultation" eyebrow regardless of the card's actual status.
 * DashboardController::getPatientActiveConsultation() only ever surfaces
 * pending/reviewed/assigned/scheduled/active requests (never completed —
 * that query's own whereIn excludes it), so in practice this mislabeled a
 * pending, reviewed, assigned, or scheduled request as active, not a
 * completed one. The eyebrow is now bound to the same status_label already
 * embedded in window.patientConsultation for the rest of the card.
 */
it('embeds the real status label for a scheduled consultation, not a hard-coded Active eyebrow', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'scheduled',
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($patient)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('"status_label":"Scheduled"', false);

    $source = file_get_contents(resource_path('views/patient/dashboard.blade.php'));
    expect($source)->not->toContain('<p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Active Consultation</p>');
});
