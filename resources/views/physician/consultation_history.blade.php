<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation History') }}
        </h2>
    </x-slot>

    <script>
        function scheduleFollowUpFromHistory(button) {
            const consultationId = button.getAttribute('data-consultation-id');
            const physicianId = button.getAttribute('data-physician-id');
            const followUpUrl = button.getAttribute('data-follow-up-url');
            const slotsUrl = button.getAttribute('data-slots-url');
            const csrfTokenElement = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = csrfTokenElement ? csrfTokenElement.getAttribute('content') : '';

            if (!consultationId || !physicianId || !followUpUrl || !slotsUrl || !csrfToken) {
                Swal.fire('Error', 'Unable to start follow-up scheduling.', 'error');
                return;
            }

            const loadSlots = () => {
                fetch(slotsUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                })
                    .then((response) => response.json())
                    .then((data) => {
                        const slots = Array.isArray(data?.slots) ? data.slots : [];

                        if (!slots.length) {
                            Swal.fire({
                                title: 'No Available Slots',
                                text: 'Create available schedule slots first before scheduling a follow-up.',
                                icon: 'info',
                                showCancelButton: true,
                                confirmButtonText: 'Go To Schedule Slots',
                                cancelButtonText: 'Close',
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = data?.manage_schedule_url || '/physicians/' + physicianId + '/scheduled_consultation';
                                }
                            });
                            return;
                        }

                        const options = slots.reduce((carry, slot) => {
                            const slotDate = new Date(`${slot.slot_date}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                            carry[String(slot.slot_id)] = `${slot.label}, ${slotDate}`;
                            return carry;
                        }, {});

                        Swal.fire({
                            title: 'Schedule Follow-up',
                            text: 'Choose a slot and provide the physician decision notes before scheduling.',
                            html: `
                                <div class="text-left">
                                    <label class="mb-2 block text-sm font-semibold text-slate-700" for="follow-up-slot">Select Schedule Slot</label>
                                    <select id="follow-up-slot" class="swal2-input">
                                        <option value="">Select a slot</option>
                                        ${Object.entries(options).map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}
                                    </select>
                                    <label class="mt-3 mb-2 block text-sm font-semibold text-slate-700" for="follow-up-notes">Decision Notes</label>
                                    <textarea id="follow-up-notes" class="swal2-textarea" placeholder="Enter the physician decision notes..."></textarea>
                                </div>
                            `,
                            showCancelButton: true,
                            confirmButtonText: 'Schedule Follow-up',
                            preConfirm: () => {
                                const slotId = document.getElementById('follow-up-slot').value;
                                const decisionNotes = document.getElementById('follow-up-notes').value.trim();

                                if (!slotId) {
                                    Swal.showValidationMessage('Please select a schedule slot.');
                                    return false;
                                }

                                if (!decisionNotes) {
                                    Swal.showValidationMessage('Decision notes are required before scheduling the follow-up.');
                                    return false;
                                }

                                return {
                                    slot_id: slotId,
                                    decision_notes: decisionNotes,
                                };
                            },
                        }).then((result) => {
                            if (!result.isConfirmed || !result.value) {
                                return;
                            }

                            fetch(followUpUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrfToken,
                                },
                                body: JSON.stringify({
                                    mode: 'scheduled',
                                    slot_id: result.value.slot_id,
                                    decision_notes: result.value.decision_notes,
                                }),
                            })
                                .then((response) => response.json())
                                .then((data) => {
                                    if (data.success) {
                                        Swal.fire('Success', data.message || 'Follow-up scheduled successfully.', 'success').then(() => {
                                            window.location.reload();
                                        });
                                    } else {
                                        Swal.fire('Error', data.message || 'Unable to schedule follow-up.', 'error');
                                    }
                                })
                                .catch(() => {
                                    Swal.fire('Error', 'Unable to schedule follow-up.', 'error');
                                });
                        });
                    })
                    .catch(() => {
                        Swal.fire('Error', 'Unable to load available slots.', 'error');
                    });
            };

            loadSlots();
        }

        function initPhysicianHistoryLiveSearch() {
            const form = document.getElementById('physician-history-filter-form');
            const searchInput = document.getElementById('search');
            const resultsContainer = document.getElementById('physician-history-results');

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

        document.addEventListener('DOMContentLoaded', initPhysicianHistoryLiveSearch);
    </script>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-4 flex justify-end">
                        <x-dash.export-menu
                            route="physician.consultation_history.export"
                            :route-params="['physician' => $physician->user_id]"
                            :query-params="array_filter([
                                'date_filter' => $filters['date_filter'] ?? 'all',
                                'status' => $filters['status'] ?? 'all',
                                'consultation_type' => $filters['consultation_type'] ?? 'all',
                                'search' => $filters['search'] ?? '',
                            ])"
                            label="Export History"
                        />
                    </div>
                    <form id="physician-history-filter-form" method="GET" action="{{ route('physician.consultation_history', ['physician' => $physician->user_id]) }}" class="mb-6 rounded-2xl border border-gray-200 bg-slate-50 p-4">
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
                                <label for="search" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Search Patient or Nurse</label>
                                <input id="search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Enter patient or nurse name" class="mt-2 block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-green-100" />
                            </div>
                        </div>
                        <div class="mt-4 flex items-center gap-2">
                            <x-button-primary type="submit">Apply</x-button-primary>
                            <x-button-secondary href="{{ route('physician.consultation_history', ['physician' => $physician->user_id]) }}">Reset</x-button-secondary>
                        </div>
                    </form>

                    <div id="physician-history-results">
                        @include('physician.partials.consultation_history_table', ['historyConsultations' => $historyConsultations, 'physician' => $physician])
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>