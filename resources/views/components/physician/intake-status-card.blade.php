@props(['intake', 'routes', 'manageUrl' => null, 'todaySchedule' => null, 'warnIfClosed' => false])

{{-- Self-contained: owns its own Alpine scope and only needs the open/close
     intake endpoints, so it can be dropped on any physician page (dashboard,
     consultation intake) without depending on that page's own component. --}}
<div
    class="overflow-hidden rounded-2xl border border-brand-border bg-white shadow-sm"
    x-data="physicianIntakeStatusCard(@js($intake), @js($routes), @js($warnIfClosed))"
>
    <div class="p-6 text-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h3 class="text-lg font-semibold text-slate-900">{{ __('Current Consultation Intake') }}</h3>

            @if ($manageUrl)
                <a
                    href="{{ $manageUrl }}"
                    class="inline-flex items-center gap-2 rounded-xl border border-brand-border px-4 py-2.5 text-xs font-semibold text-slate-700 transition hover:bg-brand-muted"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    {{ __('Manage Intake Hours') }}
                </a>
            @endif
        </div>

        @if (!is_null($todaySchedule))
            <p class="mt-1 text-sm text-slate-500">
                {{ __('Today\'s hours:') }}
                @if (count($todaySchedule))
                    {{ implode(', ', $todaySchedule) }}
                @else
                    {{ __('No recurring hours set for today.') }}
                @endif
            </p>
        @endif

        <div
            x-show="warnIfClosed && intake.state === 'closed'"
            x-cloak
            class="mt-4 flex items-center gap-3 rounded-xl bg-brand-gold-soft px-4 py-3"
        >
            <svg class="h-5 w-5 flex-shrink-0 text-amber-800" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <span class="text-xs font-semibold text-amber-800">{{ __("You're inside your scheduled consultation intake hours — patients expect intake to be open.") }}</span>
        </div>

        <div
            class="mt-5 flex flex-wrap items-center justify-between gap-5 rounded-2xl p-5"
            :class="{
                'bg-brand-green-deep': intake.state === 'open',
                'bg-slate-700': intake.state === 'closed',
                'bg-amber-700': intake.state === 'expired',
            }"
        >
            <div class="flex min-w-[240px] items-start gap-4">
                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-white/20">
                    <svg x-show="intake.state === 'open'" x-cloak class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <svg x-show="intake.state === 'closed'" x-cloak class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="9" y1="12" x2="15" y2="12"></line></svg>
                    <svg x-show="intake.state === 'expired'" x-cloak class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </div>
                <div>
                    <p class="text-base font-bold text-white" x-text="intake.status_label"></p>

                    <p class="mt-1 text-xs text-white/80" x-show="intake.state === 'open'" x-cloak>
                        <span class="font-semibold" x-text="intake.mode_label"></span>
                        <span> · </span>
                        <span>{{ __('Started') }} <span x-text="intake.started_at"></span></span>
                    </p>

                    <p class="mt-1 text-xs text-white/80" x-show="intake.state === 'expired'" x-cloak>
                        {{ __('Your intake session expired because the connection was lost. Open intake again when you are ready to accept new requests.') }}
                    </p>

                    <p class="mt-1 text-xs text-white/80" x-show="intake.state === 'closed'" x-cloak>
                        {{ __('New consultation requests are not being accepted right now.') }}
                    </p>
                </div>
            </div>

            <div>
                <button
                    type="button"
                    x-show="intake.state !== 'open'"
                    @click="openIntake()"
                    :disabled="intakeBusy"
                    class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold transition disabled:opacity-60"
                    :class="{
                        'text-slate-700': intake.state === 'closed',
                        'text-amber-700': intake.state === 'expired',
                    }"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    {{ __('Open Intake') }}
                </button>
                <button
                    type="button"
                    x-show="intake.state === 'open'"
                    x-cloak
                    @click="closeIntake()"
                    :disabled="intakeBusy"
                    class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-brand-green-deep transition disabled:opacity-60"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="9" y1="12" x2="15" y2="12"></line></svg>
                    {{ __('Close Intake') }}
                </button>
            </div>
        </div>
    </div>
</div>

@once
    <script>
        function physicianIntakeStatusCard(initialIntake, routes, warnIfClosed) {
            return {
                intake: initialIntake || { state: 'closed' },
                intakeBusy: false,
                warnIfClosed: warnIfClosed || false,

                init() {
                    // Mirrors the shared authenticated heartbeat (see
                    // layouts/app.blade.php) so it keeps beating while this
                    // card's own state says intake is open, then stays in
                    // sync with whatever the heartbeat reports back.
                    this.setHeartbeatEnabled(this.intake.state === 'open');

                    window.addEventListener('telemed:intake-heartbeat', (event) => {
                        if (event.detail?.intake) {
                            this.intake = event.detail.intake;
                        }
                    });
                },

                setHeartbeatEnabled(enabled) {
                    if (window.telemedIntakeHeartbeat) {
                        window.telemedIntakeHeartbeat.open = enabled;
                    }
                },

                openIntake() {
                    if (this.intakeBusy) {
                        return;
                    }

                    Swal.fire({
                        title: 'Open consultation intake?',
                        text: 'This will allow new patient consultation requests to enter the nurse queue.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, open intake',
                        cancelButtonText: 'Cancel',
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.intakeBusy = true;

                        $.ajax({
                            url: routes.open_url,
                            type: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': this.csrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            dataType: 'json',
                            success: (data) => {
                                this.intake = data.intake;
                                this.setHeartbeatEnabled(true);
                                Swal.fire('Intake Open', data.message, 'success');
                            },
                            error: (xhr) => {
                                const message = xhr.responseJSON?.message || 'Could not open consultation intake.';
                                Swal.fire('Error', message, 'error');
                            },
                            complete: () => {
                                this.intakeBusy = false;
                            },
                        });
                    });
                },

                closeIntake() {
                    if (this.intakeBusy) {
                        return;
                    }

                    Swal.fire({
                        title: 'Close consultation intake?',
                        text: 'New consultation requests will no longer be accepted. Consultations already in progress or scheduled are not affected.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, close intake',
                        cancelButtonText: 'Cancel',
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.intakeBusy = true;

                        $.ajax({
                            url: routes.close_url,
                            type: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': this.csrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            dataType: 'json',
                            success: (data) => {
                                this.intake = data.intake;
                                this.setHeartbeatEnabled(false);
                                Swal.fire('Intake Closed', data.message, 'success');
                            },
                            error: (xhr) => {
                                const message = xhr.responseJSON?.message || 'Could not close consultation intake.';
                                Swal.fire('Error', message, 'error');
                            },
                            complete: () => {
                                this.intakeBusy = false;
                            },
                        });
                    });
                },

                csrfToken() {
                    return $('meta[name="csrf-token"]').attr('content');
                },
            };
        }
    </script>
@endonce
