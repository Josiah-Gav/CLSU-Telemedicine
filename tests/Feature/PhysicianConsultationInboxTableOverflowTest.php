<?php

/**
 * The physician consultation inbox's desktop table (7 columns, each
 * px-6 and mostly whitespace-nowrap) overflowed its container at both
 * 1280px (by 202px, measured) and 1440px (by 42px, measured) — enough to
 * push the Review button in the Actions column past the right edge of the
 * viewport at 1280px. Column padding was trimmed and the two heaviest
 * nowrap columns (Scheduled Slot, Submitted At) now wrap instead of
 * forcing their content onto one line; Actions is additionally pinned
 * with `sticky right-0` as a backstop so it stays reachable even if a
 * narrower window still needs the horizontal scroll the table already had.
 *
 * Pest cannot measure a rendered layout box model, so this can only assert
 * that the intended CSS is present — see AdminInFlightMetricTest.php for
 * the existing precedent for this style of test. The fix was verified live
 * at 1280/1440/390 via Playwright MCP in the same session that measured
 * the original overflow; no @playwright/test suite exists in this repo and
 * none was added for it.
 */
it('pins the Actions column to the right edge and trims column padding on the desktop table', function () {
    $source = file_get_contents(resource_path('views/physician/consultation_inbox.blade.php'));

    expect($source)
        ->toContain('sticky right-0 z-10 whitespace-nowrap border-l border-gray-200 bg-gray-50')
        ->toContain('sticky right-0 z-10 whitespace-nowrap border-l border-gray-200 bg-white')
        ->not->toContain('px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500')
        ->not->toContain('whitespace-nowrap px-6 py-4');
});
