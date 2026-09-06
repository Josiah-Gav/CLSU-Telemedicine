<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation Intake') }}
        </h2>
    </x-slot>

    <script>
        window.consultationIntakeData = {
            schedules: @json($schedules ?? []),
            intake: @json($intake ?? []),
            routes: @json($routes ?? []),
        };

        function consultationIntakeManager(initialData) {
            return {
                routes: initialData.routes || {},
                schedules: Array.isArray(initialData.schedules) ? initialData.schedules : [],
                weekdayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                showForm: false,
                saving: false,
                editingId: null,
                form: {
                    day_of_week: '1',
                    start_time: '08:00',
                    end_time: '12:00',
                },

                // Live intake. Seeded from the server-rendered state so the
                // card never flashes the wrong status on load.
                intake: initialData.intake || { state: 'closed' },
                intakeBusy: false,

                init() {
                    // This page owns no heartbeat timer. The application's
                    // single authenticated heartbeat in the layout does the
                    // touching, so intake stays alive when the physician works
                    // on other pages too; here we only keep the shared flag
                    // truthful and listen for what the server reports back.
                    this.setHeartbeatEnabled(this.intake.state === 'open');

                    window.addEventListener('telemed:intake-heartbeat', (event) => {
                        if (event.detail?.intake) {
                            this.intake = event.detail.intake;
                        }
                    });
                },

                // Tells the layout's heartbeat whether there is an open session
                // worth touching. Only ever reflects a state the server has
                // already confirmed — flipping it true cannot create a session,
                // because the endpoint behind it only bumps an existing one.
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
                            url: this.routes.open_url,
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
                            url: this.routes.close_url,
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

                get schedulesByDay() {
                    return this.weekdayNames.map((name, index) => ({
                        day_of_week: index,
                        day_name: name,
                        windows: this.schedules.filter((schedule) => schedule.day_of_week === index),
                    }));
                },

                openAddForm() {
                    this.editingId = null;
                    this.form = { day_of_week: '1', start_time: '08:00', end_time: '12:00' };
                    this.showForm = true;
                },

                openEditForm(schedule) {
                    this.editingId = schedule.id;
                    this.form = {
                        day_of_week: String(schedule.day_of_week),
                        start_time: schedule.start_time,
                        end_time: schedule.end_time,
                    };
                    this.showForm = true;
                },

                closeForm() {
                    this.showForm = false;
                },

                csrfToken() {
                    return $('meta[name="csrf-token"]').attr('content');
                },

                submitForm() {
                    if (this.saving) {
                        return;
                    }

                    this.saving = true;

                    const isEdit = this.editingId !== null;
                    const url = isEdit
                        ? this.routes.update_url_template.replace('__ID__', this.editingId)
                        : this.routes.store_url;

                    $.ajax({
                        url,
                        type: isEdit ? 'PUT' : 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': this.csrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        data: JSON.stringify(this.form),
                        dataType: 'json',
                        success: (data) => {
                            this.schedules = data.schedules || [];
                            this.showForm = false;
                            Swal.fire('Saved', data.message, 'success');
                        },
                        error: (xhr) => {
                            const errors = xhr.responseJSON?.errors;
                            const firstError = errors ? Object.values(errors)[0]?.[0] : null;
                            const message = firstError || xhr.responseJSON?.message || 'Could not save this schedule window.';
                            Swal.fire('Error', message, 'error');
                        },
                        complete: () => {
                            this.saving = false;
                        },
                    });
                },

                toggleActive(schedule) {
                    $.ajax({
                        url: this.routes.update_url_template.replace('__ID__', schedule.id),
                        type: 'PUT',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': this.csrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        data: JSON.stringify({
                            day_of_week: schedule.day_of_week,
                            start_time: schedule.start_time,
                            end_time: schedule.end_time,
                            is_active: !schedule.is_active,
                        }),
                        dataType: 'json',
                        success: (data) => {
                            this.schedules = data.schedules || [];
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Could not update this schedule window.';
                            Swal.fire('Error', message, 'error');
                        },
                    });
                },

                deleteSchedule(schedule) {
                    Swal.fire({
                        title: 'Delete Schedule Window',
                        text: `Remove ${schedule.day_name} ${schedule.label}? This cannot be undone.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        $.ajax({
                            url: this.routes.destroy_url_template.replace('__ID__', schedule.id),
                            type: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': this.csrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            dataType: 'json',
                            success: (data) => {
                                this.schedules = data.schedules || [];
                                Swal.fire('Deleted', data.message, 'success');
                            },
                            error: (xhr) => {
                                const message = xhr.responseJSON?.message || 'Could not delete this schedule window.';
                                Swal.fire('Error', message, 'error');
                            },
                        });
                    });
                },
            };
        }
    </script>

    <div class="py-8" x-data="consultationIntakeManager(window.consultationIntakeData)">
        <div class="max-w-5xl mx-auto space-y-6 px-4 sm:px-6 lg:px-8">

            {{-- Current intake: whether new consultation requests are being
                 accepted right now. Separate from the recurring schedule
                 below, which only describes normal intended hours. --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold text-slate-900">{{ __('Current Consultation Intake') }}</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ __('While intake is open, new patient consultation requests can enter the nurse review queue. This does not assign those requests to you, and it does not affect consultations already in progress or scheduled.') }}
                    </p>

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

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">{{ __('Recurring Intake Schedule') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ __('Set the times you normally intend to accept new telemedicine consultation requests. This is separate from your appointment slots under Scheduled Consultations.') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openAddForm()"
                            class="inline-flex items-center px-4 py-2 bg-brand-green text-white text-xs font-semibold rounded-md hover:bg-brand-green-deep transition"
                        >
                            {{ __('+ Add Schedule') }}
                        </button>
                    </div>

                    <div class="mt-6 space-y-5">
                        <template x-for="day in schedulesByDay" :key="day.day_of_week">
                            <div class="border-t border-gray-100 pt-4 first:border-t-0 first:pt-0">
                                <h4 class="text-sm font-semibold text-slate-800" x-text="day.day_name"></h4>

                                <p class="mt-2 text-sm text-slate-400" x-show="day.windows.length === 0">
                                    {{ __('No intake schedule') }}
                                </p>

                                <ul class="mt-2 space-y-2" x-show="day.windows.length > 0">
                                    <template x-for="window in day.windows" :key="window.id">
                                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 px-4 py-2.5">
                                            <div class="flex items-center gap-3">
                                                <span class="text-sm font-medium text-slate-700" x-text="window.label"></span>
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
                                                    :class="window.is_active ? 'bg-brand-green-soft text-brand-green-deep' : 'bg-gray-100 text-gray-500'"
                                                    x-text="window.is_active ? 'Active' : 'Inactive'"
                                                ></span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" @click="openEditForm(window)" class="inline-flex items-center px-3 py-1.5 bg-slate-100 text-slate-700 text-xs font-semibold rounded-md hover:bg-slate-200 transition">
                                                    {{ __('Edit') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="toggleActive(window)"
                                                    class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md transition"
                                                    :class="window.is_active ? 'bg-amber-100 text-amber-800 hover:bg-amber-200' : 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200'"
                                                    x-text="window.is_active ? 'Deactivate' : 'Activate'"
                                                ></button>
                                                <button
                                                    type="button"
                                                    x-show="!window.is_active"
                                                    @click="deleteSchedule(window)"
                                                    class="inline-flex items-center px-3 py-1.5 bg-red-100 text-red-700 text-xs font-semibold rounded-md hover:bg-red-200 transition"
                                                >
                                                    {{ __('Delete') }}
                                                </button>
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Add / Edit modal --}}
            <div
                x-show="showForm"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4"
                @keydown.escape.window="closeForm()"
            >
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" @click.outside="closeForm()">
                    <h3 class="text-lg font-semibold text-slate-900" x-text="editingId ? 'Edit Schedule Window' : 'Add Schedule Window'"></h3>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('Day') }}</label>
                            <select x-model="form.day_of_week" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100">
                                <template x-for="(name, index) in weekdayNames" :key="index">
                                    <option :value="String(index)" x-text="name"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('Start Time') }}</label>
                            <input type="time" x-model="form.start_time" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('End Time') }}</label>
                            <input type="time" x-model="form.end_time" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100" />
                        </div>
                        <p class="text-xs text-slate-400">
                            {{ __('Windows that cross midnight are not supported — create two separate windows instead.') }}
                        </p>
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" @click="closeForm()" class="inline-flex items-center px-4 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-md hover:bg-slate-200 transition">
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="button"
                            @click="submitForm()"
                            :disabled="saving"
                            class="inline-flex items-center px-4 py-2 bg-brand-green text-white text-xs font-semibold rounded-md hover:bg-brand-green-deep transition disabled:opacity-60"
                        >
                            <span x-show="!saving">{{ __('Save') }}</span>
                            <span x-show="saving" x-cloak>{{ __('Saving...') }}</span>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
