<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @vite(['resources/js/dashboards.js'])

    <div class="py-8">
        <div class="max-w-7xl mx-auto space-y-8 px-4 sm:px-6 lg:px-8">

            <div class="overflow-hidden rounded-3xl bg-brand-green-deep shadow-sm">
                <div class="p-6 sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-wide text-white/70">Physician Dashboard</p>
                    <h2 class="mt-2 text-2xl font-bold text-white">
                        {{ __('Hello Doc ' . Auth::user()->first_name . '!') }}
                    </h2>
                </div>
            </div>

            <x-physician.intake-status-card
                :intake="$intake"
                :routes="$intakeRoutes"
                :manage-url="route('physician.consultation_intake', ['physician' => Auth::user()->user_id])"
                :today-schedule="$todaySchedule"
                :warn-if-closed="$showIntakeScheduleWarning"
            />

            {{-- ================= BAND 2 — NOW (unfiltered, always current) ================= --}}
            <section aria-labelledby="physician-now-heading" class="rounded-2xl border border-brand-border bg-white p-4 sm:p-6">
                <h2 id="physician-now-heading" class="text-lg font-bold text-slate-900">Right Now</h2>
                <p class="mt-1 text-sm text-slate-500">Always current, not affected by the date filter below.</p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-dash.stat
                        label="Active Consultation now"
                        :value="$analytics['operational']['active_now']"
                        :tone="$analytics['operational']['active_now'] > 0 ? 'active' : 'neutral'"
                        icon="pulse"
                        :href="route('physician.active_consultation', ['physician' => Auth::user()->user_id])"
                        aria-label="{{ $analytics['operational']['active_now'] }} consultations active right now"
                        :supporting="$analytics['operational']['active_now'] === 0 ? 'No consultation in progress.' : null"
                    />
                    <x-dash.stat
                        label="Scheduled Consultation ahead"
                        :value="$analytics['operational']['scheduled_ahead']"
                        icon="calendar"
                        :href="route('physician.scheduled_consultation', ['physician' => Auth::user()->user_id])"
                        aria-label="{{ $analytics['operational']['scheduled_ahead'] }} consultations scheduled ahead"
                    />
                </div>
            </section>

            {{-- ================= BAND 3 — FILTER BOUNDARY ================= --}}
            {{-- Quiet chrome, not another card: a hairline under the toolbar
                 is what marks it as controls rather than content, so it
                 doesn't compete with the KPI/chart cards below it. --}}
            <div class="flex flex-col gap-3 border-b border-brand-border pb-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex-1">
                    <x-dash.filter-bar
                        :date-range="$dateRange"
                        :action="route('physician.dashboard', ['physician' => Auth::user()->user_id])"
                        scope-note="Historical analytics only — 'Active now' and 'Scheduled ahead' above always show current state, regardless of this filter."
                    />
                </div>
                <x-dash.export-menu
                    route="physician.dashboard.export"
                    :route-params="['physician' => Auth::user()->user_id]"
                    :date-range="$dateRange"
                />
            </div>

            {{-- ================= BAND 4 — HISTORICAL ANALYTICS (date-filtered) ================= --}}
            <x-dash.section
                id="physician-history"
                title="My Analytics — Selected Period"
                description="Scoped to your own assigned consultations only."
            >
                @php
                    $rate = $analytics['period']['completion_rate'];
                    $rateDisplay = $rate['rate'] === null ? '—' : $rate['rate'] . '%';
                    $rateSupporting = $rate['concluded'] > 0
                        ? $rate['completed'] . ' of ' . $rate['concluded'] . ' concluded requests · of requests submitted this period'
                        : 'No concluded requests yet in this period.';
                @endphp
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-1.5">
                        <svg class="h-[13px] w-[13px] text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span class="text-[11px] font-bold uppercase tracking-wide text-slate-400">This period</span>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-dash.stat
                            label="Completed"
                            :value="$analytics['period']['completed']"
                            supporting="Completed during this period"
                        />
                        <x-dash.stat
                            label="Completion Rate"
                            :value="$rateDisplay"
                            :supporting="$rateSupporting"
                        >
                            <p class="mt-2 text-xs text-slate-400">
                                Completed consultations as a percentage of concluded requests (completed + rejected + cancelled). In-progress requests are excluded.
                            </p>
                        </x-dash.stat>
                    </div>
                </div>

                @php
                    $volume = $analytics['charts']['volume_over_time'];
                    $hasEnoughPointsForLine = count($volume['labels']) >= 4;
                    $totalVolume = array_sum($volume['datasets'][0]['data'] ?? []);
                @endphp

                @if ($hasEnoughPointsForLine)
                    <x-dash.chart
                        chart-id="physician-volume-chart"
                        type="line"
                        variant="hero"
                        title="My consultation volume, by submission date"
                        description="The headline trend for this period"
                        :labels="$volume['labels']"
                        :datasets="$volume['datasets']"
                        summary="Line chart of my consultation volume by submission date for the selected period"
                        empty-message="No consultations in this period."
                    />
                @else
                    <div class="rounded-xl border border-brand-border bg-white p-4">
                        <h3 class="text-sm font-semibold text-slate-900">My consultation volume, by submission date</h3>
                        <p class="mt-2 text-2xl font-bold tabular-nums text-slate-900">{{ $totalVolume }} {{ $totalVolume === 1 ? 'consultation' : 'consultations' }}</p>
                        <p class="mt-1 text-xs text-slate-500">Too few days in this period for a trend line — showing the total instead.</p>
                    </div>
                @endif

                {{--
                    Phase 5 finding M-4: pairing the 7-category status chart
                    (h-56) with a single-row split-bar chart (h-32) in the
                    same grid row stretched the shorter cell to match,
                    leaving dead whitespace under it. Status now gets its
                    own full-width row; the two naturally-equal-height
                    split-bar charts (type, priority) share a row instead,
                    so nothing is stretched beyond its natural height.

                    All three charts below are grouped into one panel
                    (divide-y for the rule between rows) instead of standing
                    as three separate cards — they're facets of the same
                    "selected period" data, not unrelated content.
                --}}
                @php
                    $statusChart = $analytics['charts']['status_distribution'];
                    $statusLabelsForDisplay = array_map('ucfirst', $statusChart['labels']);
                    $typeChart = $analytics['charts']['initial_vs_follow_up'];
                    $priorityChart = $analytics['charts']['priority_distribution'];
                @endphp
                <div class="overflow-hidden rounded-xl border border-brand-border bg-white divide-y divide-brand-border">
                    <div class="px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Breakdown — Selected Period</p>
                    </div>

                    <x-dash.chart
                        bare
                        chart-id="physician-status-chart"
                        type="hbar-status"
                        title="Status distribution"
                        :labels="$statusLabelsForDisplay"
                        :datasets="[['label' => 'Consultations', 'data' => $statusChart['datasets'][0]['data']]]"
                        summary="Horizontal bar chart of my consultations by status for the selected period"
                        empty-message="No consultations in this period."
                        height="h-56"
                    />

                    {{-- Phase 3: grid-cols-1 must be explicit here, not left to
                         implicit auto-sizing. Without it, a bare `grid` container
                         has no defined column track below `lg:`, so the browser
                         sizes its single implicit column to fit the widest
                         child's max-content — and a <canvas> contributes its
                         fixed width/height HTML attributes (Chart.js sets these)
                         as that max-content, not its CSS display size. That
                         pulled the whole row wider than the viewport at
                         375/390px (confirmed live via Playwright). grid-cols-1
                         forces an explicit minmax(0,1fr) track instead. --}}
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <x-dash.chart
                            bare
                            chart-id="physician-type-chart"
                            type="splitbar-type"
                            title="Initial vs Follow-up"
                            :labels="$typeChart['labels']"
                            :datasets="$typeChart['datasets']"
                            summary="Proportion of my consultations that are initial versus follow-up"
                            empty-message="No consultations in this period."
                            height="h-32"
                        />

                        <x-dash.chart
                            bare
                            chart-id="physician-priority-chart"
                            type="splitbar-priority"
                            title="Priority mix"
                            :labels="$priorityChart['labels']"
                            :datasets="$priorityChart['datasets']"
                            summary="Proportion of my consultations that are High versus Normal priority"
                            empty-message="No consultations in this period."
                            height="h-32"
                        />
                    </div>
                </div>
            </x-dash.section>

            {{-- ================= BAND 5 — SYMPTOM ANALYTICS (date-filtered, own patients only) ================= --}}
            {{-- Its own tinted module, not another white card in the same
                 stack — this is patient-reported clinical signal, a
                 different kind of information from the operational counts
                 in Band 4 above it, and the gold tint is what makes that
                 legible at a glance. --}}
            @php
                $symptoms = $analytics['symptoms'];
                $standardizedLabels = array_column($symptoms['standardized'], 'name');
                $standardizedCounts = array_column($symptoms['standardized'], 'count');
            @endphp
            <section aria-labelledby="physician-symptom-heading" class="rounded-2xl border-2 border-brand-gold/30 bg-brand-gold-soft p-4 sm:p-6">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-amber-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                    </svg>
                    <h2 id="physician-symptom-heading" class="text-lg font-bold text-amber-900">What My Patients Are Reporting</h2>
                </div>
                <p class="mt-1 text-sm text-amber-800/80">
                    Based on initial requests only. Follow-up consultations repeat the original request's symptoms, so including them would count the same report more than once.
                </p>

                <div class="mt-4">
                    <x-dash.chart
                        chart-id="physician-symptoms-chart"
                        type="hbar"
                        title="Most reported symptoms"
                        :labels="$standardizedLabels"
                        :datasets="[['label' => 'Requests', 'data' => $standardizedCounts]]"
                        :summary="'Horizontal bar chart of the top standardized symptoms across ' . $symptoms['valid_requests'] . ' of my requests with recorded symptoms'"
                        empty-message="No symptom data recorded for this period."
                        :footnote="'Out of ' . $symptoms['valid_requests'] . ' of my initial requests with at least one recorded symptom.'"
                    />
                </div>
            </section>

        </div>
    </div>
</x-app-layout>
