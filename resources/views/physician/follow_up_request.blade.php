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

            $forwardedFollowUpPayload = collect($forwardedRequests)->map(function ($followUp) use ($physician, $medicalFiles) {
                $originalSession = $followUp->consultation;
                $originalRequest = optional($originalSession)->request;

                return [
                    'id' => $followUp->id,
                    'patient_name' => trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient',
                    'reason' => $followUp->reason,
                    'decide_url' => route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                    'available_slots_url' => route('physician.follow_up_requests.available_slots', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                    // Mirrors nurse/follow_up_requests.blade.php's payload shape
                    // exactly, so the "Original Consultation Details" modal is
                    // the same component/markup on both sides.
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
            window.forwardedFollowUpRequests = @json($forwardedFollowUpPayload);

        function physicianFollowUpRequests(initialRequests) {
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
                        url: requestItem.decide_url,
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
                rejectRequest(requestItem) {
                    Swal.fire({
                        title: 'Reject Follow-up Request',
                        text: 'Please provide a reason for rejection.',
                        icon: 'warning',
                        input: 'textarea',
                        inputPlaceholder: 'Type rejection reason here...',
                        inputAttributes: {
                            'aria-label': 'Type rejection reason here'
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
                            decision: 'rejected',
                            decision_notes: result.value,
                        });
                    });
                },
                startFollowUpNow(requestItem) {
                    this.submitDecision(requestItem, {
                        decision: 'approved',
                        mode: 'immediate',
                    });
                },
                approveScheduled(requestItem) {
                    $.ajax({
                        url: requestItem.available_slots_url,
                        type: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        dataType: 'json',
                        success: (data) => {
                            const slots = Array.isArray(data?.slots) ? data.slots : [];

                            if (!slots.length) {
                                Swal.fire({
                                    title: 'No Available Slots',
                                    text: 'Create available schedule slots first.',
                                    icon: 'info',
                                    showCancelButton: true,
                                    confirmButtonText: 'Go To Schedule Slots',
                                    cancelButtonText: 'Close'
                                }).then((result) => {
                                    if (result.isConfirmed && data?.manage_schedule_url) {
                                        window.location.href = data.manage_schedule_url;
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
                                title: 'Select Schedule Slot',
                                input: 'select',
                                inputOptions: options,
                                inputPlaceholder: 'Select an available slot',
                                showCancelButton: true,
                                confirmButtonText: 'Approve & Schedule',
                                inputValidator: (value) => {
                                    if (!value) {
                                        return 'Please select a slot.';
                                    }
                                }
                            }).then((slotResult) => {
                                if (!slotResult.isConfirmed) {
                                    return;
                                }

                                this.submitDecision(requestItem, {
                                    decision: 'approved',
                                    mode: 'scheduled',
                                    slot_id: Number(slotResult.value),
                                });
                            });
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to load available slots.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                }
            };
        }
    </script>

    <div class="py-10" x-data="physicianFollowUpRequests(window.forwardedFollowUpRequests)" @keydown.escape.window="previewFile ? closeAttachmentPreview() : closeDetails()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-sm text-slate-600">Review forwarded follow-up requests and either reject or approve with immediate start or schedule slot selection.</p>
                </div>

                {{-- Phase 3: 3 columns, but the actions cell holds three
                     buttons with long labels ("Approve & Start Now",
                     "Approve & Schedule", "Reject") — the least compressible
                     part of any table on this page, and unusable at
                     375/390px. Mobile gets a card per request, calling the
                     exact same Alpine methods with the same data object as
                     the desktop row — no duplicated decision logic. --}}
                @if($forwardedRequests->isNotEmpty())
                    <div class="space-y-3 p-4 sm:hidden">
                        @foreach($forwardedRequests as $followUp)
                            @php
                                $patientName = trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient';
                                $isOnline = optional($followUp->patient)->online_status === 'online'
                                    && optional($followUp->patient)->last_seen_at
                                    && $followUp->patient->last_seen_at->gt(now()->subMinutes(2));
                            @endphp
                            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="inline-block h-[0.625em] w-[0.625em] shrink-0 rounded-full {{ $isOnline ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
                                    <p class="truncate text-sm font-medium text-slate-900">{{ $patientName }}</p>
                                </div>
                                <p class="mt-2 text-sm text-slate-700">{{ $followUp->reason }}</p>
                                {{-- Schedule/Start/Reject live in the details modal now, next
                                     to the record they're deciding on — not blind from the card. --}}
                                <x-button-secondary class="mt-4 w-full" @click="openDetails(requests.find((r) => r.id === {{ $followUp->id }}))">
                                    {{ __('View Consultation Details') }}
                                </x-button-secondary>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="p-6 text-center text-sm text-slate-500 sm:hidden">No forwarded follow-up requests to review.</p>
                @endif

                <div class="hidden overflow-x-auto sm:block">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Patient</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Reason</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @forelse($forwardedRequests as $followUp)
                                @php
                                    $patientName = trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient';
                                @endphp
                                <tr>
                                    <td class="px-6 py-4 text-sm text-slate-900">
                                        <span class="inline-flex items-center gap-2">
                                            @if(
                                                optional($followUp->patient)->online_status === 'online'
                                                && optional($followUp->patient)->last_seen_at
                                                && $followUp->patient->last_seen_at->gt(now()->subMinutes(2))
                                            )
                                                <span class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0 bg-emerald-500" title="Online"></span>
                                            @else
                                                <span class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0 bg-slate-300" title="Offline"></span>
                                            @endif
                                            {{ $patientName }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700 max-w-md">
                                        {{ $followUp->reason }}
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        {{-- Schedule/Start/Reject live in the details modal now, next
                                             to the record they're deciding on — not blind from the row. --}}
                                        <x-button-secondary size="sm" @click="openDetails(requests.find((r) => r.id === {{ $followUp->id }}))">
                                            {{ __('Details') }}
                                        </x-button-secondary>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-10 text-center text-sm text-slate-500">No forwarded follow-up requests to review.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Original consultation details modal — same design as the nurse
             follow-up page and the two Consultation Details modals. --}}
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
            aria-labelledby="physician-original-consultation-details-heading"
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
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 bg-white px-4 py-3 sm:px-5 sm:py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-brand-green-deep text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6" />
                            </svg>
                        </div>
                        <div>
                            <h3 id="physician-original-consultation-details-heading" class="text-base font-bold text-gray-900 sm:text-lg">{{ __('Original Consultation Details') }}</h3>
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
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Request Status') }}</p>
                            <p class="mt-1.5 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="requestStatusBadgeClass(selectedDetails?.details?.request_status)" x-text="selectedDetails?.details?.request_status ? selectedDetails.details.request_status.charAt(0).toUpperCase() + selectedDetails.details.request_status.slice(1) : '{{ __('N/A') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Priority Level') }}</p>
                            <p class="mt-1.5 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="priorityBadgeClass(selectedDetails?.details?.priority_level)" x-text="selectedDetails?.details?.priority_level ? selectedDetails.details.priority_level.charAt(0).toUpperCase() + selectedDetails.details.priority_level.slice(1) : '{{ __('Not Set') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Assigned Physician') }}</p>
                            <p class="mt-1.5 text-sm font-medium text-gray-900" x-text="selectedDetails?.details?.assigned_physician_name || '{{ __('Unassigned') }}'"></p>
                        </div>
                    </div>

                    {{-- Symptoms through Follow-up Reason are all facets of
                         the same record, not unrelated content — grouped
                         into one panel instead of six identical boxes
                         stacked with equal weight. --}}
                    <div class="overflow-hidden rounded-xl border border-gray-200 divide-y divide-gray-100">
                        <div class="p-3">
                            <div class="flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Symptoms') }}</p>
                            </div>
                            <template x-if="selectedDetails?.details?.symptoms_desc">
                                <ul class="mt-2 space-y-2 text-sm text-gray-900" x-html="formatSymptoms(selectedDetails?.details?.symptoms_desc)"></ul>
                            </template>
                            <p class="mt-2 text-sm text-gray-500" x-show="!selectedDetails?.details?.symptoms_desc">{{ __('No symptom details provided.') }}</p>
                        </div>

                        <div class="p-3">
                            <div class="flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                                </svg>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Reason for online consultation') }}</p>
                            </div>
                            <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.online_reason ?? '{{ __('N/A') }}'"></p>
                        </div>

                        <div class="p-3">
                            <div class="flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                </svg>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Additional Information') }}</p>
                            </div>
                            <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.details?.additional_information || '{{ __('No additional information provided.') }}'"></p>
                        </div>

                        <div class="p-3">
                            <div class="flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                </svg>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Attachments') }}</p>
                            </div>
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

                        <div class="p-3">
                            <div class="flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6" />
                                </svg>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Clinical Documentation') }}</p>
                            </div>
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

                        <div class="p-3">
                            <div class="flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <polyline points="17 1 21 5 17 9" />
                                    <path d="M3 11V9a4 4 0 0 1 4-4h14" />
                                    <polyline points="7 23 3 19 7 15" />
                                    <path d="M21 13v2a4 4 0 0 1-4 4H3" />
                                </svg>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Follow-up Reason') }}</p>
                            </div>
                            <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedDetails?.reason ?? '{{ __('N/A') }}'"></p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-5">
                    <button type="button" @click="closeDetails()" class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2">
                        {{ __('Close') }}
                    </button>
                    <x-button-danger @click="rejectRequest(selectedDetails)">
                        {{ __('Reject') }}
                    </x-button-danger>
                    <x-button-primary @click="approveScheduled(selectedDetails)">
                        {{ __('Approve & Schedule') }}
                    </x-button-primary>
                    <x-button-primary @click="startFollowUpNow(selectedDetails)">
                        {{ __('Approve & Start Now') }}
                    </x-button-primary>
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
                    <a :href="previewFile" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-brand-green hover:underline">
                        {{ __('Open in new tab') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>