<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation Details') }}
        </h2>
    </x-slot>

    <div class="py-12" x-data="{ previewFile: null }" @keydown.escape.window="previewFile = null">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 bg-white border-b border-gray-200">
                    <div class="space-y-6">
                        <div class="rounded-3xl border border-gray-200 bg-white p-6 sm:p-7">
                            <div class="flex flex-wrap items-stretch justify-between gap-5">
                                <div class="flex min-w-[220px] flex-col justify-center gap-2">
                                    {{-- Derived from request_status rather than
                                         hard-coded — this used to always read
                                         "Active Consultation" regardless of
                                         status, contradicting the status badge
                                         rendered next to it (e.g. a completed
                                         or rejected request still said Active). --}}
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $consultation->type === 'follow_up' ? 'Follow-up Consultation' : ucfirst($consultation->request_status) . ' Consultation' }}</p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-2xl font-bold text-slate-900">{{ ucfirst($consultation->concern_category) }} Consultation</h3>
                                        @if($consultation->type === 'follow_up')
                                            <span class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">Follow-up</span>
                                        @endif
                                    </div>
                                </div>
                                @php
                                    $status = $consultation->request_status;
                                    // Phase 4: was a hand-computed if/elseif chain duplicated
                                    // (and drifted — this copy never gave 'pending'/'assigned'
                                    // their own color) between this file and
                                    // patient/dashboard.blade.php — see
                                    // StatusBadge::patientClasses()'s docblock.
                                    $statusMeaning = \App\Support\StatusBadge::patientMeaning($status);
                                    $statusPanel = \App\Support\StatusBadge::patientPanel($status);
                                @endphp
                                {{-- The status folds icon + label + meaning +
                                     submitted date into one solid panel —
                                     color is never the only signal, and it
                                     replaces a small pill sitting next to an
                                     unrelated "Submitted" card. --}}
                                <div class="flex flex-1 items-center gap-4 rounded-2xl {{ $statusPanel['bg_class'] }} px-6 py-5 sm:max-w-sm">
                                    <span class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-white/20">
                                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $statusPanel['icon_path'] }}" />
                                        </svg>
                                    </span>
                                    <div>
                                        <p class="text-sm font-bold text-white">{{ ucfirst($status) }}</p>
                                        {{-- Phase 2 UX: a bare status word ("Reviewed") doesn't tell a
                                             first-time patient what's actually happening or what to expect
                                             next — StatusBadge::patientMeaning() is the same sentence used
                                             on the dashboard card so both places agree. --}}
                                        @if ($statusMeaning)
                                            <p class="mt-0.5 text-xs text-white/80">{{ $statusMeaning }}</p>
                                        @endif
                                        <p class="mt-2 text-[11px] text-white/60">Submitted {{ $consultation->submitted_at->format('M d, Y @ h:i A') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if($consultation->type === 'follow_up' && $consultation->parentConsultation?->request)
                            <div class="rounded-3xl border border-gray-200 bg-white p-6">
                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Original Consultation</p>
                                <p class="mt-2 text-sm text-slate-600">This follow-up was created from an earlier consultation.</p>
                                <x-button-ghost href="{{ route('consultations.show', $consultation->parentConsultation->request) }}" class="mt-4">
                                    View original consultation
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </x-button-ghost>
                            </div>
                        @endif

                        @if($consultation->request_status === 'scheduled' && $consultation->consultationSession && $consultation->consultationSession->slot)
                            <div class="rounded-3xl border border-brand-border bg-brand-gold-soft p-6">
                                <p class="text-sm font-semibold uppercase tracking-wide text-brand-green-deep">Scheduled Appointment</p>
                                <p class="mt-3 text-lg font-bold text-brand-green-deep">{{ $consultation->consultationSession->slot->slot_date?->format('l, F j, Y') ?? $consultation->consultationSession->slot->slot_date }}</p>
                                <p class="mt-1 text-sm text-brand-green">{{ $consultation->consultationSession->slot->start_time }} - {{ $consultation->consultationSession->slot->end_time }}</p>
                            </div>
                        @endif

                        {{-- Symptoms, reason, and attachments are facets of the
                             same record, not unrelated content — grouped into
                             one panel (divide-y for the rule between
                             sections) instead of three identical white cards
                             stacked with equal weight. --}}
                        <div class="overflow-hidden rounded-3xl border border-gray-200 bg-white divide-y divide-gray-100">
                            <div class="p-6">
                                <div class="flex items-center gap-2">
                                    <svg class="h-[15px] w-[15px] text-slate-900" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p class="text-sm font-bold text-slate-900">Summary of Symptoms</p>
                                </div>
                                <div class="mt-3 space-y-2 text-sm text-slate-700">
                                    @if(is_array($consultation->symptoms_desc) && count($consultation->symptoms_desc) > 0)
                                        @foreach($consultation->symptoms_desc as $symptom)
                                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-4 py-2.5">
                                                <p class="text-sm font-semibold text-slate-900">{{ $symptom['name'] ?? $symptom }}</p>
                                                <p class="text-xs text-slate-500">
                                                    @if(!empty($symptom['date']) || !empty($symptom['time']))
                                                        Started {{ ($symptom['date'] ?? 'Unknown') }} {{ ($symptom['time'] ?? '') }}
                                                    @endif
                                                    @if(!empty($symptom['severity']))
                                                        &middot; {{ $symptom['severity'] }}
                                                    @endif
                                                </p>
                                            </div>
                                        @endforeach
                                    @else
                                        <p class="text-sm text-slate-500">No symptoms were recorded for this request.</p>
                                    @endif
                                </div>
                            </div>

                            <div class="p-6">
                                <div class="flex items-center gap-2">
                                    <svg class="h-[15px] w-[15px] text-slate-900" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                                    </svg>
                                    <p class="text-sm font-bold text-slate-900">Reason for Online Consultation</p>
                                </div>
                                <p class="mt-3 text-sm text-slate-700">{{ $consultation->online_reason ?? 'No reason provided.' }}</p>
                            </div>

                            @if(!empty($consultation->file_attachments))
                                <div class="p-6">
                                    <div class="flex items-center gap-2">
                                        <svg class="h-[15px] w-[15px] text-slate-900" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                        </svg>
                                        <p class="text-sm font-bold text-slate-900">Attachments</p>
                                    </div>
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        @foreach($consultation->file_attachments as $attachment)
                                            @php
                                                // Never the stored reference itself: attachments are
                                                // served only through the authorizing route, exactly as
                                                // the nurse and physician views already do it.
                                                $attachmentUrl = route('consultation.attachment', [
                                                    'consultation' => $consultation->request_id,
                                                    'file' => basename($attachment),
                                                ]);
                                            @endphp
                                            <button
                                                type="button"
                                                @click="previewFile = @js($attachmentUrl)"
                                                class="group relative h-24 overflow-hidden rounded-xl border border-slate-200 bg-slate-100 shadow-sm transition hover:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2"
                                            >
                                                <span class="sr-only">{{ __('View attachment') }}</span>
                                                <img
                                                    src="{{ $attachmentUrl }}"
                                                    alt="{{ __('Attachment preview') }}"
                                                    x-on:error="$el.style.display = 'none'; $el.nextElementSibling.style.display = 'flex';"
                                                    class="h-full w-full object-cover transition group-hover:scale-105"
                                                >
                                                <div class="h-full w-full items-center justify-center p-1 text-center text-[10px] text-slate-400" style="display: none;">{{ __('Image unavailable') }}</div>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- Two full-width cards, each holding one line of
                             text, collapsed into one compact row — the
                             amount of space this information actually
                             needs. --}}
                        @if(!empty($consultation->assigned_nurse_id) || !empty($consultation->assigned_physician_id))
                            <div class="flex flex-col gap-2.5">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Care Team</p>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @if(!empty($consultation->assigned_nurse_id))
                                        @php
                                            $assignedNurseName = trim((optional($consultation->nurse)->first_name ?? '') . ' ' . (optional($consultation->nurse)->last_name ?? ''));
                                            $nurseInitials = $assignedNurseName !== '' ? strtoupper(mb_substr($consultation->nurse->first_name, 0, 1) . mb_substr($consultation->nurse->last_name, 0, 1)) : '?';
                                        @endphp
                                        <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3.5">
                                            <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-brand-green-soft text-sm font-bold text-brand-green-deep">{{ $nurseInitials }}</span>
                                            <div>
                                                <p class="text-sm font-bold text-slate-900">{{ $assignedNurseName !== '' ? $assignedNurseName : 'Assigned nurse record not found.' }}</p>
                                                <p class="text-xs text-slate-400">Assigned Nurse</p>
                                            </div>
                                        </div>
                                    @endif

                                    @if(!empty($consultation->assigned_physician_id))
                                        @php
                                            $assignedPhysicianName = trim((optional($consultation->physician)->first_name ?? '') . ' ' . (optional($consultation->physician)->last_name ?? ''));
                                            $physicianInitials = $assignedPhysicianName !== '' ? strtoupper(mb_substr($consultation->physician->first_name, 0, 1) . mb_substr($consultation->physician->last_name, 0, 1)) : '?';
                                        @endphp
                                        <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3.5">
                                            <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-brand-gold-soft text-sm font-bold text-amber-800">{{ $physicianInitials }}</span>
                                            <div>
                                                <p class="text-sm font-bold text-slate-900">
                                                    {{ $assignedPhysicianName !== '' ? 'Dr. ' . $assignedPhysicianName : 'Assigned physician record not found.' }}
                                                </p>
                                                <p class="text-xs text-slate-400">
                                                    Assigned Physician
                                                    @if(!empty(optional($consultation->physician)->specialization))
                                                        &middot; {{ $consultation->physician->specialization }}
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- A hairline marks this as a controls zone, not
                             another content card. --}}
                        <div class="border-t border-gray-200 pt-6 text-right">
                            @if (in_array($consultation->request_status, ['pending', 'reviewed']))
                                <p class="mb-3 text-sm text-slate-500">You can cancel this consultation request if you wish.</p>
                            @endif
                            <div class="flex flex-wrap items-center justify-end gap-3">
                                @if (in_array($consultation->request_status, ['pending', 'reviewed']))
                                    {{-- Was <a href="javascript:void(0);">, a link acting as a
                                         button — a real <button> (what x-button-danger renders
                                         with no `href` prop) is the more correct element for a
                                         same-page action with no destination URL. --}}
                                    <x-button-danger
                                        data-cancel-url="{{ route('consultations.cancel', $consultation) }}"
                                        onclick="cancelConsultation(this);"
                                    >Cancel</x-button-danger>
                                @endif
                                @if ($consultation->request_status === 'active' && $consultation->consultationSession)
                                    <x-button-primary href="{{ route('consultations.messaging.show', $consultation->consultationSession) }}" aria-label="Open messaging">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                        </svg>
                                    </x-button-primary>
                                @endif
                                @if ($consultation->request_status === 'completed' && $consultation->consultationSession)
                                    <x-button-primary href="{{ route('consultations.messaging.show', $consultation->consultationSession) }}">
                                        {{ __('View Chats & Assessment') }}
                                    </x-button-primary>
                                @endif
                                <x-button-secondary href="{{ route('dashboard') }}">Back to Dashboard</x-button-secondary>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            x-show="previewFile"
            x-cloak
            @click.self="previewFile = null"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 p-3 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Attachment preview') }}"
        >
            <div
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
                        @click="previewFile = null"
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
        function cancelConsultation(triggerElement) {
            const cancelUrl = triggerElement?.dataset?.cancelUrl;
            if (!cancelUrl) {
                Swal.fire(
                    'Error!',
                    'Unable to find consultation cancel URL.',
                    'error'
                );
                return;
            }

            Swal.fire({
                title: 'Are you sure?',
                text: 'You won\'t be able to revert this!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, cancel it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Make an AJAX request to cancel the consultation
                    fetch(cancelUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire(
                                'Cancelled!',
                                'Your consultation has been cancelled.',
                                'success'
                            ).then(() => {
                                // Optionally, you can redirect or refresh the page
                                window.location.href = '{{ route('dashboard') }}';
                            });
                        } else {
                            Swal.fire(
                                'Error!',
                                data.message || 'An error occurred while cancelling the consultation.',
                                'error'
                            );
                        }
                    })
                    .catch(error => {
                        Swal.fire(
                            'Error!',
                            'An error occurred while cancelling the consultation.',
                            'error'
                        );
                    });
                }
            });
}
    </script>
</x-app-layout>
