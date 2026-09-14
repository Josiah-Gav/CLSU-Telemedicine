<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Scheduled Consultations') }}
        </h2>
    </x-slot>

    <script>
        window.scheduledConsultationData = {
            existingSlots: @json($existingSlots ?? []),
            scheduledConsultations: @json($scheduledConsultations ?? []),
            routes: @json($slotRoutes ?? []),
            physicianId: {{ (int) $physician->user_id }},
        };

        function physicianScheduleManager(initialData) {
            return {
                routes: initialData.routes || {},
                physicianId: initialData.physicianId,
                activeMainTab: 'scheduled',
                activeConsultationTab: 'initial',
                activeSlotsTab: 'upcoming',
                scheduledConsultations: Array.isArray(initialData.scheduledConsultations) ? initialData.scheduledConsultations : [],
                form: {
                    slot_date: '',
                    start_time: '08:00',
                    end_time: '17:00',
                    duration_minutes: '30',
                    break_start_time: '',
                    break_end_time: '',
                },
                generatedSlots: [],
                existingSlots: Array.isArray(initialData.existingSlots) ? initialData.existingSlots : [],
                summary: null,
                generating: false,
                saving: false,
                init() {
                    const today = new Date();
                    const year = today.getFullYear();
                    const month = String(today.getMonth() + 1).padStart(2, '0');
                    const day = String(today.getDate()).padStart(2, '0');

                    this.form.slot_date = `${year}-${month}-${day}`;
                },
                get followUpConsultations() {
                    return this.scheduledConsultations.filter((c) => c.consultation_type === 'follow_up');
                },
                get initialConsultations() {
                    return this.scheduledConsultations.filter((c) => c.consultation_type === 'initial');
                },
                formatScheduledDate(date) {
                    if (!date) return '—';
                    const d = new Date(date + 'T00:00:00');
                    if (Number.isNaN(d.getTime())) return date;
                    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                },
                priorityBadgeClass(priority) {
                    return priority === 'High'
                        ? 'text-red-700 bg-red-100'
                        : 'text-brand-green-deep bg-brand-green-soft';
                },
                startConsultation(consultation) {
                    if (!consultation?.start_url) {
                        Swal.fire('Error', 'Unable to find the start consultation URL.', 'error');
                        return;
                    }

                    Swal.fire({
                        title: 'Start Consultation',
                        text: `Are you sure you want to start the consultation for ${consultation.patient_name}?`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, start it!',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        const csrfToken = $('meta[name="csrf-token"]').attr('content');
                        if (!csrfToken) {
                            Swal.fire('Error', 'Missing CSRF token.', 'error');
                            return;
                        }

                        $.ajax({
                            url: consultation.start_url,
                            type: 'POST',
                            contentType: 'application/json',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            data: JSON.stringify({
                                physician_id: this.physicianId
                            }),
                            dataType: 'json',
                            success: (data) => {
                                if (data.success) {
                                    Swal.fire('Started!', data.message, 'success').then(() => {
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
                    });
                },
                scheduleConsultation(consultation, slotId) {
                    if (!consultation?.schedule_url) {
                        Swal.fire('Error', 'Unable to find the schedule URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: consultation.schedule_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        data: JSON.stringify({
                            physician_id: this.physicianId,
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
                promptReschedule(consultation) {
                    if (!consultation?.available_slots_url) {
                        Swal.fire('Error', 'Unable to load available slots.', 'error');
                        return;
                    }

                    $.ajax({
                        url: consultation.available_slots_url,
                        type: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
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
                                title: 'Reschedule Consultation',
                                text: `Select a new slot for ${consultation.patient_name}.`,
                                input: 'select',
                                inputOptions: options,
                                inputPlaceholder: 'Select an available slot',
                                showCancelButton: true,
                                confirmButtonText: 'Reschedule',
                                inputValidator: (value) => {
                                    if (!value) {
                                        return 'Please select a slot.';
                                    }
                                }
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    this.scheduleConsultation(consultation, result.value);
                                }
                            });
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to load available slots.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                get selectedCount() {
                    return this.generatedSlots.filter((slot) => slot.selected).length;
                },
                isSlotPast(slot) {
                    if (!slot?.slot_date || !slot?.end_time) {
                        return false;
                    }

                    const slotEnd = new Date(`${slot.slot_date}T${slot.end_time}`);
                    if (Number.isNaN(slotEnd.getTime())) {
                        return false;
                    }

                    return slotEnd.getTime() < Date.now();
                },
                get upcomingSlots() {
                    return this.existingSlots.filter((slot) => {
                        if (['missed', 'completed'].includes(slot.status)) {
                            return false;
                        }

                        return !this.isSlotPast(slot);
                    });
                },
                get archivedSlots() {
                    return this.existingSlots.filter((slot) => ['missed', 'completed'].includes(slot.status));
                },
                slotStatusBadgeClass(status) {
                    const classes = {
                        available: 'text-green-700 bg-green-100',
                        booked: 'text-red-700 bg-red-100',
                        missed: 'text-amber-700 bg-amber-100',
                        completed: 'text-slate-700 bg-slate-100',
                    };

                    return classes[status] || 'text-gray-700 bg-gray-100';
                },
                slotStatusLabel(status) {
                    if (!status) {
                        return 'Unknown';
                    }

                    return status.charAt(0).toUpperCase() + status.slice(1);
                },
                toggleAllGenerated(isChecked) {
                    this.generatedSlots = this.generatedSlots.map((slot) => ({
                        ...slot,
                        selected: isChecked,
                    }));
                },
                buildDateTime(date, time) {
                    if (!date || !time) {
                        return null;
                    }

                    const dateTime = new Date(`${date}T${time}`);
                    if (Number.isNaN(dateTime.getTime())) {
                        return null;
                    }

                    return dateTime;
                },
                validateScheduleWindow() {
                    const startAt = this.buildDateTime(this.form.slot_date, this.form.start_time);
                    const endAt = this.buildDateTime(this.form.slot_date, this.form.end_time);

                    if (!startAt || !endAt) {
                        Swal.fire('Invalid Date/Time', 'Please enter a valid date, start time, and end time.', 'error');
                        return false;
                    }

                    if (endAt <= startAt) {
                        Swal.fire('Invalid Time Range', 'End time must be later than start time.', 'error');
                        return false;
                    }

                    if (endAt <= new Date()) {
                        Swal.fire('Past Schedule Not Allowed', 'The selected date and time range is already in the past. Please choose a future schedule window.', 'error');
                        return false;
                    }

                    return true;
                },
                generateSchedule() {
                    if (!this.routes.generate_url) {
                        Swal.fire('Error', 'Generate URL is missing.', 'error');
                        return;
                    }

                    if (!this.validateScheduleWindow()) {
                        return;
                    }

                    this.generating = true;
                    this.summary = null;
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.routes.generate_url,
                        type: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        dataType: 'json',
                        data: {
                            slot_date: this.form.slot_date,
                            start_time: this.form.start_time,
                            end_time: this.form.end_time,
                            duration_minutes: this.form.duration_minutes,
                            break_start_time: this.form.break_start_time || null,
                            break_end_time: this.form.break_end_time || null,
                        },
                        success: (data) => {
                            this.generatedSlots = Array.isArray(data?.slots) ? data.slots.map((slot) => ({
                                ...slot,
                                selected: true,
                            })) : [];

                            this.summary = data?.summary || null;

                            if (!this.generatedSlots.length) {
                                Swal.fire('No Slots Generated', 'No available slots were generated for the chosen range.', 'info');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to generate schedule.';
                            Swal.fire('Error', message, 'error');
                        },
                        complete: () => {
                            this.generating = false;
                        }
                    });
                },
                saveSchedule() {
                    if (!this.routes.save_url) {
                        Swal.fire('Error', 'Save URL is missing.', 'error');
                        return;
                    }

                    const selectedSlots = this.generatedSlots
                        .filter((slot) => slot.selected)
                        .map((slot) => ({
                            start_time: slot.start_time,
                            end_time: slot.end_time,
                        }));

                    if (!selectedSlots.length) {
                        Swal.fire('No Slots Selected', 'Select at least one generated slot to save.', 'warning');
                        return;
                    }

                    this.saving = true;
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.routes.save_url,
                        type: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        dataType: 'json',
                        data: {
                            slot_date: this.form.slot_date,
                            slots: selectedSlots,
                        },
                        success: (data) => {
                            this.existingSlots = Array.isArray(data?.slots) ? data.slots : this.existingSlots;
                            this.generatedSlots = [];
                            this.summary = null;

                            const savedCount = data?.summary?.saved_count ?? 0;
                            const skippedCount = data?.summary?.skipped_by_conflict ?? 0;
                            const skippedPastCount = data?.summary?.skipped_by_past ?? 0;
                            const skippedPastText = skippedPastCount ? ` ${skippedPastCount} already-passed slot(s) were also skipped.` : '';
                            Swal.fire(
                                'Saved',
                                `Saved ${savedCount} slot(s). Skipped ${skippedCount} conflicting slot(s).${skippedPastText}`,
                                'success'
                            );
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to save generated slots.';
                            Swal.fire('Error', message, 'error');
                        },
                        complete: () => {
                            this.saving = false;
                        }
                    });
                }
            };
        }
    </script>

    <div class="py-12" x-data="physicianScheduleManager(window.scheduledConsultationData)" x-init="init()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Main Tabs -->
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-2 shadow-sm">
                <button
                    type="button"
                    @click="activeMainTab = 'scheduled'"
                    :class="activeMainTab === 'scheduled' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                    class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                >
                    {{ __('Scheduled Consultations') }}
                </button>
                <button
                    type="button"
                    @click="activeMainTab = 'availability'"
                    :class="activeMainTab === 'availability' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                    class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                >
                    {{ __('Schedule Availability') }}
                </button>
            </div>

            <!-- ===================== SCHEDULED CONSULTATIONS TAB ===================== -->
            <div x-show="activeMainTab === 'scheduled'" x-cloak class="mt-4">
                <!-- Sub-tabs: Follow-up / Initial -->
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2">
                    <button
                        type="button"
                        @click="activeConsultationTab = 'follow_up'"
                        :class="activeConsultationTab === 'follow_up' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                        class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                    >
                        {{ __('Follow-up Consultations') }} (<span x-text="followUpConsultations.length"></span>)
                    </button>
                    <button
                        type="button"
                        @click="activeConsultationTab = 'initial'"
                        :class="activeConsultationTab === 'initial' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                        class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                    >
                        {{ __('Initial Consultations') }} (<span x-text="initialConsultations.length"></span>)
                    </button>
                </div>

                <!-- Follow-up Consultations Table -->
                <div x-show="activeConsultationTab === 'follow_up'" x-cloak class="mt-4 bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold text-slate-900">{{ __('Follow-up Consultations') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Scheduled follow-up consultations with their scheduled times.') }}</p>

                        <template x-if="followUpConsultations.length === 0">
                            <p class="mt-4 text-sm text-slate-500">{{ __('No scheduled follow-up consultations.') }}</p>
                        </template>

                        {{-- Phase 3: 6 columns including a 2-action cell,
                             no room at 375/390px. Same Alpine array and
                             the same @click handlers as the desktop table
                             below — reuses the mobile card pattern already
                             established in physician/consultation_inbox.blade.php. --}}
                        <div x-show="followUpConsultations.length > 0" x-cloak class="mt-4 space-y-3 sm:hidden">
                            <template x-for="consultation in followUpConsultations" :key="`follow-up-mobile-${consultation.request_id}`">
                                <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="inline-flex min-w-0 items-center gap-2">
                                            <span class="inline-block h-[0.625em] w-[0.625em] shrink-0 rounded-full" :class="consultation.patient_is_online ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                                            <span class="truncate text-sm font-medium text-gray-900" x-text="consultation.patient_name"></span>
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">{{ __('Scheduled') }}</span>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="priorityBadgeClass(consultation.priority_level)" x-text="consultation.priority_level ?? 'Normal'"></span>
                                        <span class="text-xs text-gray-500" x-text="formatScheduledDate(consultation.scheduled_date) + ' · ' + (consultation.scheduled_time_label ?? '—')"></span>
                                    </div>

                                    <div class="mt-4 flex gap-2">
                                        <template x-if="consultation.slot_status === 'missed'">
                                            <x-button-secondary class="flex-1" @click="promptReschedule(consultation)">
                                                {{ __('Reschedule') }}
                                            </x-button-secondary>
                                        </template>
                                        <x-button-primary class="flex-1" @click="startConsultation(consultation)">
                                            {{ __('Start') }}
                                        </x-button-primary>
                                    </div>
                                </article>
                            </template>
                        </div>

                        <div class="mt-4 hidden overflow-x-auto sm:block" x-show="followUpConsultations.length > 0" x-cloak>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Scheduled Date') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Scheduled Time') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="consultation in followUpConsultations" :key="`follow-up-${consultation.request_id}`">
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="inline-flex items-center gap-2">
                                                    <span class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0" :class="consultation.patient_is_online ? 'bg-emerald-500' : 'bg-slate-300'" :title="consultation.patient_is_online ? 'Online' : 'Offline'"></span>
                                                    <span x-text="consultation.patient_name"></span>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="formatScheduledDate(consultation.scheduled_date)"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.scheduled_time_label ?? '—'"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="priorityBadgeClass(consultation.priority_level)" x-text="consultation.priority_level ?? 'Normal'"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">{{ __('Scheduled') }}</span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <div class="inline-flex items-center gap-2">
                                                    <template x-if="consultation.slot_status === 'missed'">
                                                        <x-button-secondary size="sm" @click="promptReschedule(consultation)">
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                            </svg>
                                                            <span>{{ __('Reschedule') }}</span>
                                                        </x-button-secondary>
                                                    </template>
                                                    <x-button-primary size="sm" @click="startConsultation(consultation)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                                        </svg>
                                                        <span>{{ __('Start') }}</span>
                                                    </x-button-primary>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Initial Consultations Table -->
                <div x-show="activeConsultationTab === 'initial'" x-cloak class="mt-4 bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold text-slate-900">{{ __('Initial Consultations') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Scheduled initial consultations with their scheduled times.') }}</p>

                        <template x-if="initialConsultations.length === 0">
                            <p class="mt-4 text-sm text-slate-500">{{ __('No scheduled initial consultations.') }}</p>
                        </template>

                        {{-- Phase 3: same mobile-card treatment as the
                             Follow-up Consultations table above — identical
                             columns/actions, different Alpine array. --}}
                        <div x-show="initialConsultations.length > 0" x-cloak class="mt-4 space-y-3 sm:hidden">
                            <template x-for="consultation in initialConsultations" :key="`initial-mobile-${consultation.request_id}`">
                                <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="inline-flex min-w-0 items-center gap-2">
                                            <span class="inline-block h-[0.625em] w-[0.625em] shrink-0 rounded-full" :class="consultation.patient_is_online ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                                            <span class="truncate text-sm font-medium text-gray-900" x-text="consultation.patient_name"></span>
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">{{ __('Scheduled') }}</span>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="priorityBadgeClass(consultation.priority_level)" x-text="consultation.priority_level ?? 'Normal'"></span>
                                        <span class="text-xs text-gray-500" x-text="formatScheduledDate(consultation.scheduled_date) + ' · ' + (consultation.scheduled_time_label ?? '—')"></span>
                                    </div>

                                    <div class="mt-4 flex gap-2">
                                        <template x-if="consultation.slot_status === 'missed'">
                                            <x-button-secondary class="flex-1" @click="promptReschedule(consultation)">
                                                {{ __('Reschedule') }}
                                            </x-button-secondary>
                                        </template>
                                        <x-button-primary class="flex-1" @click="startConsultation(consultation)">
                                            {{ __('Start') }}
                                        </x-button-primary>
                                    </div>
                                </article>
                            </template>
                        </div>

                        <div class="mt-4 hidden overflow-x-auto sm:block" x-show="initialConsultations.length > 0" x-cloak>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Scheduled Date') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Scheduled Time') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="consultation in initialConsultations" :key="`initial-${consultation.request_id}`">
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="inline-flex items-center gap-2">
                                                    <span class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0" :class="consultation.patient_is_online ? 'bg-emerald-500' : 'bg-slate-300'" :title="consultation.patient_is_online ? 'Online' : 'Offline'"></span>
                                                    <span x-text="consultation.patient_name"></span>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="formatScheduledDate(consultation.scheduled_date)"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.scheduled_time_label ?? '—'"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="priorityBadgeClass(consultation.priority_level)" x-text="consultation.priority_level ?? 'Normal'"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">{{ __('Scheduled') }}</span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <div class="inline-flex items-center gap-2">
                                                    <template x-if="consultation.slot_status === 'missed'">
                                                        <x-button-secondary size="sm" @click="promptReschedule(consultation)">
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                            </svg>
                                                            <span>{{ __('Reschedule') }}</span>
                                                        </x-button-secondary>
                                                    </template>
                                                    <x-button-primary size="sm" @click="startConsultation(consultation)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                                        </svg>
                                                        <span>{{ __('Start') }}</span>
                                                    </x-button-primary>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== SCHEDULE AVAILABILITY TAB ===================== -->
            <div x-show="activeMainTab === 'availability'" x-cloak class="space-y-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold text-slate-900">{{ __('Create Schedule Availability') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Generate appointment slots using one date, working hours, duration, and an optional break.') }}</p>

                        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('Date') }}</label>
                                <input type="date" x-model="form.slot_date" min="{{ now()->toDateString() }}" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('Start Time') }}</label>
                                <input type="time" x-model="form.start_time" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('End Time') }}</label>
                                <input type="time" x-model="form.end_time" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('Appointment Duration') }}</label>
                                <select x-model="form.duration_minutes" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100">
                                    <option value="15">15 minutes</option>
                                    <option value="30">30 minutes</option>
                                    <option value="45">45 minutes</option>
                                    <option value="60">60 minutes</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('Break Start (Optional)') }}</label>
                                <input type="time" x-model="form.break_start_time" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">{{ __('Break End (Optional)') }}</label>
                                <input type="time" x-model="form.break_end_time" class="block w-full rounded-md border-gray-300 focus:border-brand-green focus:ring-green-100" />
                            </div>
                        </div>

                        <div class="mt-6">
                            {{-- x-bind:disabled, not the bare :disabled shorthand — Blade
                                 intercepts a bare leading colon on a <x-component> tag as
                                 its own PHP-expression prop binding, which would try (and
                                 fail) to evaluate a PHP variable named $generating. See
                                 button-primary.blade.php's docblock. --}}
                            <x-button-primary @click="generateSchedule()" x-bind:disabled="generating">
                                <span x-show="!generating">{{ __('Generate Schedule') }}</span>
                                <span x-show="generating" x-cloak>{{ __('Generating...') }}</span>
                            </x-button-primary>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" x-show="generatedSlots.length > 0" x-cloak>
                    <div class="p-6 text-gray-900">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-slate-900">{{ __('Generated Slots Preview') }}</h3>
                            <div class="text-sm text-slate-600">
                                {{ __('Selected:') }} <span class="font-semibold" x-text="selectedCount"></span>
                            </div>
                        </div>

                        <div class="mt-3 text-sm text-slate-600" x-show="summary">
                            <span>{{ __('Generated:') }} <span class="font-semibold" x-text="summary?.generated_count ?? 0"></span></span>
                            <span class="ml-4">{{ __('Skipped by break:') }} <span class="font-semibold" x-text="summary?.skipped_by_break ?? 0"></span></span>
                            <span class="ml-4">{{ __('Skipped by existing conflict:') }} <span class="font-semibold" x-text="summary?.skipped_by_conflict ?? 0"></span></span>
                            <span class="ml-4">{{ __('Skipped (already passed):') }} <span class="font-semibold" x-text="summary?.skipped_by_past ?? 0"></span></span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-button-secondary size="sm" @click="toggleAllGenerated(true)">{{ __('Select All') }}</x-button-secondary>
                            <x-button-secondary size="sm" @click="toggleAllGenerated(false)">{{ __('Unselect All') }}</x-button-secondary>
                        </div>

                        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <template x-for="(slot, index) in generatedSlots" :key="`${slot.start_time}-${slot.end_time}-${index}`">
                                <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 hover:bg-slate-50">
                                    <input type="checkbox" x-model="slot.selected" class="rounded border-gray-300 text-brand-green focus:ring-green-100" />
                                    <span class="text-sm font-medium text-slate-700" x-text="slot.label"></span>
                                </label>
                            </template>
                        </div>

                        <div class="mt-6">
                            <x-button-primary @click="saveSchedule()" x-bind:disabled="saving">
                                <span x-show="!saving">{{ __('Save Selected Slots') }}</span>
                                <span x-show="saving" x-cloak>{{ __('Saving...') }}</span>
                            </x-button-primary>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold text-slate-900">{{ __('Saved Schedule Slots') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Manage upcoming slots and review completed or missed slot outcomes.') }}</p>

                        <div class="mt-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2">
                            <button
                                type="button"
                                @click="activeSlotsTab = 'upcoming'"
                                :class="activeSlotsTab === 'upcoming' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                                class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                            >
                                {{ __('Upcoming Slots') }} (<span x-text="upcomingSlots.length"></span>)
                            </button>
                            <button
                                type="button"
                                @click="activeSlotsTab = 'history'"
                                :class="activeSlotsTab === 'history' ? 'bg-slate-700 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                                class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                            >
                                {{ __('Completed & Missed') }} (<span x-text="archivedSlots.length"></span>)
                            </button>
                        </div>

                        <p class="mt-4 text-sm text-slate-500" x-show="activeSlotsTab === 'upcoming' && upcomingSlots.length === 0">{{ __('No upcoming slots yet.') }}</p>
                        <p class="mt-4 text-sm text-slate-500" x-show="activeSlotsTab === 'history' && archivedSlots.length === 0">{{ __('No completed or missed slots yet.') }}</p>

                        <div class="mt-4 overflow-x-auto" x-show="activeSlotsTab === 'upcoming' && upcomingSlots.length > 0" x-cloak>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Date') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Time') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="slot in upcomingSlots" :key="`upcoming-${slot.slot_id}`">
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="slot.slot_date"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="slot.label"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
                                                    :class="slotStatusBadgeClass(slot.status)"
                                                    x-text="slotStatusLabel(slot.status)"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 overflow-x-auto" x-show="activeSlotsTab === 'history' && archivedSlots.length > 0" x-cloak>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Date') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Time') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="slot in archivedSlots" :key="`history-${slot.slot_id}`">
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="slot.slot_date"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="slot.label"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
                                                    :class="slotStatusBadgeClass(slot.status)"
                                                    x-text="slotStatusLabel(slot.status)"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <template x-if="slot.status === 'completed' && slot.messaging_url">
                                                    <a
                                                        :href="slot.messaging_url"
                                                        class="inline-flex items-center gap-1 rounded-md bg-slate-700 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                                                        aria-label="View consultation record"
                                                    >
                                                        <span>{{ __('View') }}</span>
                                                    </a>
                                                </template>
                                                <template x-if="slot.status === 'completed' && !slot.messaging_url">
                                                    <span class="text-xs text-gray-500">{{ __('Session unavailable') }}</span>
                                                </template>
                                                <template x-if="slot.status !== 'completed'">
                                                    <span class="text-xs text-gray-400">-</span>
                                                </template>
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
    </div>
</x-app-layout>