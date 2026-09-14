<?php

/**
 * The notification dropdown used to be `absolute right-0` with a width of
 * `calc(100vw-3rem)`, sized as if its own trigger sat flush against the
 * viewport's right edge. It never does: the header's px-4 padding and, on
 * mobile, the account-menu button rendered after it both eat into that
 * space, so the panel's left edge landed off-screen (measured at -27px at
 * a 375px viewport, with no horizontal scroll to reach it).
 *
 * Pest has no JS engine or layout box model, so this can only assert
 * against the page's source — see AdminInFlightMetricTest.php for the
 * existing precedent for this style of test. The fix was verified live at
 * 375/390/1280/1440 via Playwright MCP in the same session that found the
 * clipping; no @playwright/test suite exists in this repo and none was
 * added for it.
 */
it('anchors the notification panel to the viewport on mobile instead of its trigger', function () {
    $source = file_get_contents(resource_path('views/layouts/notificationUI.blade.php'));

    expect($source)
        ->toContain('fixed inset-x-4 top-24')
        ->toContain('sm:absolute')
        ->toContain('sm:right-0')
        ->toContain('sm:w-[calc(100vw-3rem)]');
});
