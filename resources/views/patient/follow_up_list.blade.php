<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Follow-up Consultations') }}
        </h2>
    </x-slot>

    <script>
        function requestFollowUp(button) {
            const form = document.getElementById(button.getAttribute('data-form-id'));

            if (!form) {
                return;
            }

            Swal.fire({
                title: 'Request Follow-up',
                text: 'Tell us why you need a follow-up consultation.',
                icon: 'question',
                input: 'textarea',
                inputPlaceholder: 'Reason for follow-up...',
                inputAttributes: {
                    'aria-label': 'Reason for follow-up'
                },
                showCancelButton: true,
                confirmButtonText: 'Submit Request',
                inputValidator: (value) => {
                    if (!value) {
                        return 'A reason is required.';
                    }
                }
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                const textarea = form.querySelector('textarea[name="reason"]');

                if (textarea) {
                    textarea.value = result.value || '';
                }

                form.submit();
            });
        }
    </script>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-sm text-slate-600">
                        Completed consultations from the last 7 days appear below.
                    </p>
                </div>

                {{-- One hidden form per session, shared by the card button and the
                     table button. requestFollowUp() looks the form up by id and fills
                     its textarea, so exactly one element may carry each id. --}}
                @foreach($completedConsultations as $session)
                    @if($session->followUpRequests->firstWhere('status', 'pending') === null)
                        <form id="follow-up-form-{{ $session->id }}" method="POST" action="{{ route('patient.follow_up_requests.store', ['session' => $session]) }}" class="hidden">
                            @csrf
                            <textarea name="reason" rows="2" maxlength="2000"></textarea>
                        </form>
                    @endif
                @endforeach

                {{-- Below sm the four-column table needs sideways scrolling, so the
                     same rows render as cards. The column headers disappear with the
                     table, so each value carries its own label. --}}
                <div class="space-y-3 p-4 sm:hidden">
                    @forelse($completedConsultations as $session)
                        @php
                            $request = $session->request;
                            $patientName = trim((optional($request->patient)->first_name ?? '') . ' ' . (optional($request->patient)->last_name ?? '')) ?: 'Patient';
                            $messagingUrl = route('consultations.messaging.show', ['session' => $session]);
                            $existingFollowUp = $session->followUpRequests->firstWhere('status', 'pending');
                        @endphp
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-sm font-semibold text-slate-900">{{ $patientName }}</p>

                            <dl class="mt-2 space-y-1 text-xs">
                                <div class="flex gap-2">
                                    <dt class="font-semibold uppercase tracking-wide text-slate-500">Completed</dt>
                                    <dd class="text-slate-700">{{ optional($session->completed_at)->format('M d, Y @ h:i A') }}</dd>
                                </div>
                                <div class="flex gap-2">
                                    <dt class="font-semibold uppercase tracking-wide text-slate-500">Physician</dt>
                                    <dd class="text-slate-700">{{ trim((optional($session->physician)->first_name ?? '') . ' ' . (optional($session->physician)->last_name ?? '')) ?: 'Unassigned' }}</dd>
                                </div>
                            </dl>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <x-button-primary href="{{ $messagingUrl }}" class="flex-1">
                                    View Details
                                </x-button-primary>

                                @if($existingFollowUp)
                                    <span class="inline-flex flex-1 items-center justify-center rounded-lg bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800">
                                        Follow-up pending review
                                    </span>
                                @else
                                    <x-button-primary
                                        class="flex-1"
                                        data-form-id="follow-up-form-{{ $session->id }}"
                                        onclick="requestFollowUp(this)"
                                    >
                                        Request Follow-up
                                    </x-button-primary>
                                @endif
                            </div>
                        </article>
                    @empty
                        <p class="px-2 py-6 text-center text-sm text-slate-500">
                            No completed consultations are eligible for follow-up right now.
                        </p>
                    @endforelse
                </div>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Consultation</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Completed</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Physician</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @forelse($completedConsultations as $session)
                                @php
                                    $request = $session->request;
                                    $patientName = trim((optional($request->patient)->first_name ?? '') . ' ' . (optional($request->patient)->last_name ?? '')) ?: 'Patient';
                                    $messagingUrl = route('consultations.messaging.show', ['session' => $session]);
                                    $existingFollowUp = $session->followUpRequests->firstWhere('status', 'pending');
                                @endphp
                                <tr>
                                    <td class="px-6 py-4 text-sm text-slate-900">
                                        <!-- <div class="font-semibold">{{ $request->concern_category ?? 'Completed Consultation' }}</div> -->
                                        <div class="font-semibold">{{ $patientName }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700">
                                        {{ optional($session->completed_at)->format('M d, Y @ h:i A') }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700">
                                        {{ trim((optional($session->physician)->first_name ?? '') . ' ' . (optional($session->physician)->last_name ?? '')) ?: 'Unassigned' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="flex flex-wrap gap-2">
                                            <x-button-primary size="sm" href="{{ $messagingUrl }}">
                                                View Details
                                            </x-button-primary>

                                            @if($existingFollowUp)
                                                <span class="inline-flex items-center rounded-lg bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800">
                                                    Follow-up pending review
                                                </span>
                                            @else
                                                <x-button-primary
                                                    size="sm"
                                                    data-form-id="follow-up-form-{{ $session->id }}"
                                                    onclick="requestFollowUp(this)"
                                                >
                                                    Request Follow-up
                                                </x-button-primary>
   
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-sm text-slate-500">
                                        No completed consultations are eligible for follow-up right now.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout><div>
    <!-- The biggest battle is the war against ignorance. - Mustafa Kemal Atatürk -->
</div>
