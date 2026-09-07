@props(['intake', 'routes', 'manageUrl' => null, 'todaySchedule' => null, 'warnIfClosed' => false])

{{-- Self-contained: owns its own Alpine scope and only needs the open/close
     intake endpoints, so it can be dropped on any physician page (dashboard,
     consultation intake) without depending on that page's own component. --}}
<div
    class="bg-white overflow-hidden shadow-sm sm:rounded-lg"
    x-data="physicianIntakeStatusCard(@js($intake), @js($routes), @js($warnIfClosed))"
>
    <div class="p-6 text-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h3 class="text-lg font-semibold text-slate-900">{{ __('Current Consultation Intake') }}</h3>

            <span
                x-show="warnIfClosed && intake.state === 'closed'"
                x-cloak
                class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800"
            >
                {{ __('⚠️ You are within your consultation intake schedule') }}
            </span>

            @if ($manageUrl)
                <a
                    href="{{ $manageUrl }}"
                    class="inline-flex items-center px-4 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-md hover:bg-slate-200 transition"
                >
                    {{ __('Create Schedule') }}
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

        <div class="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 p-4">
            <div class="flex items-start gap-3">
                <span
                    class="mt-1 inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full"
                    :class="{
                        'bg-brand-green': intake.state === 'open',
                        'bg-amber-500': intake.state === 'expired',
                        'bg-slate-300': intake.state === 'closed',
                    }"
                ></span>
                <div>
                    <p
                        class="text-sm font-semibold"
                        :class="intake.state === 'open' ? 'text-brand-green-deep' : 'text-slate-700'"
                        x-text="intake.status_label"
                    ></p>

                    <p class="mt-1 text-xs text-slate-500" x-show="intake.state === 'open'" x-cloak>
                        <span class="font-semibold" x-text="intake.mode_label"></span>
                        <span> · </span>
                        <span>{{ __('Started') }} <span x-text="intake.started_at"></span></span>
                    </p>

                    <p class="mt-1 text-xs text-amber-700" x-show="intake.state === 'expired'" x-cloak>
                        {{ __('Your intake session expired because the connection was lost. Open intake again when you are ready to accept new requests.') }}
                    </p>

                    <p class="mt-1 text-xs text-slate-500" x-show="intake.state === 'closed'" x-cloak>
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
                    class="inline-flex items-center px-4 py-2 bg-brand-green text-white text-xs font-semibold rounded-md hover:bg-brand-green-deep transition disabled:opacity-60"
                >
                    {{ __('Open Intake') }}
                </button>
                <button
                    type="button"
                    x-show="intake.state === 'open'"
                    x-cloak
                    @click="closeIntake()"
                    :disabled="intakeBusy"
                    class="inline-flex items-center px-4 py-2 bg-amber-100 text-amber-800 text-xs font-semibold rounded-md hover:bg-amber-200 transition disabled:opacity-60"
                >
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
