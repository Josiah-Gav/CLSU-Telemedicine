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
                    <p class="text-xs font-bold uppercase tracking-wide text-white/70">Nurse Portal</p>
                    <h2 class="mt-2 text-2xl font-bold text-white">
                        {{ __('Hello Nurse ' . Auth::user()->first_name) }}
                    </h2>
                </div>
            </div>

            {{-- ================= BAND 2 — SHARED QUEUE (unfiltered, always current) ================= --}}
            <section aria-labelledby="shared-queue-heading" class="rounded-2xl border-2 border-brand-green/30 bg-brand-green-soft p-4 sm:p-6">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-brand-green" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                    </svg>
                    <h2 id="shared-queue-heading" class="text-lg font-bold text-brand-green-deep">Shared Queue</h2>
                </div>
                <p class="mt-1 text-sm text-brand-green-deep/80">
                    Shared across all nurses &middot; always current, not affected by the date filter below.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-dash.stat
                        label="Unclaimed pending requests"
                        :value="$analytics['operational']['unclaimed_pending']"
                        :tone="$analytics['operational']['unclaimed_high_priority'] > 0 ? 'critical' : 'neutral'"
                        icon="inbox"
                        :href="route('nurse.consultation_inbox', ['nurse' => $nurse->user_id])"
                        aria-label="{{ $analytics['operational']['unclaimed_pending'] }} unclaimed pending requests"
                    />
                    <x-dash.stat
                        label="High-priority, unclaimed"
                        :value="$analytics['operational']['unclaimed_high_priority']"
                        :tone="$analytics['operational']['unclaimed_high_priority'] > 0 ? 'critical' : 'neutral'"
                        icon="alert"
                        :href="route('nurse.consultation_inbox', ['nurse' => $nurse->user_id])"
                        aria-label="{{ $analytics['operational']['unclaimed_high_priority'] }} high priority unclaimed requests"
                    />
                    <x-dash.stat
                        label="Follow-ups awaiting triage"
                        :value="$analytics['operational']['follow_ups_awaiting_triage']"
                        icon="repeat"
                        :href="route('nurse.follow_up_requests', ['nurse' => $nurse->user_id])"
                        aria-label="{{ $analytics['operational']['follow_ups_awaiting_triage'] }} follow-up requests awaiting triage"
                    />
                </div>

                {{-- unclaimed_high_priority is a subset of unclaimed_pending
                     (both scoped from Consultation::pending()->unclaimed(),
                     see DashboardAnalyticsService), so checking it separately
                     here would be redundant — but follow_ups_awaiting_triage
                     comes from the independent FollowUpRequest model and used
                     to be left out of this gate entirely, so the queue could
                     read "clear" while a follow-up request still awaited
                     triage. --}}
                @if ($analytics['operational']['unclaimed_pending'] === 0 && $analytics['operational']['follow_ups_awaiting_triage'] === 0)
                    <div class="mt-4">
                        <x-dash.empty tone="positive" message="The queue is clear — no unclaimed requests." />
                    </div>
                @endif
            </section>

            {{-- ================= BAND 2b — MY WORKLOAD (unfiltered, always current) ================= --}}
            <section aria-labelledby="my-workload-heading" class="rounded-2xl border border-brand-border bg-white p-4 sm:p-6">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    </svg>
                    <h2 id="my-workload-heading" class="text-lg font-bold text-slate-900">My Workload</h2>
                </div>
                <p class="mt-1 text-sm text-slate-500">Assigned to you &middot; always current.</p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-dash.stat
                        label="My open cases"
                        :value="$analytics['operational']['my_open_cases']['total']"
                        icon="folder"
                        :href="route('nurse.consultation_inbox', ['nurse' => $nurse->user_id])"
                        aria-label="{{ $analytics['operational']['my_open_cases']['total'] }} of my cases are open"
                    >
                        <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5">Reviewed {{ $analytics['operational']['my_open_cases']['reviewed'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5">Scheduled {{ $analytics['operational']['my_open_cases']['scheduled'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5">Active {{ $analytics['operational']['my_open_cases']['active'] }}</span>
                        </div>
                    </x-dash.stat>
                    <x-dash.stat
                        label="My active consultations"
                        :value="$analytics['operational']['my_active']"
                        :tone="$analytics['operational']['my_active'] > 0 ? 'active' : 'neutral'"
                        icon="pulse"
                        aria-label="{{ $analytics['operational']['my_active'] }} of my consultations are active"
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
                        :action="route('nurse.dashboard', ['nurse' => $nurse->user_id])"
                        scope-note="Historical analytics only — the queue and workload above always show current state, regardless of this filter."
                    />
                </div>
                <x-dash.export-menu
                    route="nurse.dashboard.export"
                    :route-params="['nurse' => $nurse->user_id]"
                    :date-range="$dateRange"
                />
            </div>

            {{-- ================= BAND 4 — HISTORICAL ANALYTICS (date-filtered) ================= --}}
            <x-dash.section
                id="nurse-history"
                title="My Analytics — Selected Period"
                description="Requests in your caseload, scoped to the date range above."
            >
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-1.5">
                        <svg class="h-[13px] w-[13px] text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span class="text-[11px] font-bold uppercase tracking-wide text-slate-400">This period</span>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-dash.stat
                            label="My reviewed requests"
                            :value="$analytics['period']['my_reviewed_requests']"
                            supporting="Requests you triaged, by submission date"
                        />
                        <x-dash.stat
                            label="My Completed — Selected Period"
                            :value="$analytics['period']['my_completed']"
                            supporting="Not a real-time workload figure — scoped to the filter above"
                        />
                    </div>
                </div>

                @php
                    $volume = $analytics['charts']['volume_over_time'];
                    $hasEnoughPointsForLine = count($volume['labels']) >= 4;
                    $totalVolume = array_sum($volume['datasets'][0]['data'] ?? []);
                @endphp

                @if ($hasEnoughPointsForLine)
                    <x-dash.chart
                        chart-id="nurse-volume-chart"
                        type="line"
                        variant="hero"
                        title="Requests in my caseload, by submission date"
                        description="The headline trend for this period"
                        :labels="$volume['labels']"
                        :datasets="$volume['datasets']"
                        summary="Line chart of requests in my caseload by submission date for the selected period"
                        empty-message="No requests in your caseload for this period."
                        footnote="Plotted by when requests were submitted, not when you claimed them — there is no separate 'claimed at' timestamp."
                    />
                @else
                    <div class="rounded-xl border border-brand-border bg-white p-4">
                        <h3 class="text-sm font-semibold text-slate-900">Requests in my caseload, by submission date</h3>
                        <p class="mt-2 text-2xl font-bold tabular-nums text-slate-900">{{ $totalVolume }} {{ $totalVolume === 1 ? 'request' : 'requests' }}</p>
                        <p class="mt-1 text-xs text-slate-500">Too few days in this period for a trend line — showing the total instead.</p>
                    </div>
                @endif

                {{--
                    Phase 5 finding M-4: a 7-category status chart (h-56, to
                    fit the axis labels) was grid-paired with a single-row
                    split-bar chart (h-32). CSS grid stretches both cells to
                    the row's tallest member, leaving the split-bar's cell
                    with obvious dead whitespace below it. Status gets its
                    own full-width row; the split-bar chart — naturally
                    shorter, nothing else its height to pair with here —
                    also gets its own row, so neither is stretched to match
                    a sibling with a different natural height.
                --}}
                {{-- Grouped into one panel (divide-y for the rule between
                     rows) instead of standing as two separate cards — both
                     are facets of the same "selected period" caseload. --}}
                @php
                    $statusChart = $analytics['charts']['status_distribution'];
                    $statusLabelsForDisplay = array_map('ucfirst', $statusChart['labels']);
                    $priorityChart = $analytics['charts']['priority_distribution'];
                @endphp
                <div class="overflow-hidden rounded-xl border border-brand-border bg-white divide-y divide-brand-border">
                    <div class="px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Breakdown — Selected Period</p>
                    </div>

                    <x-dash.chart
                        bare
                        chart-id="nurse-status-chart"
                        type="hbar-status"
                        title="Status distribution"
                        :labels="$statusLabelsForDisplay"
                        :datasets="[['label' => 'Requests', 'data' => $statusChart['datasets'][0]['data']]]"
                        summary="Horizontal bar chart of my caseload's requests by status for the selected period"
                        empty-message="No requests in my caseload for this period."
                        height="h-56"
                    />

                    <div class="max-w-md">
                        <x-dash.chart
                            bare
                            chart-id="nurse-priority-chart"
                            type="splitbar-priority"
                            title="Priority mix"
                            :labels="$priorityChart['labels']"
                            :datasets="$priorityChart['datasets']"
                            summary="Proportion of my caseload's requests that are High versus Normal priority"
                            empty-message="No requests in my caseload for this period."
                            height="h-32"
                        />
                    </div>
                </div>
            </x-dash.section>

        </div>
    </div>
</x-app-layout>
