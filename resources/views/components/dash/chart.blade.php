@props([
    'title',
    'description' => null,
    'type' => 'hbar', // line | hbar | hbar-status | splitbar-priority | splitbar-type | severity
    'labels' => [],
    'datasets' => [],
    'chartId',
    'summary' => null,
    'footnote' => null,
    'emptyMessage' => 'No data for this period.',
    'height' => 'h-64',
    'variant' => 'default', // default | hero — hero gets a tinted header and a bigger radius, for the one headline chart on a page
    'bare' => false, // true: no outer card chrome — for stacking inside a divide-y grouped panel instead of standing alone
])

{{--
    The only way a chart is placed on any dashboard — wraps the header,
    canvas, empty state, and the accessible data-table fallback together so
    none of them can be forgotten (Phase 2 §7.3). Blade computes nothing
    here beyond checking whether the already-supplied numbers sum to zero;
    every value in $labels/$datasets was computed by DashboardAnalyticsService.
--}}
@php
    $total = collect($datasets)->sum(fn ($set) => array_sum($set['data'] ?? []));
    $isEmpty = $total <= 0;
    $accessibleSummary = $summary ?? $title;

    // Phase 5 finding C-1: Blade's @json directive does explode(',', ...)
    // on its raw argument text, so a multi-key array literal passed
    // directly — @json(['labels' => $labels, 'datasets' => $datasets]) —
    // has its top-level comma misparsed, and the JSON_HEX_* escaping flags
    // silently get replaced by the $depth default (512), which is then
    // read as $flags. Apostrophes in patient-controlled symptom names
    // (ConsultationController::store validates symptoms_payload only as
    // `required|string`, with no per-entry validation) then reach this
    // single-quoted HTML attribute unescaped.
    //
    // Illuminate\Support\Js::encode() is a plain PHP method call (not a
    // Blade directive), so the array literal's commas are parsed normally
    // by PHP — no splitting bug — and it unconditionally forces
    // JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT via its
    // REQUIRED_FLAGS regardless of what's passed in, so this stays safe
    // even if a future edit adds explicit flags here. The output is then
    // also passed through {{ }} (e()/htmlspecialchars) as a second,
    // independent layer — the security boundary is output encoding at
    // both the JSON and HTML layers, not client-side filtering.
    $chartPayloadJson = \Illuminate\Support\Js::encode(['labels' => $labels, 'datasets' => $datasets]);

    $outerClasses = match (true) {
        $bare => '',
        $variant === 'hero' => 'rounded-3xl border border-brand-border bg-white overflow-hidden',
        default => 'rounded-xl border border-brand-border bg-white',
    };
    $headerClasses = $variant === 'hero'
        ? 'flex items-center gap-2.5 bg-brand-green-soft px-6 py-4'
        : 'flex flex-col gap-1 border-b border-brand-border px-4 py-3 sm:flex-row sm:items-start sm:justify-between';
    $titleClasses = $variant === 'hero' ? 'text-sm font-bold text-brand-green-deep' : 'text-sm font-semibold text-slate-900';
    $descriptionClasses = $variant === 'hero' ? 'mt-0.5 text-xs text-brand-green-deep/70' : 'mt-0.5 text-xs text-slate-500';
@endphp

<div {{ $attributes->merge(['class' => $outerClasses]) }}>
    <div class="{{ $headerClasses }}">
        @if ($variant === 'hero')
            <svg class="h-[18px] w-[18px] flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="#0a4d2d" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
        @endif
        <div>
            <h3 class="{{ $titleClasses }}">{{ $title }}</h3>
            @if ($description)
                <p class="{{ $descriptionClasses }}">{{ $description }}</p>
            @endif
        </div>
        @isset($action)
            <div>{{ $action }}</div>
        @endisset
    </div>

    <div class="{{ $variant === 'hero' ? 'p-6' : 'p-4' }}" data-chart-wrapper>
        @if ($isEmpty)
            <x-dash.empty :message="$emptyMessage" />
        @else
            <div class="{{ $height }} w-full">
                <canvas
                    id="{{ $chartId }}"
                    role="img"
                    aria-label="{{ $accessibleSummary }}"
                    data-chart
                    data-chart-type="{{ $type }}"
                    data-chart-payload="{{ $chartPayloadJson }}"
                ></canvas>
            </div>

            <details class="mt-3">
                <summary class="cursor-pointer select-none text-xs font-semibold uppercase tracking-wide text-brand-green">
                    View data as table
                </summary>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ $accessibleSummary }}</caption>
                        <thead>
                            <tr class="border-b border-brand-border text-xs uppercase tracking-wide text-slate-500">
                                <th scope="col" class="py-1 pr-4 font-semibold">Label</th>
                                @foreach ($datasets as $set)
                                    <th scope="col" class="py-1 pr-4 font-semibold">{{ $set['label'] ?? 'Value' }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($labels as $i => $label)
                                <tr class="border-b border-brand-border/60 last:border-0">
                                    <td class="py-1 pr-4 font-medium text-slate-700">{{ $label }}</td>
                                    @foreach ($datasets as $set)
                                        <td class="py-1 pr-4 tabular-nums text-slate-600">{{ $set['data'][$i] ?? 0 }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif

        @if ($footnote)
            <p class="mt-3 text-xs text-slate-500">{{ $footnote }}</p>
        @endif
    </div>
</div>
