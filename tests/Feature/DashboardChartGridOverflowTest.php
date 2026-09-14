<?php

/**
 * A <canvas> contributes its intrinsic HTML width/height attributes (which
 * Chart.js sets) to a bare `grid` container's implicit column sizing when no
 * explicit `grid-template-columns` exists at that breakpoint — pulling the
 * whole row 90-185px wider than the viewport at 375/390px (confirmed live
 * via Playwright: DashboardChartGridOverflowTest documents what
 * grid-cols-1 fixes). Pest cannot execute Chart.js or measure a rendered
 * layout box, so this locks the fix in at the source level, per the
 * project's established convention for CSS-only regressions (see
 * PhysicianConsultationInboxTableOverflowTest, NotificationPanelMobile
 * PositioningTest).
 */
it('gives every grid wrapper around a chart an explicit base column count', function () {
    $files = [
        resource_path('views/admin/dashboard.blade.php'),
        resource_path('views/physician/dashboard.blade.php'),
    ];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        // Every `grid ... lg:grid-cols-2` wrapper in these two files directly
        // contains at least one <x-dash.chart> (verified by inspection) — so
        // every occurrence of this exact pre-Phase-3 class string would mean
        // the overflow-causing pattern has come back.
        expect($source)->not->toContain('class="grid gap-4 lg:grid-cols-2"', "grid-cols-1 is missing on a chart-grid wrapper in {$file}");
    }
});
