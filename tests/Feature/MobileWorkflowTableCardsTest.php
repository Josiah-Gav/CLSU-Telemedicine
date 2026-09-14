<?php

use App\Models\Consultation;
use App\Models\FollowUpRequest;
use App\Models\User;

/**
 * Phase 3 (mobile-first): several workflow tables had only a desktop
 * `overflow-x-auto` table with no mobile alternative — genuinely unusable
 * at 375/390px (patient names, emails, and multi-button action cells all
 * competing for the same ~340px of usable width). Each got a `sm:hidden`
 * card list paired with a `hidden ... sm:block` desktop table, reusing the
 * exact same data and action handlers. Pest cannot measure a rendered
 * layout box, so — per the project's established convention for CSS-driven
 * responsive fixes (PhysicianConsultationInboxTableOverflowTest,
 * NotificationPanelMobilePositioningTest) — this locks in that both
 * representations render the same record, rather than only one surviving
 * a future edit.
 */
it('renders both a mobile card and a desktop table for the nurse follow-up queue', function () {
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'submitted_at' => now(),
    ]);
    $session = $consultation->consultationSession()->create([
        'physician_id' => null,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Assessment pending.',
        'plan' => 'Plan pending.',
        'recommendations' => 'Recommendations pending.',
    ]);
    FollowUpRequest::create([
        'patient_id' => $patient->user_id,
        'consultation_id' => $session->id,
        'reason' => 'Still have a headache',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($nurse)->get(route('nurse.follow_up_requests', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee('space-y-3 sm:hidden', false);
    $response->assertSee('hidden overflow-hidden rounded-xl border border-gray-200 sm:block', false);
    // Both representations render the same request's reason text.
    $response->assertSeeInOrder(['Still have a headache', 'Still have a headache'], false);
});

it('renders both a mobile card and a desktop table for admin user management', function () {
    $admin = User::factory()->create(['role' => 'admin', 'user_type' => 'staff']);
    User::factory()->create(['role' => 'nurse', 'user_type' => 'staff', 'first_name' => 'Regression', 'last_name' => 'Tester']);

    $response = $this->actingAs($admin)->get(route('admin.users.index'));

    $response->assertOk();
    $response->assertSee('space-y-3 sm:hidden', false);
    $response->assertSee('hidden overflow-x-auto sm:block', false);
    $response->assertSeeInOrder(['Regression Tester', 'Regression Tester'], false);
});

it('gives the physician forwarded follow-up queue a mobile card alongside its desktop table', function () {
    $source = file_get_contents(resource_path('views/physician/follow_up_request.blade.php'));

    expect($source)->toContain('space-y-3 p-4 sm:hidden');
    expect($source)->toContain('hidden overflow-x-auto sm:block');
    // The mobile card must call the same three Alpine decision handlers as
    // the desktop row, not a separate/duplicated set.
    expect($source)->toContain('startFollowUpNow(@js($followUpJs))');
    expect($source)->toContain('approveScheduled(@js($followUpJs))');
    expect($source)->toContain('rejectRequest(@js($followUpJs))');
});

it('gives the physician scheduled-consultation queues a mobile card alongside their desktop tables', function () {
    $source = file_get_contents(resource_path('views/physician/scheduled_consultation.blade.php'));

    expect(substr_count($source, 'space-y-3 sm:hidden'))->toBe(2);
    expect(substr_count($source, 'hidden overflow-x-auto sm:block'))->toBe(2);
    // Both the Follow-up and Initial Consultations tables get a mobile card
    // (2 tables) each paired with its existing desktop row (2 more) — the
    // same two Alpine methods, called from both representations.
    expect(substr_count($source, '@click="startConsultation(consultation)"'))->toBe(4);
    expect(substr_count($source, '@click="promptReschedule(consultation)"'))->toBe(4);
});

it('gives both role consultation-history partials a mobile card alongside their desktop table', function () {
    foreach (['nurse', 'physician'] as $role) {
        $source = file_get_contents(resource_path("views/{$role}/partials/consultation_history_table.blade.php"));

        expect($source)->toContain('space-y-3 sm:hidden');
        expect($source)->toContain('hidden overflow-x-auto sm:block');
    }
});
