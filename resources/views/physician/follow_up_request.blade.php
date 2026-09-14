<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Follow-up Requests') }}
        </h2>
    </x-slot>

        @php
            $forwardedFollowUpPayload = collect($forwardedRequests)->map(function ($followUp) use ($physician) {
                return [
                    'id' => $followUp->id,
                    'patient_name' => trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient',
                    'reason' => $followUp->reason,
                    'decide_url' => route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                    'available_slots_url' => route('physician.follow_up_requests.available_slots', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                ];
            })->values();
        @endphp

    <script>
            window.forwardedFollowUpRequests = @json($forwardedFollowUpPayload);

        function physicianFollowUpRequests(initialRequests) {
            return {
                requests: initialRequests || [],
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
                                carry[String(slot.slot_id)] = `${slot.label} (${slot.slot_date})`;
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

    <div class="py-10" x-data="physicianFollowUpRequests(window.forwardedFollowUpRequests)">
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
                                $followUpJs = [
                                    'id' => $followUp->id,
                                    'patient_name' => $patientName,
                                    'reason' => $followUp->reason,
                                    'decide_url' => route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                                    'available_slots_url' => route('physician.follow_up_requests.available_slots', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                                ];
                            @endphp
                            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="inline-block h-[0.625em] w-[0.625em] shrink-0 rounded-full {{ $isOnline ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
                                    <p class="truncate text-sm font-medium text-slate-900">{{ $patientName }}</p>
                                </div>
                                <p class="mt-2 text-sm text-slate-700">{{ $followUp->reason }}</p>
                                <div class="mt-4 flex flex-col gap-2">
                                    <x-button-primary class="w-full" @click="startFollowUpNow(@js($followUpJs))">
                                        Approve &amp; Start Now
                                    </x-button-primary>
                                    <x-button-primary class="w-full" @click="approveScheduled(@js($followUpJs))">
                                        Approve &amp; Schedule
                                    </x-button-primary>
                                    <x-button-danger class="w-full" @click="rejectRequest(@js($followUpJs))">
                                        Reject
                                    </x-button-danger>
                                </div>
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
                                        @php
                                            $rowJs = [
                                                'id' => $followUp->id,
                                                'patient_name' => $patientName,
                                                'reason' => $followUp->reason,
                                                'decide_url' => route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                                                'available_slots_url' => route('physician.follow_up_requests.available_slots', ['physician' => $physician->user_id, 'followUpRequest' => $followUp->id]),
                                            ];
                                        @endphp
                                        <div class="flex flex-wrap gap-2">
                                            <x-button-primary size="sm" @click="startFollowUpNow(@js($rowJs))">
                                                Approve &amp; Start Now
                                            </x-button-primary>
                                            <x-button-primary size="sm" @click="approveScheduled(@js($rowJs))">
                                                Approve &amp; Schedule
                                            </x-button-primary>
                                            <x-button-danger size="sm" @click="rejectRequest(@js($rowJs))">
                                                Reject
                                            </x-button-danger>
                                        </div>
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
    </div>
</x-app-layout>