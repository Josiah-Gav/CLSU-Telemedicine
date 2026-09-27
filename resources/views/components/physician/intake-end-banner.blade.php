{{-- Shown on every authenticated physician page. Owns no timer: it only
     listens to the shared heartbeat in layouts/app.blade.php (and to the
     same event re-dispatched by its own buttons and by the intake card), so
     whatever the server last said about intake is what it shows.

     Two states, both server-worded:
       - end_warning: the planned end has passed, intake is still open, and
         it will close on its own after the grace period.
       - auto_closed: it did. Stays until dismissed or intake is reopened. --}}
<div
    x-data="physicianIntakeEndBanner()"
    x-show="mode"
    x-cloak
    class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8"
>
    <div
        role="alert"
        class="flex flex-wrap items-center justify-between gap-4 rounded-2xl p-5"
        :class="mode === 'warning' ? 'bg-amber-700' : 'bg-slate-700'"
    >
        <div class="flex min-w-[240px] flex-1 items-start gap-4">
            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-white/20">
                <svg x-show="mode === 'warning'" class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <svg x-show="mode === 'auto_closed'" class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div>
                <p class="text-base font-bold text-white" x-text="title"></p>
                <p class="mt-1 text-sm text-white/90" x-text="message"></p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <template x-if="mode === 'warning'">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" @click="post('continue_url')" :disabled="busy"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-amber-800 transition disabled:opacity-60">
                        {{ __('Continue (overtime)') }}
                    </button>
                    <button type="button" @click="post('close_url')" :disabled="busy"
                        class="inline-flex items-center gap-2 rounded-xl border border-white/60 bg-transparent px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10 disabled:opacity-60">
                        {{ __('Close intake') }}
                    </button>
                </div>
            </template>
            <template x-if="mode === 'auto_closed'">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" @click="post('open_url')" :disabled="busy"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-slate-700 transition disabled:opacity-60">
                        {{ __('Open intake') }}
                    </button>
                    <button type="button" @click="dismissed = true" :disabled="busy"
                        class="inline-flex items-center gap-2 rounded-xl border border-white/60 bg-transparent px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10 disabled:opacity-60">
                        {{ __('Dismiss') }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</div>

@once
    <script>
        function physicianIntakeEndBanner() {
            return {
                intake: null,
                dismissed: false,
                busy: false,

                get mode() {
                    if (this.intake?.state === 'open' && this.intake.end_warning) {
                        return 'warning';
                    }

                    if (this.intake?.state === 'auto_closed' && !this.dismissed) {
                        return 'auto_closed';
                    }

                    return null;
                },

                get title() {
                    return this.mode === 'warning' ? this.intake.end_warning.title : this.intake?.status_label;
                },

                get message() {
                    return this.mode === 'warning' ? this.intake.end_warning.message : this.intake?.message;
                },

                init() {
                    // The first heartbeat fires on page load and can land
                    // before Alpine starts, so pick up whatever it already said.
                    const last = window.telemedIntakeHeartbeat?.last;
                    if (last?.intake) {
                        this.intake = last.intake;
                    }

                    window.addEventListener('telemed:intake-heartbeat', (event) => {
                        if (event.detail?.intake) {
                            this.intake = event.detail.intake;
                        }
                    });
                },

                post(urlKey) {
                    const url = window.telemedIntakeHeartbeat?.[urlKey];
                    if (this.busy || !url) {
                        return;
                    }

                    this.busy = true;

                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                    })
                        .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
                        .then(({ ok, data }) => {
                            if (data.intake) {
                                const open = data.intake.state === 'open';
                                window.telemedIntakeHeartbeat.open = open;
                                // Same event the heartbeat raises, so the intake
                                // card on this page updates too.
                                window.dispatchEvent(new CustomEvent('telemed:intake-heartbeat', {
                                    detail: { success: data.success, open, intake: data.intake },
                                }));
                            }

                            if (!ok) {
                                Swal.fire('Consultation intake', data.message || 'Could not update consultation intake.', 'error');
                            }
                        })
                        .catch(() => {
                            Swal.fire('Consultation intake', 'Could not update consultation intake. Please try again.', 'error');
                        })
                        .finally(() => {
                            this.busy = false;
                        });
                },
            };
        }
    </script>
@endonce
