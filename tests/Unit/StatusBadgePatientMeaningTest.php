<?php

use App\Support\StatusBadge;

/**
 * Phase 2 (IA/UX): a first-time patient sees a status badge like "Assigned"
 * or "Reviewed" with no explanation of what it means or what happens next —
 * flagged directly in both the original audit and the Phase 2 brief ("do not
 * expose ASSIGNED without also saying what it means"). StatusBadge already
 * centralizes every other status→presentation mapping for this exact
 * request_status vocabulary (see STATUS_MAP), so the plain-language sentence
 * lives next to it rather than being duplicated separately in each of the
 * two patient-facing templates that render it.
 */
it('gives every reachable request_status a plain-language sentence for patients', function () {
    // 'assigned' is excluded deliberately: the glossary documents it as a
    // dead enum value never written by any code path, so it isn't a status
    // a patient can ever actually see.
    $reachableStatuses = ['pending', 'reviewed', 'scheduled', 'active', 'completed', 'rejected', 'cancelled'];

    foreach ($reachableStatuses as $status) {
        expect(StatusBadge::patientMeaning($status))->toBeString()->not->toBeEmpty();
    }
});

it('returns null for a status with no known meaning rather than inventing one', function () {
    expect(StatusBadge::patientMeaning('assigned'))->toBeNull();
    expect(StatusBadge::patientMeaning(null))->toBeNull();
    expect(StatusBadge::patientMeaning('made_up_status'))->toBeNull();
});
