<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation History') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 bg-white border-b border-gray-200">
                    <div class="flex flex-col gap-6">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Your Consultation History</h3>
                                <p class="mt-1 text-sm text-slate-500">Review your past consultation requests and their status.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-dash.export-menu
                                    route="consultations.history.export"
                                    :query-params="[
                                        'date_filter' => $filters['date_filter'] ?? 'all',
                                        'status' => $filters['status'] ?? 'all',
                                        'consultation_type' => $filters['consultation_type'] ?? 'all',
                                    ]"
                                    label="Export History"
                                />
                                <x-button-primary href="{{ route('consultations.create') }}">New Consultation</x-button-primary>
                            </div>
                        </div>

                                <form method="GET" action="{{ route('consultations.history') }}" class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-4">
                                        <div class="grid gap-4 sm:grid-cols-3">
                                            <div>
                                                <label for="date_filter" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Date Range</label>
                                                <select id="date_filter" name="date_filter" class="mt-2 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-green-100">
                                                    <option value="all" {{ ($filters['date_filter'] ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                                                    <option value="today" {{ ($filters['date_filter'] ?? 'all') === 'today' ? 'selected' : '' }}>Today</option>
                                                    <option value="last_7_days" {{ ($filters['date_filter'] ?? 'all') === 'last_7_days' ? 'selected' : '' }}>Last 7 Days</option>
                                                    <option value="last_30_days" {{ ($filters['date_filter'] ?? 'all') === 'last_30_days' ? 'selected' : '' }}>Last 30 Days</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="status" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                                                <select id="status" name="status" class="mt-2 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-green-100">
                                                    <option value="all" {{ ($filters['status'] ?? 'all') === 'all' ? 'selected' : '' }}>All Statuses</option>
                                                    <option value="completed" {{ ($filters['status'] ?? 'all') === 'completed' ? 'selected' : '' }}>Completed</option>
                                                    <option value="cancelled" {{ ($filters['status'] ?? 'all') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                                    <option value="rejected" {{ ($filters['status'] ?? 'all') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="consultation_type" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Consultation Type</label>
                                                <select id="consultation_type" name="consultation_type" class="mt-2 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-green-100">
                                                    <option value="all" {{ ($filters['consultation_type'] ?? 'all') === 'all' ? 'selected' : '' }}>All Types</option>
                                                    <option value="follow_up" {{ ($filters['consultation_type'] ?? 'all') === 'follow_up' ? 'selected' : '' }}>Follow-up</option>
                                                    <option value="general" {{ ($filters['consultation_type'] ?? 'all') === 'general' ? 'selected' : '' }}>General</option>
                                                </select>
                                            </div>
                                            <div class="flex items-end gap-2">
                                                <x-button-primary type="submit">Apply</x-button-primary>
                                                <x-button-secondary href="{{ route('consultations.history') }}">Reset</x-button-secondary>
                                            </div>
                                        </div>
                                    </div>
                                </form>

                        @if(($historyItems->isEmpty() ?? true))
                            <div class="rounded-3xl border border-gray-200 bg-slate-50 p-8 text-center">
                                <p class="text-lg font-semibold text-slate-900">No consultation history found.</p>
                                        <p class="mt-2 text-sm text-slate-500">No records matched your selected filters. Try adjusting your filters.</p>
                            </div>
                        @else
                            <div class="space-y-4">
                                @foreach($historyItems as $historyItem)
                                    @if($historyItem['type'] === 'consultation')
                                        @php
                                            $consultation = $historyItem['consultation'];
                                            $consultationTypeLabel = $consultation->type === 'follow_up' ? 'Follow-up' : 'General';
                                            $normalizedConcernCategory = trim((string) ($consultation->concern_category ?? ''));
                                            $shouldIncludeConcernCategory = $normalizedConcernCategory !== '' && strtolower($normalizedConcernCategory) !== 'general' && strtolower($normalizedConcernCategory) !== strtolower($consultationTypeLabel);
                                            $historyTitle = $shouldIncludeConcernCategory
                                                ? $consultationTypeLabel . ' ' . ucfirst($normalizedConcernCategory) . ' Consultation'
                                                : $consultationTypeLabel . ' Consultation';
                                            $status = $consultation->request_status;
                                            $badgeClasses = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';
                                            if (in_array($status, ['rejected', 'cancelled'], true)) {
                                                $badgeClasses .= 'bg-red-100 text-red-700';
                                            } elseif ($status === 'completed') {
                                                $badgeClasses .= 'bg-emerald-100 text-emerald-700';
                                            } elseif (in_array($status, ['pending', 'assigned'], true)) {
                                                $badgeClasses .= 'bg-yellow-100 text-yellow-700';
                                            } elseif ($status === 'scheduled') {
                                                $badgeClasses .= 'bg-brand-gold-soft text-brand-green-deep';
                                            } elseif ($status === 'active') {
                                                $badgeClasses .= 'bg-brand-green-soft text-brand-green-deep';
                                            } else {
                                                $badgeClasses .= 'bg-slate-100 text-slate-700';
                                            }
                                        @endphp
                                        <a href="{{ route('consultations.show', $consultation) }}" class="block rounded-3xl border border-gray-200 bg-white p-6 shadow-sm transition hover:border-blue-300 hover:bg-slate-50">
                                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                                <div>
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $historyTitle }}</p>
                                                    <h4 class="mt-2 text-xl font-semibold text-slate-900">{{ ucfirst($status) }}</h4>
                                                    <p class="mt-1 text-sm text-slate-600">Submitted {{ $consultation->submitted_at->format('M d, Y @ h:i A') }}</p>
                                                </div>
                                                <div class="inline-flex items-center gap-3">
                                                    <span class="{{ $badgeClasses }}">{{ ucfirst($status) }}</span>
                                                    <span class="text-sm text-slate-400">View details ></span>
                                                </div>
                                            </div>
                                        </a>

                                        @if($consultation->request_status === 'rejected' && filled($consultation->rejection_reason))
                                            <div class="mt-3 rounded-2xl border border-red-200 bg-red-50 p-4">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-red-600">Decision for Rejection</p>
                                                <p class="mt-2 text-sm text-red-800">{{ $consultation->rejection_reason }}</p>
                                            </div>
                                        @endif
                                    @endif

                                    @if($historyItem['type'] === 'rejected_follow_up_request')
                                        @php
                                            $followUpRequest = $historyItem['follow_up_request'];
                                            $sourceConsultation = $followUpRequest->consultation?->request;
                                            $sourceConcernCategory = trim((string) ($sourceConsultation?->concern_category ?? ''));
                                            $followUpTitle = $sourceConcernCategory !== ''
                                                ? 'Follow-up ' . ucfirst($sourceConcernCategory) . ' Request'
                                                : 'Follow-up Request';
                                        @endphp
                                        <div class="block rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
                                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                                <div>
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $followUpTitle }}</p>
                                                    <h4 class="mt-2 text-xl font-semibold text-slate-900">Rejected</h4>
                                                    <p class="mt-1 text-sm text-slate-600">Updated {{ optional($followUpRequest->updated_at)->format('M d, Y @ h:i A') ?? 'N/A' }}</p>
                                                </div>
                                                <div class="inline-flex items-center gap-3">
                                                    <span class="inline-flex items-center rounded-full bg-red-100 px-4 py-2 text-sm font-semibold text-red-700">Rejected</span>
                                                </div>
                                            </div>
                                            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Request Reason</p>
                                                    <p class="mt-2 text-sm text-slate-700">{{ $followUpRequest->reason ?: 'No reason provided.' }}</p>
                                                </div>
                                                <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-red-600">Decision Notes</p>
                                                    <p class="mt-2 text-sm text-red-800">{{ $followUpRequest->decision_notes ?: 'No decision notes provided.' }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>