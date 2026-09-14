<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation Inbox') }}
        </h2>
    </x-slot>

    <script>
        // Seeded from PhysicianController::serializeConsultations — the same
        // shape the refresh endpoint returns, so a polled update cannot change
        // a row's shape mid-flight. The two priority lists are merged here and
        // split again by the getters below, exactly as refreshInbox() does.
        window.assignedConsultations = @json(array_merge(
            $physicianInboxData['normalPriorityConsultations'],
            $physicianInboxData['highPriorityConsultations'],
        ));
        window.physicianInboxRefreshUrl = @json(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]));

        function physicianConsultationInbox(consultations) {
            return {
                activeTab: 'normal',
                showModal: false,
                selectedConsultation: null,
                previewFile: null,
                consultations: consultations,
                get normalPriorityConsultations() {
                    return this.consultations.filter((consultation) => consultation.priority_level === 'Normal');
                },
                get highPriorityConsultations() {
                    return this.consultations.filter((consultation) => consultation.priority_level === 'High');
                },
                // Both tabs render an identical table, so one table binds to
                // whichever list is active rather than duplicating the markup.
                get visibleConsultations() {
                    return this.activeTab === 'high'
                        ? this.highPriorityConsultations
                        : this.normalPriorityConsultations;
                },
                init() {
                    // Matches the nurse consultation inbox's poll interval
                    // (see nurse/consultation_inbox.blade.php) rather than
                    // introducing a new live-update pattern.
                    setInterval(() => this.refreshInbox(), 30000);
                },
                refreshInbox() {
                    if (this.showModal) {
                        return;
                    }

                    fetch(window.physicianInboxRefreshUrl, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            this.consultations = [
                                ...(data.normalPriorityConsultations || []),
                                ...(data.highPriorityConsultations || []),
                            ];
                        })
                        .catch(() => {
                            // Ignore a failed check; the next tick will try again.
                        });
                },
                /**
                 * Renders one badge from the tokens App\Support\StatusBadge
                 * serialized for this row — the same map the dash.badge component uses,
                 * so a physician row and a nurse row show identical colours.
                 * Every value here is a server-side constant, never user input.
                 */
                badgeHtml(token, size = 'sm') {
                    if (!token) {
                        return '';
                    }

                    const sizeClasses = size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs';
                    const icon = token.icon_path
                        ? `<svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${token.icon_path}" /></svg>`
                        : '';

                    return `<span class="inline-flex items-center gap-1 rounded-full font-semibold ${sizeClasses} ${token.classes}">${icon}${token.label}</span>`;
                },
                patientInitial(name) {
                    return (name || '?').charAt(0).toUpperCase();
                },
                openModal(requestId) {
                    this.selectedConsultation = this.consultations.find((consultation) => consultation.request_id === requestId);
                    this.showModal = true;
                },
                closeModal() {
                    this.showModal = false;
                    this.selectedConsultation = null;
                },
                openAttachmentPreview(file) {
                    this.previewFile = file;
                },
                closeAttachmentPreview() {
                    this.previewFile = null;
                },
                attachmentName(file) {
                    return decodeURIComponent(file.split('/').pop().split('?')[0]);
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
                startConsultation() {
                    if (!this.selectedConsultation?.start_url) {
                        Swal.fire('Error', 'Unable to find the start consultation URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.start_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken
                        },
                        data: JSON.stringify({
                            physician_id: {{ $physician->user_id }}
                        }),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire({
                                    title: 'Started!',
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
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Could not start the consultation.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                takeOverConsultation() {
                    if (!this.selectedConsultation?.takeover_url) {
                        Swal.fire('Error', 'Unable to find the takeover URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.takeover_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken
                        },
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire({
                                    title: 'Consultation Claimed',
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
                        // A 422 here is the normal outcome when someone else claimed it
                        // first, or the grace period has not elapsed on the server's
                        // clock — show the server's reason verbatim.
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Could not take over the consultation.';
                            Swal.fire('Unavailable', message, 'warning');
                        }
                    });
                },
                scheduleConsultation(slotId) {
                    if (!this.selectedConsultation?.schedule_url) {
                        Swal.fire('Error', 'Unable to find the schedule URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.schedule_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        data: JSON.stringify({
                            physician_id: {{ $physician->user_id }},
                            slot_id: Number(slotId)
                        }),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire('Scheduled!', data.message, 'success').then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Unable to schedule consultation.', 'error');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to schedule consultation.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                promptScheduleSlot() {
                    if (!this.selectedConsultation?.available_slots_url) {
                        Swal.fire('Error', 'Unable to load available slots.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.available_slots_url,
                        type: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        dataType: 'json',
                        success: (data) => {
                            const slots = Array.isArray(data?.slots) ? data.slots : [];

                            if (!slots.length) {
                                Swal.fire({
                                    title: 'No Available Slots Today',
                                    text: 'Create available slots for today first.',
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
                                carry[String(slot.slot_id)] = `${slot.label} (${slot.slot_date})`;
                                return carry;
                            }, {});

                            Swal.fire({
                                title: 'Select Schedule Slot',
                                input: 'select',
                                inputOptions: options,
                                inputPlaceholder: 'Select an available slot',
                                showCancelButton: true,
                                confirmButtonText: 'Assign Slot',
                                inputValidator: (value) => {
                                    if (!value) {
                                        return 'Please select a slot.';
                                    }
                                }
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    this.scheduleConsultation(result.value);
                                }
                            });
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to load available slots.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                rejectReviewedConsultation(rejectionReason) {
                    if (!this.selectedConsultation?.reject_url) {
                        Swal.fire('Error', 'Unable to find the reject URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.reject_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        data: JSON.stringify({
                            rejection_reason: rejectionReason
                        }),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire('Rejected!', data.message, 'success').then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Unable to reject consultation.', 'error');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to reject consultation.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                }
            };
        }
    </script>

    <div class="py-12" x-data="physicianConsultationInbox(window.assignedConsultations)" @keydown.escape.window="previewFile ? closeAttachmentPreview() : closeModal()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-6 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2">
                        <button
                            type="button"
                            @click="activeTab = 'normal'"
                            :class="activeTab === 'normal' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2"
                        >
                            {{ __('Normal Priority') }} (<span x-text="normalPriorityConsultations.length"></span>)
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'high'"
                            :class="activeTab === 'high' ? 'bg-red-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                        >
                            {{ __('High Priority') }} (<span x-text="highPriorityConsultations.length"></span>)
                        </button>
                    </div>

                    {{-- Empty-state markup mirrors the dash.empty component; it lives inline
                         because the surrounding table is Alpine-rendered. --}}
                    <div x-show="visibleConsultations.length === 0" x-cloak class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                        <p class="text-sm font-medium text-slate-500"
                           x-text="activeTab === 'high'
                                ? '{{ __('No assigned consultations with high priority.') }}'
                                : '{{ __('No assigned consultations with normal priority.') }}'"></p>
                    </div>

                    {{-- Below sm the seven-column table can only be read by scrolling
                         sideways, so each row is repeated as a card. Same data and the
                         same action as the table - only the layout differs, and exactly
                         one of the two is ever visible. --}}
                    <div x-show="visibleConsultations.length > 0" x-cloak class="space-y-3 sm:hidden">
                        <template x-for="consultation in visibleConsultations" :key="consultation.request_id">
                            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div class="relative h-9 w-9 flex-shrink-0">
                                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white"
                                                 x-text="patientInitial(consultation.patient_name)"></div>
                                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white"
                                                  :class="consultation.patient_is_online ? 'bg-emerald-500' : 'bg-gray-300'">
                                                <span class="sr-only" x-text="consultation.patient_is_online ? '{{ __('Online') }}' : '{{ __('Offline') }}'"></span>
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-gray-900" x-text="consultation.patient_name"></p>
                                            <p class="mt-0.5 text-xs text-gray-500" x-text="consultation.submitted_at ?? '{{ __('Unknown') }}'"></p>
                                        </div>
                                    </div>
                                    <div x-html="badgeHtml(consultation.priority_badge)"></div>
                                </div>

                                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                    <div x-html="badgeHtml(consultation.status_badge)"></div>
                                    <div x-html="badgeHtml(consultation.severity_badge)"></div>
                                </div>
                                <p x-show="consultation.takeover_available" x-cloak class="mt-1 text-[11px] font-semibold text-amber-700">{{ __('Takeover Available') }}</p>
                                <p x-show="!consultation.takeover_available && !consultation.is_assigned_to_me && consultation.assigned_physician_name"
                                   x-cloak
                                   class="mt-1 text-[11px] text-gray-500"
                                   x-text="'{{ __('Assigned to') }} ' + consultation.assigned_physician_name"></p>

                                <template x-if="consultation.scheduled_slot">
                                    <p class="mt-2 text-xs text-gray-500">
                                        <span x-text="consultation.scheduled_slot.label"></span>
                                        <span x-text="'· ' + consultation.scheduled_slot.slot_date"></span>
                                    </p>
                                </template>

                                <div class="mt-4">
                                    <button type="button" @click="openModal(consultation.request_id)"
                                            class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                        {{ __('Review') }}
                                    </button>
                                </div>
                            </article>
                        </template>
                    </div>

                    <div x-show="visibleConsultations.length > 0" x-cloak class="hidden overflow-hidden rounded-xl border border-gray-200 sm:block">
                        <div class="overflow-x-auto">
                            {{-- Column padding trimmed from px-6 to px-4, and
                                 the Scheduled Slot / Submitted At columns no
                                 longer force whitespace-nowrap, so their date
                                 text can wrap instead of setting the column's
                                 width — together this was enough to fit the
                                 table inside its container at 1280/1440px
                                 without scrolling. Actions stays sticky to
                                 the right edge as a backstop: the one action
                                 this table exists to offer must stay reachable
                                 even if a narrower window still needs the
                                 horizontal scroll below. --}}
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Severity') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Scheduled Slot') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Submitted At') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Priority') }}</th>
                                        <th class="sticky right-0 z-10 whitespace-nowrap border-l border-gray-200 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <template x-for="consultation in visibleConsultations" :key="consultation.request_id">
                                        <tr class="group transition hover:bg-gray-50">
                                            <td class="whitespace-nowrap px-4 py-4 text-sm">
                                                <div class="flex items-center gap-3">
                                                    <div class="relative h-9 w-9 flex-shrink-0">
                                                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white"
                                                             x-text="patientInitial(consultation.patient_name)"></div>
                                                        <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white"
                                                              :class="consultation.patient_is_online ? 'bg-emerald-500' : 'bg-gray-300'">
                                                            <span class="sr-only" x-text="consultation.patient_is_online ? '{{ __('Online') }}' : '{{ __('Offline') }}'"></span>
                                                        </span>
                                                    </div>
                                                    <span class="max-w-[10rem] truncate font-medium text-gray-900" x-text="consultation.patient_name"></span>
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-4 text-sm" x-html="badgeHtml(consultation.severity_badge)"></td>
                                            <td class="px-4 py-4 text-sm">
                                                <template x-if="consultation.scheduled_slot">
                                                    <div>
                                                        <p class="font-medium text-gray-900" x-text="consultation.scheduled_slot.label"></p>
                                                        <p class="text-xs text-gray-500" x-text="consultation.scheduled_slot.slot_date"></p>
                                                    </div>
                                                </template>
                                                <span x-show="!consultation.scheduled_slot" class="text-gray-400">&mdash;</span>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-500" x-text="consultation.submitted_at ?? '{{ __('Unknown') }}'"></td>
                                            <td class="whitespace-nowrap px-4 py-4 text-sm">
                                                <div x-html="badgeHtml(consultation.status_badge)"></div>
                                                <p x-show="consultation.takeover_available" x-cloak class="mt-1 text-[11px] font-semibold text-amber-700">{{ __('Takeover Available') }}</p>
                                                <p x-show="!consultation.takeover_available && !consultation.is_assigned_to_me && consultation.assigned_physician_name"
                                                   x-cloak
                                                   class="mt-1 text-[11px] text-gray-500"
                                                   x-text="'{{ __('Assigned to') }} ' + consultation.assigned_physician_name"></p>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-4 text-sm" x-html="badgeHtml(consultation.priority_badge)"></td>
                                            <td class="sticky right-0 z-10 whitespace-nowrap border-l border-gray-200 bg-white px-4 py-4 text-sm group-hover:bg-gray-50">
                                                <button type="button" @click="openModal(consultation.request_id)"
                                                        class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                                    {{ __('Review') }}
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
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
            aria-labelledby="physician-consultation-details-heading"
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
                            <h3 id="physician-consultation-details-heading" class="text-base font-bold text-gray-900 sm:text-lg">{{ __('Consultation Details') }}</h3>
                            <p class="text-xs text-gray-500 sm:text-sm" x-text="selectedConsultation ? '{{ __('Reference') }} #' + selectedConsultation.request_id : ''"></p>
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
                            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-brand-green text-lg font-semibold text-white"
                                 x-text="patientInitial(selectedConsultation?.patient_name)"></div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900" x-text="selectedConsultation?.patient_name"></p>
                                <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs" :class="selectedConsultation?.patient_is_online ? 'text-emerald-600' : 'text-gray-400'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="selectedConsultation?.patient_is_online ? 'bg-emerald-500' : 'bg-gray-300'"></span>
                                    <span x-text="selectedConsultation?.patient_is_online ? '{{ __('Online now') }}' : '{{ __('Offline') }}'"></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 text-xs font-medium text-gray-500 sm:text-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                            </svg>
                            <span x-text="selectedConsultation?.submitted_at ?? '{{ __('Unknown') }}'"></span>
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-4">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Request Status') }}</p>
                            <div class="mt-1.5" x-html="badgeHtml(selectedConsultation?.status_badge, 'md')"></div>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Priority Level') }}</p>
                            <div class="mt-1.5" x-html="badgeHtml(selectedConsultation?.priority_badge, 'md')"></div>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Assigned Nurse') }}</p>
                            <p class="mt-1.5 text-sm font-medium text-gray-900" x-text="selectedConsultation?.assigned_nurse_name || '{{ __('Unassigned') }}'"></p>
                        </div>
                        {{-- Physician Takeover: who currently owns this consultation, and
                             (once it has changed hands) who it was scheduled to. Both names
                             come from the server so this panel cannot disagree with the
                             assignment the takeover transaction actually wrote. --}}
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Assigned Physician') }}</p>
                            <p class="mt-1.5 text-sm font-medium text-gray-900" x-text="selectedConsultation?.assigned_physician_name || '{{ __('Unassigned') }}'"></p>
                            <p x-show="selectedConsultation?.is_assigned_to_me" x-cloak class="text-[11px] font-semibold text-emerald-600">{{ __('You') }}</p>
                            <p x-show="selectedConsultation?.was_taken_over && selectedConsultation?.original_physician_name"
                               x-cloak
                               class="mt-0.5 text-[11px] text-gray-500"
                               x-text="'{{ __('Originally') }}: ' + selectedConsultation?.original_physician_name"></p>
                        </div>
                    </div>

                    {{-- Takeover status banner. takeover_available is decided server-side
                         from the slot's start time plus the grace period; this block only
                         renders the answer. --}}
                    <template x-if="selectedConsultation && !selectedConsultation.is_assigned_to_me && selectedConsultation.takeover_message">
                        <div class="rounded-xl border p-3"
                             :class="selectedConsultation.takeover_available ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-gray-50'">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em]"
                               :class="selectedConsultation.takeover_available ? 'text-amber-700' : 'text-gray-500'"
                               x-text="selectedConsultation.takeover_available ? '{{ __('Takeover Available') }}' : '{{ __('Assignment') }}'"></p>
                            <p class="mt-1.5 text-sm"
                               :class="selectedConsultation.takeover_available ? 'text-amber-900' : 'text-gray-700'"
                               x-text="selectedConsultation.takeover_message"></p>
                            <p x-show="selectedConsultation.takeover_available && selectedConsultation.waiting_minutes !== null"
                               x-cloak
                               class="mt-1 text-xs font-medium text-amber-800"
                               x-text="'{{ __('Patient waiting') }}: ' + selectedConsultation.waiting_minutes + ' {{ __('min') }}'"></p>
                        </div>
                    </template>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Scheduled Slot') }}</p>
                        <template x-if="selectedConsultation?.scheduled_slot">
                            <p class="mt-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-800">
                                <span x-text="selectedConsultation.scheduled_slot.label"></span>
                                <span class="text-indigo-500" x-text="'· ' + selectedConsultation.scheduled_slot.slot_date"></span>
                            </p>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedConsultation?.scheduled_slot">{{ __('Not scheduled yet.') }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Symptoms') }}</p>
                        <template x-if="selectedConsultation?.symptoms_desc">
                            <ul class="mt-2 space-y-2 text-sm text-gray-900" x-html="formatSymptoms(selectedConsultation?.symptoms_desc)"></ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedConsultation?.symptoms_desc">{{ __('No symptom details provided.') }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Reason for online consultation') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedConsultation?.online_reason ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Additional Information') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedConsultation?.additional_information || '{{ __('No additional information provided.') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Attachments') }}</p>
                        <template x-if="selectedConsultation?.file_attachments && selectedConsultation.file_attachments.length">
                            <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <template x-for="file in selectedConsultation.file_attachments" :key="file">
                                    <button
                                        type="button"
                                        @click="openAttachmentPreview(file)"
                                        class="group relative h-20 overflow-hidden rounded-xl border border-gray-200 bg-gray-100 shadow-sm transition hover:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2 sm:h-24"
                                    >
                                        <span class="sr-only" x-text="attachmentName(file)"></span>
                                        <img
                                            :src="file"
                                            :alt="attachmentName(file)"
                                            x-on:error="$el.style.display = 'none'; $el.nextElementSibling.style.display = 'flex';"
                                            class="h-full w-full object-cover transition group-hover:scale-105"
                                        >
                                        <div class="h-full w-full items-center justify-center p-1 text-center text-[10px] text-gray-400" style="display: none;">{{ __('Image unavailable') }}</div>
                                    </button>
                                </template>
                            </div>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedConsultation?.file_attachments || !selectedConsultation.file_attachments.length">{{ __('No attachments.') }}</p>
                    </div>
                </div>

                <div class="border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-5">
                    {{-- can_start / can_start_message come from the server, so the
                         reason a consultation cannot start is shown up front
                         instead of after a failed request. --}}
                    <div x-show="selectedConsultation && !selectedConsultation.can_start && selectedConsultation.can_start_message"
                         x-cloak
                         class="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                        <span x-text="selectedConsultation?.can_start_message"></span>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2">
                            {{ __('Close') }}
                        </button>
                        {{-- Physician Takeover: another physician's consultation offers only
                             the Claim action. The backend refuses these three regardless —
                             hiding them just stops the physician firing a request that can
                             only 422. --}}
                        <template x-if="selectedConsultation?.takeover_available">
                            <button type="button" @click="Swal.fire({
                                title: 'Claim Consultation',
                                text: 'The assigned physician has not started this consultation. Take over as the responsible physician?',
                                icon: 'question',
                                showCancelButton: true,
                                confirmButtonColor: '#d97706',
                                cancelButtonColor: '#6b7280',
                                confirmButtonText: 'Yes, claim it',
                                cancelButtonText: 'Cancel'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    takeOverConsultation();
                                }
                            })"
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                </svg>
                                {{ __('Claim Consultation') }}
                            </button>
                        </template>
                        <template x-if="selectedConsultation?.is_actionable_by_me && selectedConsultation?.request_status === 'reviewed'">
                            <button type="button" @click="Swal.fire({
                                title: 'Reject Consultation',
                                text: 'Please provide a reason for rejecting this consultation.',
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
                                if (result.isConfirmed) {
                                    rejectReviewedConsultation(result.value);
                                }
                            })"
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {{ __('Reject') }}
                            </button>
                        </template>
                        <template x-if="selectedConsultation?.is_actionable_by_me && ['reviewed', 'assigned', 'scheduled'].includes(selectedConsultation.request_status)">
                            <button type="button" @click="promptScheduleSlot()"
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-green px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-green-deep focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0V11.25A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                </svg>
                                {{ __('Schedule') }}
                            </button>
                        </template>
                        <template x-if="selectedConsultation?.is_actionable_by_me && ['reviewed', 'assigned', 'scheduled'].includes(selectedConsultation.request_status)">
                            <button type="button"
                            :disabled="!selectedConsultation.can_start"
                            :title="selectedConsultation.can_start ? '' : selectedConsultation.can_start_message"
                            @click="Swal.fire({
                                title: 'Start Consultation',
                                text: 'Are you sure you want to start this consultation?',
                                icon: 'question',
                                showCancelButton: true,
                                confirmButtonText: 'Yes, start it!',
                                cancelButtonText: 'Cancel'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    startConsultation();
                                }
                            })"
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-emerald-600">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                                </svg>
                                {{ __('Start') }}
                            </button>
                        </template>
                    </div>
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
                    <p class="truncate text-sm font-semibold text-gray-900" x-text="previewFile ? attachmentName(previewFile) : ''"></p>
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
                    <img :src="previewFile" :alt="previewFile ? attachmentName(previewFile) : ''" class="mx-auto max-h-[70vh] w-auto max-w-full rounded-lg object-contain">
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
