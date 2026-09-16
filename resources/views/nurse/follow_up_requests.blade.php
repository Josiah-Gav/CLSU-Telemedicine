<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Follow-up Requests') }}
        </h2>
    </x-slot>

    @php
        // Resolved once per render rather than per attachment: the service is
        // stateless and this block builds the whole page payload in one pass.
        $medicalFiles = app(\App\Services\MedicalFileStorage::class);
        $isPatientOnline = fn ($patient) => $patient
            && $patient->online_status === 'online'
            && $patient->last_seen_at
            && $patient->last_seen_at->gt(now()->subMinutes(2));

        $pendingFollowUpPayload = collect($pendingRequests)->map(function ($followUp) use ($nurse, $isPatientOnline, $medicalFiles) {
            $originalSession = $followUp->consultation;
            $originalRequest = optional($originalSession)->request;
            $patientName = trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient';

            return [
                'id' => $followUp->id,
                'patient_name' => $patientName,
                'patient_is_online' => $isPatientOnline($followUp->patient),
                'original_consultation' => $originalRequest->concern_category ?? 'Completed Consultation',
                'requested_at' => $followUp->created_at ? $followUp->created_at->format('M. j, Y g:i A') : null,
                'reason' => $followUp->reason,
                'forward_url' => route('nurse.follow_up_requests.forward', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]),
                'reject_url' => route('nurse.follow_up_requests.reject', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]),
                'details' => [
                    'submitted_at' => optional($originalRequest?->submitted_at)->format('M. j, Y g:i A'),
                    'request_status' => $originalRequest->request_status ?? null,
                    'priority_level' => $originalRequest->priority_level ?? null,
                    'assigned_physician_name' => trim((optional($originalSession?->physician)->first_name ?? '') . ' ' . (optional($originalSession?->physician)->last_name ?? '')) ?: null,
                    'symptoms_desc' => $originalRequest->symptoms_desc ?? null,
                    'online_reason' => $originalRequest->online_reason ?? null,
                    'additional_information' => $originalRequest->additional_information ?? null,
                    'file_attachments' => $originalRequest
                        ? array_map(fn ($reference) => url('/consultations/' . $originalRequest->request_id . '/attachments/' . $medicalFiles->attachmentKey($reference)), $originalRequest->file_attachments ?? [])
                        : [],
                    'diagnosis' => $originalSession?->hasDiagnosis() ? $originalSession->diagnosis : null,
                    'assessment' => $originalSession?->hasMeaningfulAssessment() ? $originalSession->assessment : null,
                    'plan' => $originalSession?->hasMeaningfulPlan() ? $originalSession->plan : null,
                    'recommendations' => $originalSession?->hasMeaningfulRecommendations() ? $originalSession->recommendations : null,
                ],
            ];
        })->values();
    @endphp

    <script>
        window.pendingFollowUpRequests = @json($pendingFollowUpPayload);

        function nurseFollowUpRequests(initialRequests) {
            return {
                requests: initialRequests || [],
                showDetailsModal: false,
                selectedDetails: null,
                previewFile: null,
                openDetails(requestItem) {
                    this.selectedDetails = requestItem;
                    this.showDetailsModal = true;
                },
                closeDetails() {
                    this.showDetailsModal = false;
                    this.selectedDetails = null;
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
                        reviewed: 'text-yellow-700 bg-yellow-100',
                        scheduled: 'text-brand-green-deep bg-brand-gold-soft',
                        active: 'text-green-700 bg-green-100',
                        completed: 'text-green-900 bg-green-100',
                        cancelled: 'text-red-700 bg-red-100',
                        rejected: 'text-red-700 bg-red-100',
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
                submitDecision(requestItem, payload) {
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: payload.action === 'forward' ? requestItem.forward_url : requestItem.reject_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        data: JSON.stringify(payload),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire('Success', data.message, 'success').then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Unable to process follow-up request.', 'error');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to process follow-up request.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                forwardRequest(requestItem) {
                    Swal.fire({
                        title: 'Forward Follow-up Request',
                        text: 'Add optional screening notes before forwarding to the physician.',
                        icon: 'question',
                        input: 'textarea',
                        inputPlaceholder: 'Optional nurse screening notes...',
                        inputAttributes: {
                            'aria-label': 'Optional nurse screening notes'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Forward',
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.submitDecision(requestItem, {
                            action: 'forward',
                            decision_notes: result.value || null,
                        });
                    });
                },
                rejectRequest(requestItem) {
                    Swal.fire({
                        title: 'Reject Follow-up Request',
                        text: 'Please provide a reason for rejection.',
                        icon: 'warning',
                        input: 'textarea',
                        inputPlaceholder: 'Required rejection reason...',
                        inputAttributes: {
                            'aria-label': 'Required rejection reason'
                        },
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Reject',
                        inputValidator: (value) => {
                            if (!value) {
                                return 'A rejection reason is required.';
                            }
                        }
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.submitDecision(requestItem, {
                            action: 'reject',
                            decision_notes: result.value,
                        });
                    });
                }
            };
        }
    </script>

    <div class="py-10" x-data="nurseFollowUpRequests(window.pendingFollowUpRequests)" @keydown.escape.window="previewFile ? closeAttachmentPreview() : closeDetails()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p class="mb-4 text-sm text-gray-600">{{ __('Review patient follow-up requests and either forward to physician review or reject with a reason.') }}</p>

                    @if($pendingRequests->isEmpty())
                        <x-dash.empty message="No pending follow-up requests to review." />
                    @else
                        {{-- Phase 3: this table has 5 columns including a
                             3-button action group, which cannot fit at
                             375/390px without either clipping the actions or
                             forcing page-level horizontal scroll. Mobile gets
                             a card per request instead, reusing the exact
                             same $pendingFollowUpPayload data and the same
                             Alpine methods (openDetails/forwardRequest/
                             rejectRequest) the desktop table calls — no
                             duplicated business logic, just a second
                             presentation of the same loop. Pattern matches
                             the established one in
                             nurse/consultation_inbox.blade.php. --}}
                        <div class="space-y-3 sm:hidden">
                            @foreach($pendingRequests as $index => $followUp)
                                @php $patientName = $pendingFollowUpPayload[$index]['patient_name']; @endphp
                                <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <div class="relative h-9 w-9 flex-shrink-0">
                                                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white">
                                                    {{ strtoupper(substr($patientName, 0, 1)) }}
                                                </div>
                                                <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($followUp->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                    <span class="sr-only">{{ $isPatientOnline($followUp->patient) ? __('Online') : __('Offline') }}</span>
                                                </span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium text-gray-900">{{ $patientName }}</p>
                                                <p class="mt-0.5 text-xs text-gray-500">{{ __('Requested') }} {{ $followUp->created_at ? $followUp->created_at->format('M. j, Y g:i A') : __('Unknown') }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Reason') }}</p>
                                        <p class="mt-1 text-sm text-gray-700">{{ $followUp->reason }}</p>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1">
                                            {{ __('Physician') }}: {{ $pendingFollowUpPayload[$index]['details']['assigned_physician_name'] ?? __('Unassigned') }}
                                        </span>
                                    </div>

                                    <div class="mt-4 flex flex-col gap-2">
                                        <x-button-primary class="w-full" @click="forwardRequest(requests.find((r) => r.id === {{ $followUp->id }}))">
                                            {{ __('Forward') }}
                                        </x-button-primary>
                                        <div class="flex gap-2">
                                            <x-button-secondary class="flex-1" @click="openDetails(requests.find((r) => r.id === {{ $followUp->id }}))">
                                                {{ __('Details') }}
                                            </x-button-secondary>
                                            <x-button-danger class="flex-1" @click="rejectRequest(requests.find((r) => r.id === {{ $followUp->id }}))">
                                                {{ __('Reject') }}
                                            </x-button-danger>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <div class="hidden overflow-hidden rounded-xl border border-gray-200 sm:block">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Assigned Physician') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Requested') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Reason') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white">
                                        @foreach($pendingRequests as $index => $followUp)
                                            @php $patientName = $pendingFollowUpPayload[$index]['patient_name']; @endphp
                                            <tr class="transition hover:bg-gray-50">
                                                <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                    <div class="flex items-center gap-3">
                                                        <div class="relative h-9 w-9 flex-shrink-0">
                                                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white">
                                                                {{ strtoupper(substr($patientName, 0, 1)) }}
                                                            </div>
                                                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline($followUp->patient) ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                                <span class="sr-only">{{ $isPatientOnline($followUp->patient) ? __('Online') : __('Offline') }}</span>
                                                            </span>
                                                        </div>
                                                        <span class="font-medium text-gray-900">{{ $patientName }}</span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-700">
                                                    {{ $pendingFollowUpPayload[$index]['details']['assigned_physician_name'] ?? __('Unassigned') }}
                                                </td>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                    {{ $followUp->created_at ? $followUp->created_at->format('M. j, Y g:i A') : __('Unknown') }}
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-700 max-w-xs">
                                                    <p class="truncate" title="{{ $followUp->reason }}">{{ $followUp->reason }}</p>
                                                </td>
                                                <td class="px-6 py-4 text-sm">
                                                    <div class="flex flex-wrap gap-2">
                                                        <x-button-secondary size="sm" @click="openDetails(requests.find((r) => r.id === {{ $followUp->id }}))">
                                                            {{ __('Details') }}
                                                        </x-button-secondary>
                                                        <x-button-primary size="sm" @click="forwardRequest(requests.find((r) => r.id === {{ $followUp->id }}))">
                                                            {{ __('Forward') }}
                                                        </x-button-primary>
                                                        <x-button-danger size="sm" @click="rejectRequest(requests.find((r) => r.id === {{ $followUp->id }}))">
                                                            {{ __('Reject') }}
                                                        </x-button-danger>
                                                    </div>
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

        {{-- Original consultation details modal --}}
        <div
            x-show="showDetailsModal"
            x-cloak
            @click.self="closeDetails()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-3 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="original-consultation-details-heading"
        >
            <div
                x-effect="if (showDetailsModal) { $nextTick(() => $refs.detailsCloseButton?.focus()); }"
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
                            <h3 id="original-consultation-details-heading" class="text-base font-bold text-gray-900 sm:text-lg">{{ __('Original Consultation Details') }}</h3>
                            <p class="text-xs text-gray-500 sm:text-sm" x-text="selectedDetails ? '{{ __('Follow-up request') }} #' + selectedDetails.id : ''"></p>
                        </div>
                    </div>
                    <button
                        type="button"
                        x-ref="detailsCloseButton"
                        @click="closeDetails()"
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
                            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-brand-green text-lg font-semibold text-white" x-text="(selectedDetails?.patient_name || '?').charAt(0).toUpperCase()"></div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900" x-text="selectedDetails?.patient_name"></p>
                                <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs" :class="selectedDetails?.patient_is_online ? 'text-emerald-600' : 'text-gray-400'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="selectedDetails?.patient_is_online ? 'bg-emerald-500' : 'bg-gray-300'"></span>
                                    <span x-text="selectedDetails?.patient_is_online ? '{{ __('Online now') }}' : '{{ __('Offline') }}'"></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 text-xs font-medium text-gray-500 sm:text-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                            </svg>
                            <span x-text="selectedDetails?.details?.submitted_at ?? '{{ __('Unknown') }}'"></span>
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Request Status') }}</p>
                            <p class="mt-1.5 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="requestStatusBadgeClass(selectedDetails?.details?.request_status)" x-text="selectedDetails?.details?.request_status ? selectedDetails.details.request_status.charAt(0).toUpperCase() + selectedDetails.details.request_status.slice(1) : '{{ __('N/A') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Priority Level') }}</p>
                            <p class="mt-1.5 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="priorityBadgeClass(selectedDetails?.details?.priority_level)" x-text="selectedDetails?.details?.priority_level ? selectedDetails.details.priority_level.charAt(0).toUpperCase() + selectedDetails.details.priority_level.slice(1) : '{{ __('Not Set') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Assigned Physician') }}</p>
                            <p class="mt-1.5 text-sm font-medium text-gray-900" x-text="selectedDetails?.details?.assigned_physician_name || '{{ __('Unassigned') }}'"></p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Symptoms') }}</p>
                        <template x-if="selectedDetails?.details?.symptoms_desc">
                            <ul class="mt-2 space-y-2 text-sm text-gray-900" x-html="formatSymptoms(selectedDetails?.details?.symptoms_desc)"></ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedDetails?.details?.symptoms_desc">{{ __('No symptom details provided.') }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Reason for online consultation') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.online_reason ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Additional Information') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.additional_information || '{{ __('No additional information provided.') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Attachments') }}</p>
                        <template x-if="selectedDetails?.details?.file_attachments && selectedDetails.details.file_attachments.length">
                            <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <template x-for="file in selectedDetails.details.file_attachments" :key="file">
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
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedDetails?.details?.file_attachments || !selectedDetails.details.file_attachments.length">{{ __('No attachments.') }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Clinical Documentation') }}</p>
                        <dl class="mt-2 space-y-3">
                            <div>
                                <dt class="text-xs font-semibold text-gray-600">{{ __('Diagnosis') }}</dt>
                                <dd class="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.diagnosis || '{{ __('Not documented.') }}'"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-600">{{ __('Assessment') }}</dt>
                                <dd class="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.assessment || '{{ __('Not documented.') }}'"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-600">{{ __('Plan') }}</dt>
                                <dd class="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.plan || '{{ __('Not documented.') }}'"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-600">{{ __('Recommendations') }}</dt>
                                <dd class="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.recommendations || '{{ __('Not documented.') }}'"></dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Follow-up Reason') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.reason ?? '{{ __('N/A') }}'"></p>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-5">
                    <button type="button" @click="closeDetails()" class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Attachment preview lightbox --}}
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
</x-app-layout>
