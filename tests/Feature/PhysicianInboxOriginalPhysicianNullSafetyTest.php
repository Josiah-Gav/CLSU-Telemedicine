<?php

/**
 * physician/consultation_inbox.blade.php's takeover-notice paragraph guards
 * its x-show with optional chaining (`selectedConsultation?.was_taken_over`)
 * but its x-text on the same element read `selectedConsultation.` without
 * the `?.` — Alpine evaluates every directive on an element as its own
 * reactive effect regardless of that element's x-show state, so this threw
 * `TypeError: Cannot read properties of null (reading
 * 'original_physician_name')` on every load of the page, before the modal
 * was ever opened (selectedConsultation starts null).
 *
 * Pest has no JS engine, so this can only assert against the page's
 * source — see AdminInFlightMetricTest.php for the existing precedent for
 * this style of test. The absence of the console error was confirmed live
 * via Playwright MCP in the same session that found the bug.
 */
it('never accesses original_physician_name without optional chaining', function () {
    $source = file_get_contents(resource_path('views/physician/consultation_inbox.blade.php'));

    expect($source)->not->toContain('selectedConsultation.original_physician_name');
    expect($source)->toContain('selectedConsultation?.original_physician_name');
});
