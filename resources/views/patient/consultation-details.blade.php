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
                        <div class="rounded-3xl border border-gray-200 bg-slate-50 p-6">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    {{-- Derived from request_status rather than
                                         hard-coded — this used to always read
                                         "Active Consultation" regardless of
                                         status, contradicting the status badge
                                         rendered next to it (e.g. a completed
                                         or rejected request still said Active). --}}
                                    <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ $consultation->type === 'follow_up' ? 'Follow-up Consultation' : ucfirst($consultation->request_status) . ' Consultation' }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
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
                                    $statusClasses = \App\Support\StatusBadge::patientClasses($status);
                                    $statusMeaning = \App\Support\StatusBadge::patientMeaning($status);
                                @endphp
                                <div class="flex flex-col gap-3 sm:items-end sm:w-80">
                                    <span class="{{ $statusClasses }}">
                                        {{ ucfirst($status) }}
                                    </span>
                                    {{-- Phase 2 UX: a bare status word ("Reviewed") doesn't tell a
                                         first-time patient what's actually happening or what to expect
                                         next — StatusBadge::patientMeaning() is the same sentence used
                                         on the dashboard card so both places agree. --}}
                                    @if ($statusMeaning)
                                        <p class="text-sm text-slate-600 sm:text-right">{{ $statusMeaning }}</p>
                                    @endif
                                    <div class="rounded-2xl border border-gray-200 bg-white px-5 py-4 text-right shadow-sm">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Submitted</p>
                                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ $consultation->submitted_at->format('M d, Y @ h:i A') }}</p>
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

                        <div class="rounded-3xl border border-gray-200 bg-white p-6">
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Summary of Symptoms</p>
                            <div class="mt-4 space-y-3 text-sm text-slate-700">
                                @if(is_array($consultation->symptoms_desc) && count($consultation->symptoms_desc) > 0)
                                    @foreach($consultation->symptoms_desc as $symptom)
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <p class="font-semibold text-slate-900">{{ $symptom['name'] ?? $symptom }}</p>
                                            @if(!empty($symptom['date']) || !empty($symptom['time']))
                                                <p class="text-xs text-slate-500 mt-1">Started: {{ ($symptom['date'] ?? 'Unknown') }} {{ ($symptom['time'] ?? '') }}</p>
                                            @endif
                                            @if(!empty($symptom['severity']))
                                                <p class="text-xs text-slate-500 mt-1">Severity: {{ $symptom['severity'] }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                @else
                                    <p class="text-sm text-slate-500">No symptoms were recorded for this request.</p>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-3xl border border-gray-200 bg-white p-6">
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Reason for Online Consultation</p>
                            <p class="mt-4 text-sm text-slate-700">{{ $consultation->online_reason ?? 'No reason provided.' }}</p>
                        </div>

                        @if(!empty($consultation->file_attachments))
                            <div class="rounded-3xl border border-gray-200 bg-white p-6">
                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Attachments</p>
                                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
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

                        @if(!empty($consultation->assigned_nurse_id))
                            <div class="rounded-3xl border border-gray-200 bg-white p-6">
                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Assigned Nurse</p>
                                @php
                                    $assignedNurseName = trim((optional($consultation->nurse)->first_name ?? '') . ' ' . (optional($consultation->nurse)->last_name ?? ''));
                                @endphp
                                <p class="mt-4 text-sm text-slate-700">{{ $assignedNurseName !== '' ? $assignedNurseName : 'Assigned nurse record not found.' }}</p>
                            </div>
                        @endif

                        @if(!empty($consultation->assigned_physician_id))
                            <div class="rounded-3xl border border-gray-200 bg-white p-6">
                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Assigned Physician</p>
                                @php
                                    $assignedPhysicianName = trim((optional($consultation->physician)->first_name ?? '') . ' ' . (optional($consultation->physician)->last_name ?? ''));
                                @endphp
                                <p class="mt-4 text-sm text-slate-700">
                                    {{ $assignedPhysicianName !== '' ? 'Dr. ' . $assignedPhysicianName : 'Assigned physician record not found.' }}
                                    @if(!empty(optional($consultation->physician)->specialization))
                                        <span class="text-slate-400">&middot; {{ $consultation->physician->specialization }}</span>
                                    @endif
                                </p>
                            </div>
                        @endif

                        
                        <div class="text-right">
                            @if (in_array($consultation->request_status, ['pending', 'reviewed']))
                                <p class="mb-3 text-sm text-slate-500">You can cancel this consultation request if you wish.</p>
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
