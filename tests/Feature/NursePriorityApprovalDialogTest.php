<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * The nurse's "Approve Consultation Request?" dialog used to open with
 * `inputValue: 'Normal'` pre-selected, so a nurse who clicked Approve
 * without ever touching the priority dropdown silently triaged the request
 * as Normal priority — the dialog's own inputValidator (`if (!value)`)
 * never fires because a <select> with two real options and a pre-chosen
 * value cannot be left blank. The fix replaces the pre-chosen value with a
 * real blank option, so the nurse must make an explicit choice.
 *
 * ConsultationController::approveConsultation already validates
 * `priority_level => 'required|in:High,Normal'` server-side — see the
 * second test below, which locks in that this was already correct and
 * only the client-side dialog needed the fix (see also
 * ConsultationConcurrencyTest.php, which already exercises the happy path
 * for this same endpoint).
 */
it('never pre-selects a priority level in the approve dialog', function () {
    $source = file_get_contents(resource_path('views/nurse/consultation_inbox.blade.php'));

    expect($source)
        ->not->toContain("inputValue: 'Normal'")
        ->toContain("inputValue: '',")
        ->toContain("'': 'Choose priority level");
});

it('rejects approving a consultation with no priority level', function () {
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $patient = User::factory()->create(['role' => 'patient']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'submitted_at' => now(),
    ]);

    $this->actingAs($nurse)
        ->postJson(route('consultations.approve', ['consultation' => $consultation->request_id]), [])
        ->assertStatus(422);

    expect($consultation->fresh()->request_status)->toBe('pending');
});
