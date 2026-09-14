<?php

/**
 * Phase 6, Objective 3: the final duplicated-button-styling audit found a
 * handful of plain, unambiguous rectangular actions still hand-styled
 * outside the <x-button-*> set — some duplicated verbatim across 3+ files
 * (the Apply/Reset consultation-history filter), one a genuine three-color
 * inconsistency for the exact same action (nurse consultation-inbox
 * "Review": brand-green in one list, indigo-600 in the other two). Locks
 * in the migration + handler preservation at the source level, the same
 * convention as Phase5ButtonMigrationTest.
 */
it('migrates the shared dash.filter-bar Apply buttons', function () {
    $source = file_get_contents(resource_path('views/components/dash/filter-bar.blade.php'));

    expect(substr_count($source, '<x-button-primary'))->toBe(2)
        ->and($source)->toContain('type="submit"');
});

it('migrates the duplicated Apply/Reset consultation-history filter across all three roles', function () {
    $pages = [
        'nurse/consultation_history',
        'physician/consultation_history',
        'patient/consultation-history',
    ];

    foreach ($pages as $page) {
        $source = file_get_contents(resource_path("views/{$page}.blade.php"));

        expect($source)->toContain('<x-button-primary type="submit">Apply</x-button-primary>')
            ->and($source)->toContain('<x-button-secondary href=')
            ->and($source)->toContain('>Reset</x-button-secondary>');
    }
});

it('migrates admin create/edit user form actions, preserving their routes', function () {
    $create = file_get_contents(resource_path('views/admin/users/create.blade.php'));
    $edit = file_get_contents(resource_path('views/admin/users/edit.blade.php'));

    expect($create)->toContain('<x-button-primary type="submit">Create Staff Account</x-button-primary>')
        ->and($create)->toContain("<x-button-secondary href=\"{{ route('admin.users.index') }}\">Cancel</x-button-secondary>")
        ->and($edit)->toContain('<x-button-primary type="submit">Save Changes</x-button-primary>')
        ->and($edit)->toContain("<x-button-secondary href=\"{{ route('admin.users.index') }}\">Cancel</x-button-secondary>");
});

it('migrates the two profile forms without losing their submit type', function () {
    $password = file_get_contents(resource_path('views/profile/partials/update-password-form.blade.php'));
    $info = file_get_contents(resource_path('views/profile/partials/update-profile-information-form.blade.php'));

    expect($password)->toContain('<x-button-primary type="submit">')
        ->and($password)->toContain("__('Update Password')")
        ->and($info)->toContain('<x-button-primary type="submit">')
        ->and($info)->toContain("__('Save Changes')");
});

it('migrates the patient follow-up list actions, preserving the request-follow-up handler and data attribute', function () {
    $source = file_get_contents(resource_path('views/patient/follow_up_list.blade.php'));

    expect(substr_count($source, '<x-button-primary'))->toBe(4)
        ->and(substr_count($source, 'onclick="requestFollowUp(this)"'))->toBe(2)
        ->and(substr_count($source, 'data-form-id="follow-up-form-'))->toBe(2);
});

it('migrates the patient consultation-history New Consultation CTA', function () {
    $source = file_get_contents(resource_path('views/patient/consultation-history.blade.php'));

    expect($source)->toContain("<x-button-primary href=\"{{ route('consultations.create') }}\">New Consultation</x-button-primary>");
});

it('unifies the nurse consultation-inbox Review buttons onto one primary color and preserves the openModal handler', function () {
    $source = file_get_contents(resource_path('views/nurse/consultation_inbox.blade.php'));

    // Three sections (Pending, Assigned To Me, Assigned To Other Nurses) each
    // render a mobile card + desktop table row — six identical actions that
    // used to be split across bg-brand-green (one section) and bg-indigo-600
    // (the other two). All six now share the same component.
    expect(substr_count($source, 'openModal({{ $request->request_id }})'))->toBe(6)
        ->and($source)->not->toContain('bg-indigo-600')
        ->and($source)->not->toContain('bg-brand-green px-3 py-2 text-xs')
        ->and($source)->not->toContain('bg-brand-green px-3 py-1.5 text-xs');
});

it('migrates the nurse consultation-inbox modal footer to secondary/danger/primary, preserving the Swal-free click handlers', function () {
    $source = file_get_contents(resource_path('views/nurse/consultation_inbox.blade.php'));

    expect($source)->toContain('<x-button-secondary @click="closeModal()">')
        ->and($source)->toContain('<x-button-danger @click="rejectSelectedRequest()">')
        ->and($source)->toContain('<x-button-primary @click="approveSelectedRequest()">');
});

it('leaves the physician consultation-inbox amber Claim / disabled Start cluster and the Alpine-dynamic prescription link unmigrated', function () {
    // Claim Consultation's amber tone has no matching variant in the
    // 4-component set (not primary/secondary/danger/ghost), and Start's
    // bare `:disabled`/`:title` bindings sit on a plain <button> — moving
    // either into an <x-button-*> tag risks the same Blade/Alpine colon
    // collision the project has hit before. Left as a documented Category
    // C item rather than guessed at.
    $inbox = file_get_contents(resource_path('views/physician/consultation_inbox.blade.php'));
    $messaging = file_get_contents(resource_path('views/consultations/messaging.blade.php'));

    expect($inbox)->toContain('bg-amber-600')
        ->and($inbox)->toContain(':disabled="!selectedConsultation.can_start"')
        ->and($messaging)->toContain('<a :href="clinical.prescription.download_url"');
});
