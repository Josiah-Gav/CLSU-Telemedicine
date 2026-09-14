<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation Inbox') }}
        </h2>
    </x-slot>

    @php
        $isPatientOnline = fn ($patient) => $patient
            && $patient->online_status === 'online'
            && $patient->last_seen_at
            && $patient->last_seen_at->gt(now()->subMinutes(2));

        // Shared by every table's Severity column below and by their sm:hidden
        // card equivalents, so the desktop row and its mobile card can never
        // disagree on the highest-severity symptom's colour/label.
        $symptomSeverity = function ($symptomsData) {
            $highestSeverity = null;

            if (!empty($symptomsData) && is_array($symptomsData)) {
                $severityValues = collect($symptomsData)
                    ->map(fn ($item) => is_array($item) ? ($item['severity'] ?? null) : null)
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (int) $value)
                    ->all();

                if (!empty($severityValues)) {
                    $highestSeverity = max($severityValues);
                }
            }

            return match ($highestSeverity) {
                1 => ['class' => 'bg-green-100 text-green-800', 'label' => __('1 - Very Mild')],
                2 => ['class' => 'bg-yellow-100 text-yellow-800', 'label' => __('2 - Mild')],
                3 => ['class' => 'bg-orange-100 text-orange-800', 'label' => __('3 - Moderate')],
                4 => ['class' => 'bg-red-100 text-red-800', 'label' => __('4 - Severe')],
                default => ['class' => 'bg-gray-100 text-gray-700', 'label' => __('N/A')],
            };
        };

        $allInboxRequests = $pendingRequests
            ->concat($assignedToCurrentNurse)
            ->concat($assignedToOtherNurses)
            ->unique('request_id')
            ->values();

        $inboxRequestsJson = $allInboxRequests->map(function ($request) use ($isPatientOnline) {
            return [
                'request_id' => $request->request_id,
                'patient_id' => $request->patient_id,
                'patient_name' => trim(optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name) ?: 'Unknown Patient',
                'patient_is_online' => $isPatientOnline($request->patient),
                'submitted_at' => $request->submitted_at ? $request->submitted_at->format('M. j, Y g:i A') : null,
                'request_status' => $request->request_status,
                'assigned_nurse_id' => $request->assigned_nurse_id,
                'assigned_nurse_name' => trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: null,
                'assigned_physician_id' => $request->assigned_physician_id,
                'assigned_physician_name' => trim(optional($request->physician)->first_name . ' ' . optional($request->physician)->last_name) ?: null,
                'priority_level' => $request->priority_level,
                'symptoms_desc' => $request->symptoms_desc,
                'online_reason' => $request->online_reason,
                'additional_information' => $request->additional_information,
                'file_attachments' => array_map(function ($p) use ($request) {
                    return url('/consultations/' . $request->request_id . '/attachments/' . basename($p));
                }, $request->file_attachments ?? []),
            ];
        })->toArray();
    @endphp

    <script>
        window.inboxRequests = @json($inboxRequestsJson);
        window.inboxRefreshUrl = @json(route('nurse.consultation_inbox.refresh', ['nurse' => $nurse->user_id]));

        function consultationInbox(requests, pendingCount) {
            return {
                showModal: false,
                selectedRequest: null,
                previewFile: null,
                requests: requests,
                pendingCount: pendingCount,
                activeTab: 'pending',
                init() {
                    // Matches the navbar notification bell's 30s poll (see
                    // layouts/navigation.blade.php) rather than introducing
                    // a new live-update pattern. Reuses the pre-existing
                    // nurse.consultation_inbox.refresh endpoint — this page
                    // already reloads on approve/reject, so reloading here
                    // when a new pending request shows up is consistent
                    // with that, not a new pattern.
                    setInterval(() => this.checkForNewPending(), 30000);
                },
                checkForNewPending() {
                    if (this.showModal) {
                        return;
                    }

                    fetch(window.inboxRefreshUrl, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            if (Array.isArray(data.pendingRequests) && data.pendingRequests.length !== this.pendingCount) {
                                window.location.reload();
                            }
                        })
                        .catch(() => {
                            // Ignore a failed check; the next tick will try again.
                        });
                },
                setTab(tab) {
                    this.activeTab = tab;
                },
                openModal(requestId) {
                    this.selectedRequest = this.requests.find((request) => request.request_id === requestId);
                    this.showModal = true;
                },
                closeModal() {
                    this.showModal = false;
                    this.selectedRequest = null;
                },
                openAttachmentPreview(file) {
                    this.previewFile = file;
                },
                closeAttachmentPreview() {
                    this.previewFile = null;
                },
                formatSeverityLabel(severity) {
                    const labels = {
                        1: '1 - Very Mild',
                        2: '2 - Mild',
                        3: '3 - Moderate',
                        4: '4 - Severe',
                    };

                    return labels[severity] || 'N/A';
                },
                requestStatusBadgeClass(status) {
                    const classes = {
                        pending: 'text-orange-700 bg-orange-100',
                        assigned: 'text-yellow-700 bg-yellow-100',
                        scheduled: 'text-brand-green-deep bg-brand-gold-soft',
                        active: 'text-green-700 bg-green-100',
                        completed: 'text-green-900 bg-green-100',
                        cancelled: 'text-red-700 bg-red-100',
                    };

                    return classes[status] || 'text-gray-700 bg-gray-100';
                },
                priorityBadgeClass(priority) {
                    const classes = {
                        High: 'text-red-700 bg-red-100',
                        Normal: 'text-yellow-700 bg-yellow-100',
                    };

                    return classes[priority] || 'text-gray-700 bg-gray-100';
                },
                formatSymptoms(symptoms) {
                    if (!symptoms) return '';
                    if (Array.isArray(symptoms)) {
                        return symptoms.map((item) => {
                            const name = typeof item === 'object' ? (item.name ?? item['name'] ?? '') : item;
                            const severity = typeof item === 'object' ? (item.severity ?? item['severity'] ?? null) : null;
                            const startedDate = typeof item === 'object' ? (item.date ?? item['date'] ?? null) : null;
                            const startedTime = typeof item === 'object' ? (item.time ?? item['time'] ?? null) : null;
                            let severityClass = 'bg-slate-100 text-slate-700';

                            if (severity === 1) {
                                severityClass = 'bg-green-100 text-green-800';
                            } else if (severity === 2) {
                                severityClass = 'bg-yellow-100 text-yellow-800';
                            } else if (severity === 3) {
                                severityClass = 'bg-orange-100 text-orange-800';
                            } else if (severity === 4) {
                                severityClass = 'bg-red-100 text-red-800';
                            }

                            const severityBadge = severity !== null && severity !== undefined && severity !== ''
                                ? `<span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${severityClass}">${this.formatSeverityLabel(severity)}</span>`
                                : '';

                            const startedAt = [startedDate, startedTime].filter(Boolean).join(' ').trim();
                            const startedAtText = startedAt
                                ? `<p class="mt-1 w-full text-xs text-slate-500">Started: ${startedAt}</p>`
                                : `<p class="mt-1 w-full text-xs text-slate-400">Started: N/A</p>`;

                            return `<li class="flex items-center flex-wrap gap-2">${name}${severityBadge}${startedAtText}</li>`;
                        }).join('');
                    }

                    return `<li>${symptoms}</li>`;
                },
                rejectSelectedRequest() {
                    Swal.fire({
                        title: 'Reject Consultation Request?',
                        text: 'Please provide a reason for rejecting this consultation:',
                        icon: 'warning',
                        input: 'textarea',
                        inputPlaceholder: 'Type the rejection reason here...',
                        inputAttributes: {
                            'aria-label': 'Type your rejection reason here'
                        },
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Yes, reject it!',
                        cancelButtonText: 'Cancel',
                        inputValidator: (value) => {
                            if (!value) {
                                return 'You must provide a reason for rejection!';
                            }
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.rejectionConsultation(this.selectedRequest?.request_id, result.value);
                        }
                    });
                },
                approveSelectedRequest() {
                    Swal.fire({
                        title: 'Approve Consultation Request?',
                        text: 'Select a priority level before approving this consultation.',
                        icon: 'warning',
                        input: 'select',
                        // A blank first option, not an `inputValue` default —
                        // the dialog used to open with 'Normal' pre-selected,
                        // so a nurse who just clicked through without ever
                        // touching the dropdown silently triaged the request
                        // as Normal priority. inputValidator below already
                        // requires a truthy value; it only ever fires now
                        // that a real "nothing chosen" option exists.
                        inputOptions: {
                            '': 'Choose priority level...',
                            High: 'High',
                            Normal: 'Normal'
                        },
                        inputValue: '',
                        showCancelButton: true,
                        confirmButtonColor: '#10b981',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Approve',
                        inputValidator: (value) => {
                            if (!value) {
                                return 'You must select a priority level.';
                            }
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.approveConsultation(this.selectedRequest?.request_id, result.value);
                        }
                    });
                }
            };
        }
    </script>

    <div class="py-12" x-data="consultationInbox(window.inboxRequests, {{ $pendingRequests->count() }})" @keydown.escape.window="previewFile ? closeAttachmentPreview() : closeModal()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-6 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2">
                        <button
                            type="button"
                            @click="setTab('pending')"
                            :class="activeTab === 'pending' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('Pending') }} ({{ $pendingRequests->count() }})
                        </button>
                        <button
                            type="button"
                            @click="setTab('assigned')"
                            :class="activeTab === 'assigned' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('Assigned') }} ({{ $assignedToCurrentNurse->count() + $assignedToOtherNurses->count() }})
                        </button>
                    </div>

                    <div x-show="activeTab === 'pending'" x-cloak>
                        @if($pendingRequests->isEmpty())
                            <x-dash.empty message="No pending consultation requests found." />
                        @else
                            {{-- Below sm the table can only be read by scrolling sideways,
                                 so each row is repeated as a card. Same data and the same
                                 action as the table - only the layout differs, and exactly
                                 one of the two is ever visible. --}}
                            <div class="space-y-3 sm:hidden">
                                @foreach($pendingRequests as $request)
                                    @php($severity = $symptomSeverity($request->symptoms_desc))
                                    <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <div class="relative h-9 w-9 flex-shrink-0">
                                                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white">
                                                        {{ strtoupper(substr(optional($request->patient)->first_name ?? '?', 0, 1)) }}
                                                    </div>
                                                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($request->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                        <span class="sr-only">{{ $isPatientOnline($request->patient) ? __('Online') : __('Offline') }}</span>
                                                    </span>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-medium text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</p>
                                                    <p class="mt-0.5 text-xs text-gray-500">{{ __('Submitted') }} {{ $request->submitted_at ? $request->submitted_at->format('M. j, Y g:i A') : __('Unknown') }}</p>
                                                </div>
                                            </div>
                                            <x-dash.badge :status="$request->request_status" size="sm" />
                                        </div>

                                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $severity['class'] }}">
                                                {{ __('Severity') }}: {{ $severity['label'] }}
                                            </span>
                                        </div>

                                        <div class="mt-4">
                                            <x-button-primary class="w-full" @click="openModal({{ $request->request_id }})">
                                                {{ __('Review') }}
                                            </x-button-primary>
                                        </div>
                                    </article>
                                @endforeach
                            </div>

                            <div class="hidden overflow-hidden rounded-xl border border-gray-200 sm:block">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Severity') }}</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Submitted At') }}</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 bg-white">
                                            @foreach($pendingRequests as $request)
                                                <tr class="transition hover:bg-gray-50">
                                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                        <div class="flex items-center gap-3">
                                                            <div class="relative h-9 w-9 flex-shrink-0">
                                                                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white">
                                                                    {{ strtoupper(substr(optional($request->patient)->first_name ?? '?', 0, 1)) }}
                                                                </div>
                                                                <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($request->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                                    <span class="sr-only">{{ $isPatientOnline($request->patient) ? __('Online') : __('Offline') }}</span>
                                                                </span>
                                                            </div>
                                                            <span class="font-medium text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</span>
                                                        </div>
                                                    </td>
                                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                        @php($severity = $symptomSeverity($request->symptoms_desc))
                                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $severity['class'] }}">
                                                            {{ $severity['label'] }}
                                                        </span>
                                                    </td>
                                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $request->submitted_at ? $request->submitted_at->format('M. j, Y g:i A') : __('Unknown') }}</td>
                                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                        <x-dash.badge :status="$request->request_status" size="sm" />
                                                    </td>
                                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                        <x-button-primary size="sm" @click="openModal({{ $request->request_id }})">
                                                            {{ __('Review') }}
                                                        </x-button-primary>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div x-show="activeTab === 'assigned'" x-cloak class="space-y-6">
                        <div>
                            <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-brand-green-deep">
                                <span class="h-2 w-2 rounded-full bg-brand-green"></span>
                                {{ __('Assigned To Me') }}
                            </h3>
                            @if($assignedToCurrentNurse->isEmpty())
                                <div class="mt-3">
                                    <x-dash.empty message="No consultations are currently assigned to you." />
                                </div>
                            @else
                                {{-- Below sm the table can only be read by scrolling sideways,
                                     so each row is repeated as a card. Same data and the same
                                     two actions as the table - only the layout differs, and
                                     exactly one of the two is ever visible. --}}
                                <div class="mt-3 space-y-3 sm:hidden">
                                    @foreach($assignedToCurrentNurse as $request)
                                        @php($severity = $symptomSeverity($request->symptoms_desc))
                                        <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="flex min-w-0 items-center gap-3">
                                                    <div class="relative h-9 w-9 flex-shrink-0">
                                                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white">
                                                            {{ strtoupper(substr(optional($request->patient)->first_name ?? '?', 0, 1)) }}
                                                        </div>
                                                        <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($request->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                            <span class="sr-only">{{ $isPatientOnline($request->patient) ? __('Online') : __('Offline') }}</span>
                                                        </span>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <p class="truncate text-sm font-medium text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</p>
                                                        <p class="mt-0.5 text-xs text-gray-500">{{ __('Submitted') }} {{ $request->submitted_at ? $request->submitted_at->format('M. j, Y g:i A') : __('Unknown') }}</p>
                                                    </div>
                                                </div>
                                                <x-dash.badge :priority="$request->priority_level" size="sm" />
                                            </div>

                                            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                                <x-dash.badge :status="$request->request_status" size="sm" />
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $severity['class'] }}">
                                                    {{ __('Severity') }}: {{ $severity['label'] }}
                                                </span>
                                            </div>

                                            <div class="mt-4">
                                                <x-button-primary class="w-full" @click="openModal({{ $request->request_id }})">
                                                    {{ __('Review') }}
                                                </x-button-primary>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>

                                <div class="mt-3 hidden overflow-hidden rounded-xl border border-gray-200 sm:block">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Severity') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Submitted At') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Priority') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 bg-white">
                                                @foreach($assignedToCurrentNurse as $request)
                                                    <tr class="transition hover:bg-gray-50">
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                            <div class="flex items-center gap-3">
                                                                <div class="relative h-9 w-9 flex-shrink-0">
                                                                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white">
                                                                        {{ strtoupper(substr(optional($request->patient)->first_name ?? '?', 0, 1)) }}
                                                                    </div>
                                                                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($request->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                                        <span class="sr-only">{{ $isPatientOnline($request->patient) ? __('Online') : __('Offline') }}</span>
                                                                    </span>
                                                                </div>
                                                                <span class="font-medium text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</span>
                                                            </div>
                                                        </td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                            @php($severity = $symptomSeverity($request->symptoms_desc))
                                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $severity['class'] }}">
                                                                {{ $severity['label'] }}
                                                            </span>
                                                        </td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $request->submitted_at ? $request->submitted_at->format('M. j, Y g:i A') : __('Unknown') }}</td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                            <x-dash.badge :status="$request->request_status" size="sm" />
                                                        </td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                            <x-dash.badge :priority="$request->priority_level" size="sm" />
                                                        </td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                            <x-button-primary size="sm" @click="openModal({{ $request->request_id }})">
                                                                {{ __('Review') }}
                                                            </x-button-primary>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div>
                            <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-700">
                                <span class="h-2 w-2 rounded-full bg-gray-300"></span>
                                {{ __('Assigned To Other Nurses') }}
                            </h3>
                            @if($assignedToOtherNurses->isEmpty())
                                <div class="mt-3">
                                    <x-dash.empty message="No consultations are assigned to other nurses right now." />
                                </div>
                            @else
                                {{-- Below sm the table can only be read by scrolling sideways,
                                     so each row is repeated as a card. Same data and the same
                                     action as the table - only the layout differs, and exactly
                                     one of the two is ever visible. --}}
                                <div class="mt-3 space-y-3 sm:hidden">
                                    @foreach($assignedToOtherNurses as $request)
                                        <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="flex min-w-0 items-center gap-3">
                                                    <div class="relative h-9 w-9 flex-shrink-0">
                                                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-400 text-sm font-semibold text-white">
                                                            {{ strtoupper(substr(optional($request->patient)->first_name ?? '?', 0, 1)) }}
                                                        </div>
                                                        <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($request->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                            <span class="sr-only">{{ $isPatientOnline($request->patient) ? __('Online') : __('Offline') }}</span>
                                                        </span>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <p class="truncate text-sm font-medium text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</p>
                                                        <p class="mt-0.5 text-xs text-gray-500">{{ __('Submitted') }} {{ $request->submitted_at ? $request->submitted_at->format('M. j, Y g:i A') : __('Unknown') }}</p>
                                                    </div>
                                                </div>
                                                <x-dash.badge :status="$request->request_status" size="sm" />
                                            </div>

                                            <p class="mt-3 text-xs text-gray-500">
                                                {{ __('Assigned Nurse') }}: {{ trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: __('Unassigned') }}
                                            </p>

                                            <div class="mt-4">
                                                <x-button-primary class="w-full" @click="openModal({{ $request->request_id }})">
                                                    {{ __('Review') }}
                                                </x-button-primary>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>

                                <div class="mt-3 hidden overflow-hidden rounded-xl border border-gray-200 sm:block">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Assigned Nurse') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Submitted At') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 bg-white">
                                                @foreach($assignedToOtherNurses as $request)
                                                    <tr class="transition hover:bg-gray-50">
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                            <div class="flex items-center gap-3">
                                                                <div class="relative h-9 w-9 flex-shrink-0">
                                                                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-400 text-sm font-semibold text-white">
                                                                        {{ strtoupper(substr(optional($request->patient)->first_name ?? '?', 0, 1)) }}
                                                                    </div>
                                                                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($request->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                                        <span class="sr-only">{{ $isPatientOnline($request->patient) ? __('Online') : __('Offline') }}</span>
                                                                    </span>
                                                                </div>
                                                                <span class="font-medium text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</span>
                                                            </div>
                                                        </td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: __('Unassigned') }}</td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $request->submitted_at ? $request->submitted_at->format('M. j, Y g:i A') : __('Unknown') }}</td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                            <x-dash.badge :status="$request->request_status" size="sm" />
                                                        </td>
                                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                            <x-button-primary size="sm" @click="openModal({{ $request->request_id }})">
                                                                {{ __('Review') }}
                                                            </x-button-primary>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            x-show="showModal"
            x-cloak
            @click.self="closeModal()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-3 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="consultation-details-heading"
        >
            <div
                x-effect="if (showModal) { $nextTick(() => $refs.modalCloseButton?.focus()); }"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="flex w-[80vw] h-[80vh] sm:h-[40vw] max-h-[90vh] max-w-[72rem] min-h-[22rem] min-w-[18rem] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5"
            >
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 bg-gradient-to-r from-brand-green-soft/60 via-white to-white px-4 py-3 sm:px-5 sm:py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-brand-green text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6" />
                            </svg>
                        </div>
                        <div>
                            <h3 id="consultation-details-heading" class="text-base font-bold text-gray-900 sm:text-lg">{{ __('Consultation Details') }}</h3>
                            <p class="text-xs text-gray-500 sm:text-sm" x-text="selectedRequest ? '{{ __('Reference') }} #' + selectedRequest.request_id : ''"></p>
                        </div>
                    </div>
                    <button
                        type="button"
                        x-ref="modalCloseButton"
                        @click="closeModal()"
                        class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2"
                    >
                        <span class="sr-only">{{ __('Close') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="flex-1 space-y-3 overflow-y-auto p-4 sm:p-5">
                    <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-brand-green text-lg font-semibold text-white" x-text="(selectedRequest?.patient_name || '?').charAt(0).toUpperCase()"></div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900" x-text="selectedRequest?.patient_name"></p>
                                <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs" :class="selectedRequest?.patient_is_online ? 'text-emerald-600' : 'text-gray-400'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="selectedRequest?.patient_is_online ? 'bg-emerald-500' : 'bg-gray-300'"></span>
                                    <span x-text="selectedRequest?.patient_is_online ? '{{ __('Online now') }}' : '{{ __('Offline') }}'"></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 text-xs font-medium text-gray-500 sm:text-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                            </svg>
                            <span x-text="selectedRequest?.submitted_at ?? '{{ __('Unknown') }}'"></span>
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Request Status') }}</p>
                            <p class="mt-1.5 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="requestStatusBadgeClass(selectedRequest?.request_status)" x-text="selectedRequest?.request_status ? selectedRequest.request_status.charAt(0).toUpperCase() + selectedRequest.request_status.slice(1) : '{{ __('N/A') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Priority Level') }}</p>
                            <p class="mt-1.5 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="priorityBadgeClass(selectedRequest?.priority_level)" x-text="selectedRequest?.priority_level ? selectedRequest.priority_level.charAt(0).toUpperCase() + selectedRequest.priority_level.slice(1) : '{{ __('Not Set') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text- gray-500">{{ __('Assigned Physician') }}</p>
                            <p class="mt-1.5 text-sm font-medium text-gray-900" x-text="selectedRequest?.assigned_physician_name || '{{ __('Unassigned') }}'"></p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Symptoms') }}</p>
                        <template x-if="selectedRequest?.symptoms_desc">
                            <ul class="mt-2 space-y-2 text-sm text-gray-900" x-html="formatSymptoms(selectedRequest?.symptoms_desc)"></ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedRequest?.symptoms_desc">{{ __('No symptom details provided.') }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Reason for online consultation') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedRequest?.online_reason ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Additional Information') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedRequest?.additional_information || '{{ __('No additional information provided.') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Attachments') }}</p>
                        <template x-if="selectedRequest?.file_attachments && selectedRequest.file_attachments.length">
                            <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <template x-for="file in selectedRequest.file_attachments" :key="file">
                                    <button
                                        type="button"
                                        @click="openAttachmentPreview(file)"
                                        class="group relative h-20 overflow-hidden rounded-xl border border-gray-200 bg-gray-100 shadow-sm transition hover:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2 sm:h-24"
                                    >
                                        <span class="sr-only" x-text="file.split('/').pop()"></span>
                                        <img
                                            :src="file"
                                            :alt="file.split('/').pop()"
                                            x-on:error="$el.style.display = 'none'; $el.nextElementSibling.style.display = 'flex';"
                                            class="h-full w-full object-cover transition group-hover:scale-105"
                                        >
                                        <div class="h-full w-full items-center justify-center p-1 text-center text-[10px] text-gray-400" style="display: none;">{{ __('Image unavailable') }}</div>
                                    </button>
                                </template>
                            </div>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedRequest?.file_attachments || !selectedRequest.file_attachments.length">{{ __('No attachments.') }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-5">
                    <x-button-secondary @click="closeModal()">
                        {{ __('Close') }}
                    </x-button-secondary>
                    <template x-if="selectedRequest?.request_status === 'pending'">
                        <x-button-danger @click="rejectSelectedRequest()">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {{ __('Reject') }}
                        </x-button-danger>
                    </template>
                    <template x-if="selectedRequest?.request_status === 'pending'">
                        <x-button-primary @click="approveSelectedRequest()">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {{ __('Approve') }}
                        </x-button-primary>
                    </template>
                </div>
            </div>
        </div>

        <div
            x-show="previewFile"
            x-cloak
            @click.self="closeAttachmentPreview()"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/80 p-3 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Attachment preview') }}"
        >
            <div
                x-effect="if (previewFile) { $nextTick(() => $refs.previewCloseButton?.focus()); }"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
            >
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                    <p class="truncate text-sm font-semibold text-gray-900" x-text="previewFile ? previewFile.split('/').pop() : ''"></p>
                    <button
                        type="button"
                        x-ref="previewCloseButton"
                        @click="closeAttachmentPreview()"
                        class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2"
                    >
                        <span class="sr-only">{{ __('Close') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="flex-1 overflow-auto bg-gray-100 p-2 sm:p-4">
                    <img :src="previewFile" :alt="previewFile ? previewFile.split('/').pop() : ''" class="mx-auto max-h-[70vh] w-auto max-w-full rounded-lg object-contain">
                </div>
                <div class="flex justify-end border-t border-gray-200 px-4 py-3">
                    <a :href="previewFile" target="_blank" class="text-sm font-semibold text-brand-green hover:underline">
                        {{ __('Open in new tab') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.rejectionConsultation = function(consultationId, reason) {
            if (!consultationId) {
                Swal.fire('Error', 'Missing consultation request ID. Please reopen the request and try again.', 'error');
                return;
            }

            const csrfToken = $('meta[name="csrf-token"]').attr('content');

            if (!csrfToken) {
                console.error('CSRF token not found. Please ensure <meta name="csrf-token" content="{{ csrf_token() }}"> is in your <head>.');
                return;
            }

            $.ajax({
                url: `/consultations/${consultationId}/reject`,
                type: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                data: JSON.stringify({
                    rejection_reason: reason
                }),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        Swal.fire({
                            title: 'Rejected!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Something went wrong.', 'error');
                    }
                },
                error: function(xhr) {
                    let errorMessage = 'Could not connect to the server. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }

                    Swal.fire('Error', errorMessage, 'error');
                }
            });
        };
    </script>

    <script>
        window.approveConsultation = function(consultationId, priorityLevel) {
            if (!consultationId) {
                Swal.fire('Error', 'Missing consultation request ID. Please reopen the request and try again.', 'error');
                return;
            }

            if (!priorityLevel || !['High', 'Normal'].includes(priorityLevel)) {
                Swal.fire('Error', 'Please select a valid priority level before approving.', 'error');
                return;
            }

            const csrfToken = $('meta[name="csrf-token"]').attr('content');

            if (!csrfToken) {
                console.error('CSRF token not found. Please ensure <meta name="csrf-token" content="{{ csrf_token() }}"> is in your <head>.');
                return;
            }

            $.ajax({
                url: `/consultations/${consultationId}/approve`,
                type: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                data: JSON.stringify({
                    priority_level: priorityLevel
                }),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        Swal.fire({
                            title: 'Approved!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Something went wrong.', 'error');
                    }
                },
                error: function(xhr) {
                    let errorMessage = 'Could not connect to the server. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }

                    Swal.fire('Error', errorMessage, 'error');
                }
            });
        };
    </script>
</x-app-layout>
