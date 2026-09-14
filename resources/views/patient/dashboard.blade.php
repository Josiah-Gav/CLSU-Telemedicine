<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @php
        $patientConsultationPayload = null;

        if (!empty($activeConsultation)) {
            $status = $activeConsultation->request_status;
            // Phase 4: was a hand-computed if/elseif chain duplicated (and
            // drifted) between this file and consultation-details.blade.php
            // — see StatusBadge::patientClasses()'s docblock.
            $statusClasses = \App\Support\StatusBadge::patientClasses($status);

            $patientConsultationPayload = [
                'request_id' => $activeConsultation->request_id,
                'details_url' => route('consultations.show', $activeConsultation),
                'consultation_type' => $activeConsultation->type === 'follow_up' ? 'follow_up' : 'general',
                'consultation_type_label' => $activeConsultation->type === 'follow_up' ? 'Follow-up' : 'General',
                'concern_category' => $activeConsultation->concern_category,
                'summary' => $activeConsultationSummary ?? 'No symptoms recorded',
                'request_status' => $status,
                'status_badge_class' => $statusClasses,
                'status_label' => ucfirst($status),
                // Phase 2 UX: a bare status word gives a first-time patient no
                // idea what's happening or what to expect next — same sentence
                // StatusBadge::patientMeaning() supplies on consultation-details.blade.php.
                'status_meaning' => \App\Support\StatusBadge::patientMeaning($status),
                'submitted_at' => optional($activeConsultation->submitted_at)->format('M d, Y'),
                'show_messaging' => in_array($status, ['active', 'completed'], true) && $activeConsultation->consultationSession,
                'session' => $activeConsultation->consultationSession ? [
                    'id' => $activeConsultation->consultationSession->id,
                    'consultation_status' => $activeConsultation->consultationSession->consultation_status,
                    'scheduled_slot' => $activeConsultation->consultationSession->slot ? [
                        'slot_date' => $activeConsultation->consultationSession->slot->slot_date?->format('M d, Y') ?? (string) $activeConsultation->consultationSession->slot->slot_date,
                        'start_time' => $activeConsultation->consultationSession->slot->start_time,
                        'end_time' => $activeConsultation->consultationSession->slot->end_time,
                    ] : null,
                    'has_clinical_documentation' => $activeConsultation->consultationSession->hasClinicalDocumentation(),
                    'clinical_badge_class' => $activeConsultation->consultationSession->hasClinicalDocumentation() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700',
                    'clinical_label' => $activeConsultation->consultationSession->hasClinicalDocumentation() ? __('Assessment ready') : __('Assessment pending'),
                    'has_prescription' => $activeConsultation->consultationSession->hasPrescription(),
                    'prescription_badge_class' => $activeConsultation->consultationSession->hasPrescription() ? 'bg-brand-green-soft text-brand-green-deep' : 'bg-slate-100 text-slate-600',
                    'prescription_label' => $activeConsultation->consultationSession->hasPrescription() ? __('Prescription uploaded') : __('No prescription'),
                    'unread_count' => 0,
                ] : null,
            ];
        }
    @endphp

    <script>
        window.patientConsultation = @json($patientConsultationPayload);
        window.physicianFollowUp = @json($physicianFollowUp);

        function cancelFollowUpRequest(triggerElement) {
            const cancelUrl = triggerElement?.dataset?.cancelUrl;

            if (!cancelUrl) {
                Swal.fire('Error', 'Unable to find the follow-up cancel URL.', 'error');
                return;
            }

            Swal.fire({
                title: 'Cancel follow-up request?',
                text: 'This action cannot be undone. The request will be marked as cancelled.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, cancel it',
                cancelButtonText: 'Keep request',
                confirmButtonColor: '#dc2626',
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                fetch(cancelUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (!data.success) {
                            throw new Error(data.message || 'Unable to cancel follow-up request.');
                        }

                        Swal.fire('Cancelled', data.message || 'Your follow-up request has been cancelled.', 'success').then(() => {
                            window.location.reload();
                        });
                    })
                    .catch((error) => {
                        Swal.fire('Error', error.message || 'Unable to cancel follow-up request.', 'error');
                    });
            });
        }

        function patientDashboard(initialConsultation, initialPhysicianFollowUp, refreshUrl, unreadUrl) {
            return {
                consultation: initialConsultation,
                physicianFollowUp: initialPhysicianFollowUp,
                refreshUrl,
                unreadUrl,
                refreshTimer: null,
                unreadTimer: null,
                init() {
                    if (this.refreshTimer || this.unreadTimer) {
                        return;
                    }

                    this.refreshConsultation();
                    this.updateUnreadBadge();
                    this.refreshTimer = window.setInterval(() => this.refreshConsultation(), 5000);
                    this.unreadTimer = window.setInterval(() => this.updateUnreadBadge(), 5000);
                },
                refreshConsultation() {
                    $.ajax({
                        url: this.refreshUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const nextConsultation = data?.consultation ?? null;
                            const nextPhysicianFollowUp = data?.physician_follow_up ?? null;
                            const previousUnreadCount = this.consultation?.session?.unread_count ?? 0;

                            this.consultation = nextConsultation;
                            this.physicianFollowUp = nextPhysicianFollowUp;

                            if (this.consultation?.session) {
                                this.consultation.session.unread_count = previousUnreadCount;
                            }
                        }
                    });
                },
                updateUnreadBadge() {
                    if (!this.consultation?.session || this.consultation.session.consultation_status !== 'active') {
                        if (this.consultation?.session) {
                            this.consultation.session.unread_count = 0;
                        }
                        return;
                    }

                    $.ajax({
                        url: this.unreadUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const counts = data?.counts || {};
                            const sessionId = String(this.consultation.session.id);
                            this.consultation.session.unread_count = Number(counts[sessionId] || 0);
                        }
                    });
                },
                formatUnreadCount(count) {
                    return count > 99 ? '99+' : String(count);
                },
                consultationTitle() {
                    if (!this.consultation) {
                        return 'Consultation';
                    }

                    const typeLabel = this.consultation.consultation_type_label || (this.consultation.consultation_type === 'follow_up' ? 'Follow-up' : 'General');
                    const concernCategory = typeof this.consultation?.concern_category === 'string'
                        ? this.consultation.concern_category.trim()
                        : '';
                    const normalizedConcernCategory = concernCategory
                        ? concernCategory.charAt(0).toUpperCase() + concernCategory.slice(1)
                        : '';
                    const shouldIncludeConcernCategory = Boolean(normalizedConcernCategory) && normalizedConcernCategory.toLowerCase() !== 'general' && normalizedConcernCategory.toLowerCase() !== typeLabel.toLowerCase();

                    if (!shouldIncludeConcernCategory) {
                        return `${typeLabel} Consultation`;
                    }

                    return `${typeLabel} ${normalizedConcernCategory} Consultation`;
                }
            };
        }
    </script>

    <div class="py-12" x-data="patientDashboard(window.patientConsultation, window.physicianFollowUp, '{{ route('dashboard.active_consultation') }}', '{{ route('consultations.messaging.unread_counts') }}')" x-init="init()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-3xl border border-brand-border bg-gradient-to-r from-brand-green-soft via-white to-brand-gold-soft shadow-sm">
                <div class="p-6 text-brand-green-deep sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-brand-green">Welcome</p>
                    <h2 class="mt-2 text-2xl font-bold text-slate-900">
                        {{ __("Hello $patientInfo->first_name!") }}
                    </h2>
                </div>
            </div>

            @if($followUpStatus['exists'] && in_array($followUpStatus['status'], ['pending', 'forwarded'], true))
                @php $followUpCardTag = $followUpStatus['details_url'] ? 'a' : 'div'; @endphp
                <{{ $followUpCardTag }}
                    @if($followUpStatus['details_url']) href="{{ $followUpStatus['details_url'] }}" @endif
                    class="mt-6 block rounded-3xl border border-gray-200 bg-white shadow-sm {{ $followUpStatus['details_url'] ? 'transition hover:border-blue-300 hover:shadow-lg' : '' }}"
                >
                    <div class="p-6 sm:p-8">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Follow-up Status</p>
                                <h3 class="mt-2 text-xl font-bold text-slate-900">Your latest follow-up request</h3>
                                <p class="mt-1 text-sm text-slate-600">
                                    This request is currently marked as {{ strtolower($followUpStatus['status_label']) }}.
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="{{ $followUpStatus['status_badge_class'] }}">{{ $followUpStatus['status_label'] }}</span>
                                @if(in_array($followUpStatus['status'], ['pending', 'forwarded'], true))
                                    <x-button-danger
                                        size="sm"
                                        data-cancel-url="{{ route('patient.follow_up_requests.cancel', ['followUpRequest' => $followUpStatus['request_id']]) }}"
                                        onclick="event.stopPropagation(); event.preventDefault(); cancelFollowUpRequest(this)"
                                    >
                                        Cancel Request
                                    </x-button-danger>
                                @endif
                                @if($followUpStatus['details_url'])
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                    </svg>
                                @endif
                            </div>
                        </div>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Latest Update</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">{{ $followUpStatus['updated_at'] ?? 'Pending review' }}</p>
                            </div>
                            <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Decision Notes</p>
                                <p class="mt-2 text-sm text-slate-700">{{ $followUpStatus['decision_notes'] ?: 'No notes yet.' }}</p>
                            </div>
                        </div>
                    </div>
                </{{ $followUpCardTag }}>
            @endif

            <div class="mt-6" x-show="physicianFollowUp" x-cloak>
                <template x-if="physicianFollowUp">
                    <div class="rounded-3xl border border-gray-200 bg-white shadow-sm">
                        <div class="p-6 sm:p-8">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Physician Follow-up</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-900">A follow-up consultation was initiated by your physician</h3>
                                    <p class="mt-1 text-sm text-slate-600" x-text="(physicianFollowUp?.physician_name || 'Your physician') + ' has arranged a follow-up consultation for you.'"></p>
                                </div>
                                <span :class="physicianFollowUp?.status_badge_class" x-text="physicianFollowUp?.status_label"></span>
                            </div>

                            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Consultation Type</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900" x-text="physicianFollowUp?.consultation_type_label"></p>
                                </div>
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Consultation Status</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900" x-text="physicianFollowUp?.status_label"></p>
                                </div>
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4 sm:col-span-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Scheduled Appointment</p>
                                    <template x-if="physicianFollowUp?.scheduled_slot">
                                        <p class="mt-2 text-sm font-semibold text-slate-900" x-text="physicianFollowUp.scheduled_slot.slot_date + ' ' + physicianFollowUp.scheduled_slot.start_time + ' - ' + physicianFollowUp.scheduled_slot.end_time"></p>
                                    </template>
                                    <template x-if="!physicianFollowUp?.scheduled_slot">
                                        <p class="mt-2 text-sm text-slate-600">No schedule slot has been assigned yet.</p>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-6" x-show="consultation" x-cloak>
                <a :href="consultation?.details_url" class="block rounded-3xl border border-gray-200 bg-white hover:border-blue-300 hover:shadow-lg transition shadow-sm">
                    <template x-if="consultation">
                        <div class="p-6 sm:p-8">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    {{-- Derived from status_label rather than
                                         hard-coded "Active Consultation" — see
                                         the same fix on the consultation
                                         details page (consultation-details.blade.php). --}}
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400" x-text="(consultation?.status_label || 'Active') + ' Consultation'"></p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-900" x-text="consultationTitle()"></h3>
                                    {{-- Phase 2 UX: explains what the status badge actually means,
                                         rather than leaving a first-time patient to guess. Hidden
                                         entirely when the status has no plain-language sentence
                                         (StatusBadge::patientMeaning() returns null). --}}
                                    <p class="mt-1 text-sm text-slate-600" x-show="consultation?.status_meaning" x-text="consultation?.status_meaning" x-cloak></p>
                                    <p class="mt-1 text-sm text-slate-600" x-text="'Summary of symptoms: ' + (consultation?.summary || 'No symptoms recorded')"></p>
                                </div>
                                <div class="inline-flex items-center gap-3">
                                    <span :class="consultation?.status_badge_class" x-text="consultation?.status_label"></span>
                                    <template x-if="consultation?.show_messaging && consultation?.session">
                                        <span class="inline-flex items-center rounded-full bg-brand-gold-soft px-2.5 py-1 text-brand-green-deep" title="Messaging available">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                            </svg>
                                            <span x-show="(consultation?.session?.unread_count || 0) > 0" x-cloak class="ml-1 rounded-full bg-brand-green px-1.5 py-0.5 text-[10px] font-bold text-white" :data-unread-badge="consultation?.session?.id" x-text="formatUnreadCount(consultation?.session?.unread_count || 0)"></span>
                                            <span class="sr-only">Messaging available</span>
                                        </span>
                                    </template>
                                    <template x-if="consultation?.session">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="consultation?.session?.clinical_badge_class" x-text="consultation?.session?.clinical_label">
                                        </span>
                                    </template>
                                    <template x-if="consultation?.session">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="consultation?.session?.prescription_badge_class" x-text="consultation?.session?.prescription_label">
                                        </span>
                                    </template>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Submitted</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900" x-text="consultation?.submitted_at"></p>
                                </div>
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Status</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900" x-text="consultation?.status_label"></p>
                                </div>
                                <div class="rounded-2xl border border-brand-border bg-brand-gold-soft p-4 sm:col-span-2" x-show="consultation?.request_status === 'scheduled' && consultation?.session?.scheduled_slot" x-cloak>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-green-deep">Scheduled Appointment</p>
                                    <p class="mt-2 text-sm font-semibold text-brand-green-deep" x-text="consultation?.session?.scheduled_slot ? `${consultation.session.scheduled_slot.slot_date} ${consultation.session.scheduled_slot.start_time} - ${consultation.session.scheduled_slot.end_time}` : ''"></p>
                                </div>
                            </div>
                        </div>
                    </template>
                </a>
            </div>

            {{-- Phase 2 UX: when there's no consultation in progress, the page
                 used to show nothing at all here — a dead end for a first-time
                 patient wondering what to do next. Names what's empty, that
                 it's the normal state, and gives the one relevant action. --}}
            <div class="mt-6 rounded-3xl border border-dashed border-gray-300 bg-slate-50 p-6 text-center sm:p-8" x-show="!consultation" x-cloak>
                <p class="text-sm font-semibold text-slate-700">{{ __("You don't have a consultation in progress.") }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __("That's expected if you haven't submitted a request recently. You can request one whenever you're ready.") }}</p>
                <x-button-primary href="{{ route('newconsultation') }}" class="mt-4">
                    {{ __('Request a Consultation') }}
                </x-button-primary>
            </div>

            {{-- Service-level availability, not this patient's own consultation
                 status. Same single source of truth as the new-consultation
                 page's banner (PhysicianAvailabilityService::isServiceAvailable),
                 just server-rendered once here rather than polled — this card
                 does not gate a submission, so a snapshot at page load is enough.
                 Moved below the current-consultation/empty-state block (Phase 2
                 IA): a patient's own situation is the first thing they need to
                 see, not general service availability. --}}
            <div
                class="mt-6 flex items-start gap-3 rounded-2xl border p-4 {{ $intakeAvailable ? 'border-brand-green bg-brand-green-soft' : 'border-amber-300 bg-amber-50' }}"
            >
                <span class="mt-1 inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full {{ $intakeAvailable ? 'bg-brand-green' : 'bg-amber-500' }}"></span>
                <div>
                    <p class="text-sm font-semibold {{ $intakeAvailable ? 'text-brand-green-deep' : 'text-amber-800' }}">
                        {{ $intakeAvailable ? __('Consultations Available') : __('Consultations Currently Unavailable') }}
                    </p>
                    <p class="mt-0.5 text-xs font-semibold {{ $intakeAvailable ? 'text-brand-green-deep/80' : 'text-amber-700' }}">
                        @if ($intakeAvailable)
                            {{ __('You can submit a new consultation request right now.') }}
                        @elseif ($nextScheduledWindow)
                            {{ __('New consultation requests are temporarily unavailable. Next scheduled intake: :day, :time.', ['day' => $nextScheduledWindow['day_name'], 'time' => $nextScheduledWindow['time_label']]) }}
                        @else
                            {{ __('New consultation requests are temporarily unavailable. Please try again later.') }}
                        @endif
                    </p>
                </div>
            </div>

            {{-- This week's recurring intake hours, aggregated across every
                 physician without naming any of them individually. Purely a
                 general expectation of when the clinic tends to be open — a
                 physician can still open intake outside these windows, or
                 skip one they normally keep. Moved to the bottom of the page
                 (Phase 2 IA): secondary/reference information, not the
                 patient's own current situation. --}}
            <div class="mt-6 rounded-3xl border border-gray-200 bg-white shadow-sm">
                <div class="p-6 sm:p-8">
                    <h3 class="text-lg font-semibold text-slate-900">{{ __('This Week\'s Consultation Hours') }}</h3>

                    <div class="mt-5 divide-y divide-gray-100">
                        @foreach ($weeklySchedule as $day)
                            <div class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                                <p class="text-sm font-semibold {{ $day['is_today'] ? 'text-brand-green-deep' : 'text-slate-700' }}">
                                    {{ $day['day_name'] }}
                                    <span class="font-normal text-slate-400">({{ $day['date_label'] }})</span>
                                    @if ($day['is_today'])
                                        <span class="ml-1 rounded-full bg-brand-green-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-green-deep">{{ __('Today') }}</span>
                                    @endif
                                </p>
                                <p class="text-sm text-slate-600">
                                    @if (count($day['windows']))
                                        {{ implode(', ', $day['windows']) }}
                                    @else
                                        <span class="text-slate-400">{{ __('Closed') }}</span>
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-6 text-center">
                <a href="{{ route('consultations.history') }}" class="text-sm font-semibold text-brand-green hover:text-brand-green-deep">
                    {{ __('View your consultation history') }} &rarr;
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
