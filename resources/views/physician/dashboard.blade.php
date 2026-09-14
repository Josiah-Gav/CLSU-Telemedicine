<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @vite(['resources/js/dashboards.js'])

    <div class="py-8">
        <div class="max-w-7xl mx-auto space-y-8 px-4 sm:px-6 lg:px-8">

            <div class="overflow-hidden rounded-3xl border border-brand-border bg-gradient-to-r from-brand-green-soft via-white to-brand-gold-soft shadow-sm">
                <div class="p-6 text-brand-green-deep sm:p-8">
                    <p class="text-xs font-bold uppercase text-brand-green">Physician Dashboard</p>
                    <h2 class="mt-2 text-2xl font-bold text-slate-900">
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
                        :href="route('physician.active_consultation', ['physician' => Auth::user()->user_id])"
                        aria-label="{{ $analytics['operational']['active_now'] }} consultations active right now"
                        :supporting="$analytics['operational']['active_now'] === 0 ? 'No consultation in progress.' : null"
                    />
                    <x-dash.stat
                        label="Scheduled Consultation ahead"
                        :value="$analytics['operational']['scheduled_ahead']"
                        :href="route('physician.scheduled_consultation', ['physician' => Auth::user()->user_id])"
                        aria-label="{{ $analytics['operational']['scheduled_ahead'] }} consultations scheduled ahead"
                    />
                </div>
            </section>

            {{-- ================= BAND 3 — FILTER BOUNDARY ================= --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
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

                @php
                    $volume = $analytics['charts']['volume_over_time'];
                    $hasEnoughPointsForLine = count($volume['labels']) >= 4;
                    $totalVolume = array_sum($volume['datasets'][0]['data'] ?? []);
                @endphp

                @if ($hasEnoughPointsForLine)
                    <x-dash.chart
                        chart-id="physician-volume-chart"
                        type="line"
                        title="My consultation volume, by submission date"
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
                --}}
                @php
                    $statusChart = $analytics['charts']['status_distribution'];
                    $statusLabelsForDisplay = array_map('ucfirst', $statusChart['labels']);
                @endphp
                <x-dash.chart
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
                    @php
                        $typeChart = $analytics['charts']['initial_vs_follow_up'];
                    @endphp
                    <x-dash.chart
                        chart-id="physician-type-chart"
                        type="splitbar-type"
                        title="Initial vs Follow-up"
                        :labels="$typeChart['labels']"
                        :datasets="$typeChart['datasets']"
                        summary="Proportion of my consultations that are initial versus follow-up"
                        empty-message="No consultations in this period."
                        height="h-32"
                    />

                    @php
                        $priorityChart = $analytics['charts']['priority_distribution'];
                    @endphp
                    <x-dash.chart
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
            </x-dash.section>

            {{-- ================= BAND 5 — SYMPTOM ANALYTICS (date-filtered, own patients only) ================= --}}
            @php
                $symptoms = $analytics['symptoms'];
                $standardizedLabels = array_column($symptoms['standardized'], 'name');
                $standardizedCounts = array_column($symptoms['standardized'], 'count');
            @endphp
            <x-dash.section
                id="physician-symptom-analytics"
                title="What My Patients Are Reporting"
                description="Based on initial requests only. Follow-up consultations repeat the original request's symptoms, so including them would count the same report more than once."
            >
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
            </x-dash.section>

        </div>
    </div>
</x-app-layout>
