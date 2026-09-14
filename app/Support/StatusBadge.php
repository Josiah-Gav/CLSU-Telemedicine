<?php

namespace App\Support;

/**
 * Single source of truth for how a request status, priority level, or symptom
 * severity is presented as a badge (label + Tailwind classes + icon path).
 *
 * Two different renderers need these tokens, which is why they live here rather
 * than inline in the Blade component:
 *
 *  - <x-dash.badge> renders them server-side for the nurse inbox, both
 *    dashboards, and both consultation-history views.
 *  - The physician consultation inbox renders its table rows with Alpine's
 *    x-for so the AJAX table-only refresh can repopulate them, and a Blade
 *    component cannot re-render per Alpine row. PhysicianController
 *    serializes these tokens into each row's JSON instead, and the template
 *    just binds them.
 *
 * Keeping one copy means a colour change here reaches both renderers; a JS
 * copy of these maps would silently drift from the Blade one.
 */
final class StatusBadge
{
    /**
     * Heroicons outline path data, keyed by the name used in the maps below.
     */
    private const ICONS = [
        'clock' => 'M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z',
        'clipboard-check' => 'M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0V11.25A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
        'signal' => 'M9.348 14.652a3.75 3.75 0 010-5.304m5.304 0a3.75 3.75 0 010 5.304m-7.425 2.121a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M12 12h.008v.008H12V12z',
        'check-circle' => 'M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'x-circle' => 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'minus-circle' => 'M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'arrow-up-circle' => 'M8.25 9.75L12 6l3.75 3.75M12 6v12',
        'user-check' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
    ];

    /**
     * `assigned` is a real Consultation::request_status — the physician inbox
     * queries reviewed/assigned/scheduled — but it was missing here, so those
     * rows previously rendered an empty badge.
     */
    private const STATUS_MAP = [
        'pending' => ['label' => 'Pending', 'classes' => 'bg-amber-100 text-amber-800', 'icon' => 'clock'],
        'reviewed' => ['label' => 'Reviewed', 'classes' => 'bg-cyan-100 text-cyan-800', 'icon' => 'clipboard-check'],
        'assigned' => ['label' => 'Assigned', 'classes' => 'bg-sky-100 text-sky-800', 'icon' => 'user-check'],
        'scheduled' => ['label' => 'Scheduled', 'classes' => 'bg-indigo-100 text-indigo-800', 'icon' => 'calendar'],
        'active' => ['label' => 'Active', 'classes' => 'bg-brand-green text-white', 'icon' => 'signal'],
        'completed' => ['label' => 'Completed', 'classes' => 'bg-slate-100 text-slate-700', 'icon' => 'check-circle'],
        'rejected' => ['label' => 'Rejected', 'classes' => 'bg-red-100 text-red-800', 'icon' => 'x-circle'],
        'cancelled' => ['label' => 'Cancelled', 'classes' => 'border border-dashed border-slate-300 bg-slate-50 text-slate-600', 'icon' => 'minus-circle'],
    ];

    private const PRIORITY_MAP = [
        'High' => ['label' => 'High Priority', 'classes' => 'bg-red-100 text-red-800', 'icon' => 'arrow-up-circle'],
        'Normal' => ['label' => 'Normal Priority', 'classes' => 'bg-slate-100 text-slate-600', 'icon' => null],
    ];

    /**
     * Severity is a 1-4 scale on each entry of Consultation::symptoms_desc.
     * Unlike status/priority it has no icon — the number carries the meaning,
     * so colour is never the only signal.
     */
    private const SEVERITY_MAP = [
        1 => ['label' => '1 - Very Mild', 'classes' => 'bg-green-100 text-green-800'],
        2 => ['label' => '2 - Mild', 'classes' => 'bg-yellow-100 text-yellow-800'],
        3 => ['label' => '3 - Moderate', 'classes' => 'bg-orange-100 text-orange-800'],
        4 => ['label' => '4 - Severe', 'classes' => 'bg-red-100 text-red-800'],
    ];

    private const NEUTRAL_SEVERITY = ['label' => 'N/A', 'classes' => 'bg-gray-100 text-gray-700'];

    /**
     * A first-time patient sees a status badge ("Reviewed", "Scheduled")
     * with no indication of what it means or what they should expect next —
     * flagged by the Phase 2 UX brief. Keyed on the same request_status
     * vocabulary as STATUS_MAP above so both patient-facing templates
     * (dashboard.blade.php, consultation-details.blade.php) pull the exact
     * same sentence rather than inventing their own wording independently.
     * 'assigned' is deliberately absent: docs/paper/glossary.md documents it
     * as a dead enum value no code path ever writes.
     */
    private const PATIENT_MEANING = [
        'pending' => "We've received your request and a nurse will review it shortly.",
        'reviewed' => 'A nurse has reviewed your request and is arranging a physician for you.',
        'scheduled' => 'Your consultation has been scheduled — see the appointment time below.',
        'active' => 'Your consultation is in progress right now.',
        'completed' => 'This consultation has been completed.',
        'rejected' => 'This request was not accepted.',
        'cancelled' => 'This request was cancelled.',
    ];

    /**
     * Null for any status this map doesn't cover, rather than a guessed
     * fallback sentence — an unrecognized status should show no explanation,
     * not a misleading one.
     */
    public static function patientMeaning(?string $status): ?string
    {
        return self::PATIENT_MEANING[$status] ?? null;
    }

    /**
     * Phase 4: patient/dashboard.blade.php and patient/consultation-details.blade.php
     * each hand-computed this exact if/elseif chain independently — and had
     * quietly drifted apart (dashboard.blade.php gave 'pending'/'assigned' a
     * dedicated yellow treatment; consultation-details.blade.php did not,
     * silently falling through to the slate default). Centralizing here
     * fixes that inconsistency as a side effect of removing the
     * duplication, not a separate redesign.
     *
     * This is a deliberately different, lighter/pastel palette from
     * STATUS_MAP above — chosen for a patient-facing content card, not a
     * dense staff table row (see design-system/clsu-telemedicine/pages/
     * soft-modern-clinical-saas.md § Status semantics). Kept as its own
     * map rather than merged into STATUS_MAP: unifying the two palettes
     * would be a visible color change to patient-facing pages beyond what
     * a duplication cleanup should risk — deferred, not forgotten.
     */
    private const PATIENT_STATUS_CLASSES = [
        'rejected' => 'bg-red-100 text-red-700',
        'cancelled' => 'bg-red-100 text-red-700',
        'completed' => 'bg-emerald-100 text-emerald-700',
        'pending' => 'bg-yellow-100 text-yellow-700',
        'assigned' => 'bg-yellow-100 text-yellow-700',
        'scheduled' => 'bg-brand-gold-soft text-brand-green-deep',
        'active' => 'bg-brand-green-soft text-brand-green-deep',
    ];

    private const PATIENT_STATUS_DEFAULT = 'bg-slate-100 text-slate-700';

    /**
     * Full badge class string (shape + color) for a patient-facing status
     * pill — used by patient/dashboard.blade.php and
     * patient/consultation-details.blade.php in place of each maintaining
     * its own copy of this mapping.
     */
    public static function patientClasses(?string $status): string
    {
        $colorClasses = self::PATIENT_STATUS_CLASSES[$status] ?? self::PATIENT_STATUS_DEFAULT;

        return "inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold {$colorClasses}";
    }

    /**
     * @return array{label: string, classes: string, icon_path: string|null}|null
     */
    public static function status(?string $status): ?array
    {
        return $status === null ? null : self::resolve(self::STATUS_MAP[$status] ?? null);
    }

    /**
     * @return array{label: string, classes: string, icon_path: string|null}|null
     */
    public static function priority(?string $priority): ?array
    {
        return $priority === null ? null : self::resolve(self::PRIORITY_MAP[$priority] ?? null);
    }

    /**
     * Always returns a badge — an unscored consultation still needs an "N/A"
     * cell rather than an empty one.
     *
     * @return array{label: string, classes: string, icon_path: null}
     */
    public static function severity(?int $severity): array
    {
        $config = self::SEVERITY_MAP[$severity] ?? self::NEUTRAL_SEVERITY;

        return [
            'label' => $config['label'],
            'classes' => $config['classes'],
            'icon_path' => null,
        ];
    }

    /**
     * The highest severity across a symptoms_desc payload, which is what the
     * inbox tables show: one badge per consultation, not per symptom. Returns
     * null when the payload carries no numeric severity at all.
     */
    public static function highestSeverity(mixed $symptoms): ?int
    {
        if (! is_array($symptoms)) {
            return null;
        }

        $values = collect($symptoms)
            ->map(fn ($item) => is_array($item) ? ($item['severity'] ?? null) : null)
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->all();

        return $values === [] ? null : max($values);
    }

    /**
     * @param  array{label: string, classes: string, icon: string|null}|null  $config
     * @return array{label: string, classes: string, icon_path: string|null}|null
     */
    private static function resolve(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        return [
            'label' => $config['label'],
            'classes' => $config['classes'],
            'icon_path' => $config['icon'] === null ? null : self::ICONS[$config['icon']],
        ];
    }
}
