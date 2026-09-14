<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation History') }}
        </h2>
    </x-slot>

    <script>
        function initNurseHistoryLiveSearch() {
            const form = document.getElementById('nurse-history-filter-form');
            const searchInput = document.getElementById('search');
            const resultsContainer = document.getElementById('nurse-history-results');

            if (!form || !searchInput || !resultsContainer) {
                return;
            }

            let debounceTimer = null;

            searchInput.addEventListener('input', () => {
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                }

                debounceTimer = window.setTimeout(() => {
                    const params = new URLSearchParams(new FormData(form));
                    const requestUrl = `${form.action}?${params.toString()}`;

                    fetch(requestUrl, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            if (typeof data?.html === 'string') {
                                resultsContainer.innerHTML = data.html;
                            }
                        })
                        .catch(() => {
                            // Keep existing results when request fails.
                        });
                }, 300);
            });
        }

        document.addEventListener('DOMContentLoaded', initNurseHistoryLiveSearch);
    </script>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-4 flex justify-end">
                        <x-dash.export-menu
                            route="nurse.consultation_history.export"
                            :route-params="['nurse' => $nurse->user_id]"
                            :query-params="array_filter([
                                'date_filter' => $filters['date_filter'] ?? 'all',
                                'status' => $filters['status'] ?? 'all',
                                'consultation_type' => $filters['consultation_type'] ?? 'all',
                                'search' => $filters['search'] ?? '',
                            ])"
                            label="Export History"
                        />
                    </div>
                    <form id="nurse-history-filter-form" method="GET" action="{{ route('nurse.consultation_history', ['nurse' => $nurse->user_id]) }}" class="mb-6 rounded-2xl border border-gray-200 bg-slate-50 p-4">
                        <div class="grid gap-4 sm:grid-cols-4">
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
                            <div>
                                <label for="search" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Search Patient or Physician</label>
                                <input id="search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Enter patient or physician name" class="mt-2 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-green-100" />
                            </div>
                        </div>
                        <div class="mt-4 flex items-center gap-2">
                            <x-button-primary type="submit">Apply</x-button-primary>
                            <x-button-secondary href="{{ route('nurse.consultation_history', ['nurse' => $nurse->user_id]) }}">Reset</x-button-secondary>
                        </div>
                    </form>

                    <div id="nurse-history-results">
                        @include('nurse.partials.consultation_history_table', ['historyConsultations' => $historyConsultations])
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
