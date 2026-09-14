<?php

use App\Support\StatusBadge;

/**
 * Phase 4: patient/dashboard.blade.php and patient/consultation-details.blade.php
 * each hand-computed the same status→color mapping independently — and had
 * actually drifted apart: dashboard.blade.php gave 'pending' its own yellow
 * treatment, while consultation-details.blade.php let 'pending' fall through
 * to the generic slate default, silently. Centralizing here fixes that real
 * inconsistency (both pages now agree) as a direct consequence of removing
 * the duplication, not a separate visual redesign — the palette itself
 * (lighter/pastel, distinct from the staff-facing StatusBadge::status()
 * map) is preserved exactly as documented in
 * design-system/clsu-telemedicine/pages/soft-modern-clinical-saas.md.
 */
it('gives pending and assigned their own yellow treatment, not the generic default', function () {
    expect(StatusBadge::patientClasses('pending'))->toContain('bg-yellow-100')
        ->and(StatusBadge::patientClasses('assigned'))->toContain('bg-yellow-100');
});

it('matches the rest of the previously-duplicated patient palette', function () {
    expect(StatusBadge::patientClasses('rejected'))->toContain('bg-red-100')
        ->and(StatusBadge::patientClasses('cancelled'))->toContain('bg-red-100')
        ->and(StatusBadge::patientClasses('completed'))->toContain('bg-emerald-100')
        ->and(StatusBadge::patientClasses('scheduled'))->toContain('bg-brand-gold-soft')
        ->and(StatusBadge::patientClasses('active'))->toContain('bg-brand-green-soft')
        ->and(StatusBadge::patientClasses('reviewed'))->toContain('bg-slate-100');
});

it('falls back to the generic slate treatment for any other status', function () {
    expect(StatusBadge::patientClasses('made_up_status'))->toContain('bg-slate-100')
        ->and(StatusBadge::patientClasses(null))->toContain('bg-slate-100');
});

it('always includes the shared badge shape classes', function () {
    expect(StatusBadge::patientClasses('active'))->toContain('rounded-full')
        ->and(StatusBadge::patientClasses('active'))->toContain('font-semibold');
});
