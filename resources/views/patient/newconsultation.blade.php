<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('New Consultation') }}
        </h2>
    </x-slot>

<!-- STEP 1 PATIENT INFORMATION -->

    <!-- UPDATED: Added isSubmitting state and a submitForm() function into Alpine's x-data -->
    <div x-data="{ 
        currentStep: 1, 
        selectedType: 'general', 
        selectedSymptoms: [], 
        uploadedFiles: [],
        filePreviews: [],
        otherSymptom: '', 
        customSymptomInput: '', 
        showCustomSymptomInput: false,
        isSubmitting: false,
        {{-- Server-rendered service-level state (never per-physician detail) —
             see ConsultationController::create()/DashboardController::newconsultation().
             This only informs the UI; the backend gate in store() is what
             actually enforces it, and a 503 response there flips this back to
             false so a stale page cannot keep offering a submission it knows
             will fail. --}}
        intakeAvailable: @json($intakeAvailable ?? true),
        selectedSymptomsDisplay() { return this.selectedSymptoms.map(s => s.name).join(', '); }, 
        selectedSymptomsPhrase() { return this.selectedSymptoms.length === 1 ? this.selectedSymptoms[0].name.toLowerCase() : this.selectedSymptoms.map(s => s.name.toLowerCase()).join(', '); }, 
        isSymptomSelected(symptom) { return this.selectedSymptoms.some(s => s.name === symptom); }, 
        hasCustomSymptoms() { return this.selectedSymptoms.some(s => s.custom); }, 
        addCustomSymptom() { const value = this.customSymptomInput.trim(); if (!value) { return; } if (!this.selectedSymptoms.some(s => s.name.toLowerCase() === value.toLowerCase())) { this.selectedSymptoms.push({ name: value, date: '', time: '', severity: 3, custom: true }); } this.customSymptomInput = ''; this.showCustomSymptomInput = true; }, 
        removeSymptom(name) { const index = this.selectedSymptoms.findIndex(s => s.name === name); if (index > -1) { this.selectedSymptoms.splice(index, 1); } }, 
        toggleSymptom(symptom) { const index = this.selectedSymptoms.findIndex(s => s.name === symptom); if (index > -1) { this.selectedSymptoms.splice(index, 1); } else { this.selectedSymptoms.push({ name: symptom, date: '', time: '', severity: 3 }); } },
        handleFiles(event) {
            const files = Array.from(event.target.files || []);
            this.uploadedFiles = files;
            this.filePreviews = [];
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.filePreviews.push(e.target.result);
                };
                reader.readAsDataURL(file);
            }
        },
        removeFile(index) {
            if (index < 0 || index >= this.uploadedFiles.length) return;
            this.uploadedFiles.splice(index, 1);
            this.filePreviews.splice(index, 1);
            // Clear the input value so user can re-add same filename if desired
            if (this.$refs && this.$refs.attachmentsInput) {
                // If there are remaining files we can't set input.files directly; recreate a DataTransfer if supported
                try {
                    const dt = new DataTransfer();
                    this.uploadedFiles.forEach(f => dt.items.add(f));
                    this.$refs.attachmentsInput.files = dt.files;
                } catch (e) {
                    // Fallback: clear input when any file removed to avoid stale FileList issues
                    if (this.uploadedFiles.length === 0) {
                        this.$refs.attachmentsInput.value = null;
                    }
                }
            }
        },
        validationError(message) {
            alert(message);
        },
        // Mirrors the date input's max attribute and the server-side check in
        // ConsultationController::store — a symptom's onset can be any time up
        // to now, never later. Date/time stay optional; an empty date skips
        // the check entirely.
        symptomIsInFuture(symptom) {
            if (!symptom.date) return false;
            return new Date(`${symptom.date}T${symptom.time || '00:00'}`) > new Date();
        },
        firstFutureSymptom() {
            return this.selectedSymptoms.find(s => this.symptomIsInFuture(s));
        },
        canAdvanceToStep(step) {
            if (step >= 2 && !this.intakeAvailable) {
                this.validationError('Consultation intake is currently unavailable. Please try again later.');
                return false;
            }
            if (step === 3 && this.selectedSymptoms.length === 0) {
                this.validationError('Please add at least one symptom before proceeding to additional details.');
                return false;
            }
            if (step === 3) {
                const futureSymptom = this.firstFutureSymptom();
                if (futureSymptom) {
                    this.validationError(`The onset date/time for '${futureSymptom.name}' cannot be in the future.`);
                    return false;
                }
            }
            if (step === 4) {
                const reason = this.$refs.consultationForm.querySelector('[name=&quot;online_reason&quot;]')?.value?.trim() || '';
                if (!reason) {
                    this.validationError('Please provide a reason for seeking online consultation before reviewing your request.');
                    return false;
                }
            }
            return true;
        },
        goToStep(step) {
            if (this.currentStep < 5 && this.canAdvanceToStep(step)) {
                this.currentStep = step;
            }
        },
        submitForm() {
            console.log('submitForm: start');
            this.isSubmitting = true;
            try {
                if (!this.intakeAvailable) {
                    this.validationError('Consultations are currently unavailable. Please try again later.');
                    this.isSubmitting = false;
                    return;
                }

                if (this.selectedSymptoms.length === 0) {
                    this.validationError('You must provide at least one symptom before submitting your consultation request.');
                    this.isSubmitting = false;
                    return;
                }

                const futureSymptom = this.firstFutureSymptom();
                if (futureSymptom) {
                    this.validationError(`The onset date/time for '${futureSymptom.name}' cannot be in the future.`);
                    this.isSubmitting = false;
                    return;
                }

                const onlineReason = this.$refs.consultationForm.querySelector('[name=&quot;online_reason&quot;]')?.value?.trim() || '';
                if (!onlineReason) {
                    this.validationError('You must provide a reason for seeking online consultation before submitting.');
                    this.isSubmitting = false;
                    return;
                }

                let formElement = this.$refs.consultationForm;
                let formData = new FormData(formElement);

                formData.delete(&quot;attachments[]&quot;); 
                this.uploadedFiles.forEach(file => {
                    formData.append('attachments[]', file);
                });

                const csrfToken = document.querySelector('meta[name=&quot;csrf-token&quot;]')?.getAttribute('content')
                    || formElement.querySelector('input[name=&quot;_token&quot;]')?.value;

                fetch(formElement.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken || ''
                    },
                    credentials: 'same-origin'
                })
                .then(async response => {
                    const text = await response.text();
                    let data = {};

                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        data = { success: false, message: text || 'Request failed.' };
                    }

                    if (!response.ok) {
                        // status carried on the Error so the catch handler
                        // below can recognize the intake-unavailable case
                        // without matching on message text.
                        const error = new Error(data.message || 'Request failed.');
                        error.status = response.status;
                        throw error;
                    }

                    return data;
                })
                .then(data => {
                // On success, advance to the submitted step and show a notification (or alert fallback)
                if (data.success) {
                        this.isSubmitting = false;
                        this.currentStep = 5;

                    if ('Notification' in window) {
                        if (Notification.permission === 'granted') {
                            new Notification('Consultation Submitted', { body: 'Your consultation was submitted successfully.' });
                        } else if (Notification.permission !== 'denied') {
                            Notification.requestPermission().then(permission => {
                                if (permission === 'granted') {
                                    new Notification('Consultation Submitted', { body: 'Your consultation was submitted successfully.' });
                                } else {
                                    alert('Uploaded and Saved successfully!');
                                }
                            }).catch(() => {
                                alert('Uploaded and Saved successfully!');
                            });
                        } else {
                            alert('Uploaded and Saved successfully!');
                        }
                    } else {
                        alert('Uploaded and Saved successfully!');
                    }
                    } else {
                        this.isSubmitting = false;
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    this.isSubmitting = false;

                    // The service was available when the page loaded but is
                    // not any more (a physician closed intake, or the queue
                    // filled, between then and now). The 503 status itself is
                    // the signal — store() returns 503 only for this reason —
                    // never the message text. Flips the indicator and disables
                    // the submit button; the patient must reload the page to
                    // try again, which re-checks availability fresh.
                    if (err.status === 503) {
                        this.intakeAvailable = false;
                    }

                    console.error('submitForm: fetch error', err);
                    alert('Error: ' + err.message);
                });
            } catch (err) {
                this.isSubmitting = false;
                console.error('submitForm: runtime error', err);
                alert('Error: ' + (err && err.message ? err.message : 'Unexpected error'));
            }
        }
    }" class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- ADDED: x-ref and @submit.prevent linking to our Alpine submission engine -->
            <form action="{{ route('consultations.store') }}" method="POST" x-ref="consultationForm" enctype="multipart/form-data" @submit.prevent="submitForm()">
                @csrf
                
                <input type="hidden" name="symptoms_payload" :value="JSON.stringify(selectedSymptoms)">
                <input type="hidden" name="concern_category" :value="selectedType">

                <!-- Service-level availability, not physician/nurse/session status.
                     Informational only — the backend gate in ConsultationController::store()
                     is authoritative and re-checks this on every submission. -->
                <div
                    class="mb-6 flex items-start gap-3 rounded-2xl border p-4"
                    :class="intakeAvailable ? 'border-brand-green bg-brand-green-soft' : 'border-amber-300 bg-amber-50'"
                >
                    <span
                        class="mt-1 inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full"
                        :class="intakeAvailable ? 'bg-brand-green' : 'bg-amber-500'"
                    ></span>
                    <div>
                        <p
                            class="text-sm font-semibold"
                            :class="intakeAvailable ? 'text-brand-green-deep' : 'text-amber-800'"
                            x-text="intakeAvailable ? 'Consultations Available' : 'Consultations Currently Unavailable'"
                        ></p>
                        <p
                            class="mt-0.5 text-xs"
                            :class="intakeAvailable ? 'text-brand-green-deep/80' : 'text-amber-700'"
                            x-text="intakeAvailable
                                ? 'You can submit a new consultation request.'
                                : 'New consultation requests are temporarily unavailable. Please try again later.'"
                        ></p>
                    </div>
                </div>


                <!-- STEP NAVIGATION BULLETS -->
                <div class="mb-6 rounded-3xl border border-gray-200 bg-slate-50 p-4 shadow-sm">
                    <div class="grid gap-3 sm:grid-cols-5">
                        <!-- Disable header step jumps if we are already submitted on step 5 -->
                        <button type="button" @click="if(currentStep < 5) goToStep(1)" :class="currentStep === 1 ? 'border-brand-green bg-white shadow-sm' : 'border-transparent bg-slate-50'" class="flex items-start gap-3 rounded-3xl border p-4 text-left transition">
                            <span :class="currentStep === 1 ? 'bg-brand-green text-white' : 'bg-slate-200 text-slate-700'" class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold">1</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Patient Information</p>
                                <p class="mt-1 text-sm font-semibold" :class="currentStep === 1 ? 'text-slate-900' : 'text-slate-500'">Provide your details</p>
                            </div>
                        </button>
                        <button type="button" @click="if(currentStep < 5) goToStep(2)" :class="currentStep === 2 ? 'border-brand-green bg-white shadow-sm' : 'border-transparent bg-slate-50'" class="flex items-start gap-3 rounded-3xl border p-4 text-left transition">
                            <span :class="currentStep === 2 ? 'bg-brand-green text-white' : 'bg-slate-200 text-slate-700'" class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold">2</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Symptoms</p>
                                <p class="mt-1 text-sm font-semibold" :class="currentStep === 2 ? 'text-slate-900' : 'text-slate-500'">Describe your condition</p>
                            </div>
                        </button>
                        <button type="button" @click="if(currentStep < 5) goToStep(3)" :class="currentStep === 3 ? 'border-brand-green bg-white shadow-sm' : 'border-transparent bg-slate-50'" class="flex items-start gap-3 rounded-3xl border p-4 text-left transition">
                            <span :class="currentStep === 3 ? 'bg-brand-green text-white' : 'bg-slate-200 text-slate-700'" class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold">3</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Additional Details</p>
                                <p class="mt-1 text-sm font-semibold" :class="currentStep === 3 ? 'text-slate-900' : 'text-slate-500'">Add other information</p>
                            </div>
                        </button>
                        <button type="button" @click="if(currentStep < 5) goToStep(4)" :class="currentStep === 4 ? 'border-brand-green bg-white shadow-sm' : 'border-transparent bg-slate-50'" class="flex items-start gap-3 rounded-3xl border p-4 text-left transition">
                            <span :class="currentStep === 4 ? 'bg-brand-green text-white' : 'bg-slate-200 text-slate-700'" class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold">4</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Review</p>
                                <p class="mt-1 text-sm font-semibold" :class="currentStep === 4 ? 'text-slate-900' : 'text-slate-500'">Review your request</p>
                            </div>
                        </button>
                        <button type="button" disabled :class="currentStep === 5 ? 'border-brand-green bg-white shadow-sm' : 'border-transparent bg-slate-50'" class="flex items-start gap-3 rounded-3xl border p-4 text-left transition opacity-80">
                            <span :class="currentStep === 5 ? 'bg-brand-green text-white' : 'bg-slate-200 text-slate-700'" class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold">5</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Submitted</p>
                                <p class="mt-1 text-sm font-semibold" :class="currentStep === 5 ? 'text-slate-900' : 'text-slate-500'">Request will be reviewed</p>
                            </div>
                        </button>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="p-6 md:p-8 space-y-6">
                        
                        <!-- CHANGER: Show static patient profile card only on step 1 -->
                        <div class="space-y-4" x-show="currentStep === 1">
                            <div class="pb-4 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Patient Information</h3>
                                <p class="mt-1 text-sm text-gray-500">Please confirm your details before proceeding.</p>
                            </div>

                            <div class="grid gap-4 md:grid-cols-3">
                                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Full Name</p>
                                    <p class="mt-2 text-sm font-semibold text-gray-900">{{ $patient->first_name ?? 'First Name' }} {{ $patient->last_name ?? 'Last Name' }}</p>
                                </div>
                                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Student ID Number</p>
                                    <p class="mt-2 text-sm font-semibold text-gray-900">{{ $patient->clsu_id ?? 'Student ID Number' }}</p>
                                </div>
                                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Program</p>
                                    <p class="mt-2 text-sm font-semibold text-gray-900">{{ $patient->department ?? 'Course & Year' }}</p>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-green-200 bg-green-50 p-4">
                                <div class="flex items-start gap-3">
                                    <div class="mt-1 flex h-8 w-8 items-center justify-center rounded-full bg-white text-green-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M16.704 5.296a1 1 0 010 1.414l-7.07 7.07a1 1 0 01-1.415 0l-3.536-3.536a1 1 0 011.414-1.414l2.829 2.828 6.364-6.364a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-green-800">{{ ($patient->profile_verified ?? false) ? 'Your profile information has been verified.' : 'Please verify your profile information.' }}</p>
                                        <p class="mt-1 text-sm text-green-700">If any information is incorrect, please update it in your profile settings.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 1 VIEW PANEL -->
                        <div x-show="currentStep === 1" x-cloak class="space-y-4">
                            <div class="pb-4 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Consultation Type</h3>
                                <p class="mt-1 text-sm text-gray-500">Select the type of consultation you need.</p>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <button type="button" @click="selectedType = 'general'; goToStep(2)" :class="['group flex items-center justify-between rounded-3xl p-6 text-left transition', selectedType === 'general' ? 'border-green-200 bg-green-50 hover:border-green-300 hover:bg-green-100' : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50']">
                                    <div>
                                        <div :class="['flex items-center gap-3', selectedType === 'general' ? 'text-green-600' : 'text-gray-500']">
                                            <div :class="['rounded-2xl p-3', selectedType === 'general' ? 'bg-white' : 'bg-gray-100']">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m2 4H7m0-8h10M5 6h14M5 18h14" />
                                                </svg>
                                            </div>
                                            <p class="text-base font-semibold text-gray-900">New Consultation</p>
                                        </div>
                                        <p class="mt-3 text-sm text-gray-600">For general health concerns, minor illnesses, and preventive care.</p>
                                    </div>
                                    <div :class="['flex h-9 w-9 items-center justify-center rounded-full shadow-sm', selectedType === 'general' ? 'bg-white text-green-600' : 'bg-gray-100 text-gray-400']">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" :fill="selectedType === 'general' ? 'currentColor' : 'none'" :stroke="selectedType === 'general' ? 'none' : 'currentColor'">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 00-1.414 0L9 11.586 6.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l7-7a1 1 0 000-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </button>

                                <button type="button" @click="window.location.href = '{{ route('patient.follow_up_list') }}'" :class="['group flex items-center justify-between rounded-3xl p-6 text-left transition', selectedType === 'followup' ? 'border-brand-border bg-brand-green-soft hover:border-brand-green hover:bg-brand-green-soft' : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50']">
                                    <div>
                                        <div :class="['flex items-center gap-3', selectedType === 'followup' ? 'text-brand-green' : 'text-gray-500']">
                                            <div :class="['rounded-2xl p-3', selectedType === 'followup' ? 'bg-white' : 'bg-gray-100']">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <p class="text-base font-semibold text-gray-900">Follow-up Consultation</p>
                                        </div>
                                        <p class="mt-3 text-sm text-gray-600">For follow-up checkups or continuation of previous consultation.</p>
                                    </div>
                                        <div :class="['flex h-9 w-9 items-center justify-center rounded-full shadow-sm', selectedType === 'followup' ? 'bg-white text-brand-green' : 'bg-gray-100 text-gray-400']">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" :fill="selectedType === 'followup' ? 'currentColor' : 'none'" :stroke="selectedType === 'followup' ? 'none' : 'currentColor'">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 00-1.414 0L9 11.586 6.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l7-7a1 1 0 000-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </button>
                            </div>

                            <div class="rounded-2xl border border-brand-border bg-brand-green-soft p-4">
                                <div class="flex items-start gap-3">
                                    <div class="mt-1 text-brand-green-deep">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-8 4a.75.75 0 01.75.75v.75a.75.75 0 01-1.5 0v-.75A.75.75 0 0110 14zm0-3a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5A.75.75 0 0110 11z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-brand-green-deep">To avoid duplicate requests, please check your active consultations first.</p>
                                        <p class="mt-1 text-sm text-brand-green">If your concern is related to a previous consultation, you may reopen it instead.</p>
                                    </div>
                                </div>
                                <div class="mt-4 text-right">
                                    <a href="{{ route('patient.follow_up_list') }}" class="inline-flex items-center rounded-full border border-brand-border bg-white px-4 py-2 text-sm font-semibold text-brand-green hover:bg-brand-green-soft">Go to Follow-up List</a>
                                </div>
                            </div>
                        </div>

<!-- STEP 2 SYMPTOMS INTAKE -->

                        <div x-show="currentStep === 2" x-cloak class="space-y-4">
                            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                                <div class="pb-4 border-b border-gray-200">
                                    <h3 class="text-lg font-semibold text-gray-900">Symptoms</h3>
                                    <p class="mt-1 text-sm text-gray-500" x-text="selectedSymptoms.length ? 'You have selected ' + selectedSymptoms.length + ' ' + (selectedSymptoms.length === 1 ? 'symptom' : 'symptoms') + ': ' + selectedSymptomsDisplay() : 'Please describe your current condition by selecting your symptoms and when they started.'"></p>
                                </div>

                                <div class="mt-6 grid gap-4 md:grid-cols-2">
                                    <div class="space-y-4">
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <template x-for="symptom in ['Headache', 'Fever', 'Cough', 'Sore Throat', 'Body Pain', 'Fatigue', 'Nausea / Vomiting', 'Diarrhea', 'Runny Nose', 'Shortness of Breath', 'Loss of Appetite', 'Abdominal Pain', 'Others']" :key="symptom">
                                                <button type="button" @click="symptom === 'Others' ? showCustomSymptomInput = !showCustomSymptomInput : toggleSymptom(symptom)" :class="symptom === 'Others' ? (showCustomSymptomInput || hasCustomSymptoms() ? 'border-green-200 bg-green-50 text-slate-900' : 'border-gray-200 bg-white text-slate-700') : (isSymptomSelected(symptom) ? 'border-green-200 bg-green-50 text-slate-900' : 'border-gray-200 bg-white text-slate-700')" class="flex items-center gap-3 rounded-3xl border p-4 text-left transition hover:border-slate-300 hover:bg-slate-50">
                                                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-100 text-slate-600">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                    </div>
                                                    <div class="flex-1">
                                                        <span class="text-sm font-semibold" x-text="symptom"></span>
                                                        <span x-show="symptom === 'Others'" class="block text-xs text-slate-500">Add symptoms not listed above</span>
                                                    </div>
                                                    <span x-show="symptom !== 'Others' ? isSymptomSelected(symptom) : (showCustomSymptomInput || hasCustomSymptoms())" class="flex h-6 w-6 items-center justify-center rounded-full bg-green-600 text-white">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 00-1.414 0L9 11.586 6.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l7-7a1 1 0 000-1.414z" clip-rule="evenodd" />
                                                        </svg>
                                                    </span>
                                                </button>
                                            </template>
                                        </div>

                                        <div x-show="showCustomSymptomInput" x-cloak class="rounded-2xl border border-gray-200 bg-white p-4">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <p class="text-sm font-semibold text-slate-900">Add custom symptom</p>
                                                    <p class="mt-1 text-sm text-slate-500">Enter a symptom not listed above. You can add multiple entries.</p>
                                                </div>
                                                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500" x-text="hasCustomSymptoms() ? selectedSymptoms.filter(s => s.custom).length + ' added' : 'none added'"></span>
                                            </div>
                                            <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                                                <input type="text" x-model="customSymptomInput" placeholder="e.g. dizziness" class="grow rounded-2xl border border-gray-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 focus:border-brand-green focus:outline-none" />
                                                <button type="button" @click="addCustomSymptom()" class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">Add</button>
                                            </div>
                                            <div class="mt-4 flex flex-wrap gap-2">
                                                <template x-for="symptom in selectedSymptoms.filter(s => s.custom)" :key="symptom.name">
                                                    <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700">
                                                        <span x-text="symptom.name"></span>
                                                        <button type="button" @click="removeSymptom(symptom.name)" class="rounded-full bg-slate-200 px-2 text-xs text-slate-500 hover:bg-slate-300">×</button>
                                                    </span>
                                                </template>
                                            </div>
                                        </div>

                                        <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                            <label class="text-sm font-semibold text-slate-900">Additional Notes (Optional)</label>
                                            <p class="mt-1 text-sm text-slate-500">Provide any additional details about your symptoms.</p>
                                            <textarea name="additional_notes" x-model="otherSymptom" rows="4" class="mt-4 w-full rounded-3xl border border-gray-200 bg-white px-4 py-3 text-sm text-slate-900 focus:border-brand-green focus:outline-none" placeholder="Type your message..."></textarea>
                                        </div>
                                    </div>

                                    <div class="space-y-4">
                                        <div x-show="selectedSymptoms.length" x-cloak class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                            <p class="text-sm font-semibold text-slate-900">Symptom details</p>
                                            <p class="mt-1 text-sm text-slate-500">Each selected symptom can have a different date, time, and severity.</p>
                                            <div class="mt-4 space-y-4">
                                                <template x-for="(symptom, index) in selectedSymptoms" :key="symptom.name">
                                                    <div class="rounded-3xl border border-slate-200 bg-white p-4">
                                                        <div class="flex items-center justify-between gap-3">
                                                            <div>
                                                                <p class="font-semibold text-slate-900" x-text="symptom.name"></p>
                                                                <p class="text-xs text-slate-500">Track the onset and severity for this symptom.</p>
                                                            </div>
                                                            <button type="button" @click="toggleSymptom(symptom.name)" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                                    <path fill-rule="evenodd" d="M10 8.586l4.95-4.95a1 1 0 111.414 1.414L11.414 10l4.95 4.95a1 1 0 01-1.414 1.414L10 11.414l-4.95 4.95a1 1 0 01-1.414-1.414L8.586 10 3.636 5.05a1 1 0 011.414-1.414L10 8.586z" clip-rule="evenodd" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                                            <div>
                                                                <label class="text-xs font-semibold uppercase text-slate-500">Date</label>
                                                                <input type="date" x-model="selectedSymptoms[index].date" max="{{ now()->toDateString() }}" class="mt-2 w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-slate-900 focus:border-brand-green focus:outline-none" />
                                                            </div>
                                                            <div>
                                                                <label class="text-xs font-semibold uppercase text-slate-500">Time</label>
                                                                <input type="time" x-model="selectedSymptoms[index].time" class="mt-2 w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-slate-900 focus:border-brand-green focus:outline-none" />
                                                            </div>
                                                        </div>
                                                        <div class="mt-4">
                                                            <p class="text-xs font-semibold uppercase text-slate-500">Severity</p>
                                                            <div class="mt-3 grid gap-3 grid-cols-4">
                                                                <template x-for="level in [1,2,3,4]" :key="level">
                                                                    <button type="button" @click="selectedSymptoms[index].severity = level" :class="symptom.severity === level ? 'border-green-200 bg-green-50 text-slate-900' : 'border-gray-200 bg-white text-slate-600'" class="rounded-3xl border px-3 py-4 text-center text-sm font-semibold transition hover:border-slate-300 hover:bg-slate-50">
                                                                        <div x-text="level"></div>
                                                                        <div class="mt-1 text-[10px] text-slate-500" x-text="level === 1 ? 'Very Mild' : level === 2 ? 'Mild' : level === 3 ? 'Moderate' : 'Severe'"></div>
                                                                    </button>
                                                                </template>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 3 PANELS PLACEHOLDER (ADD YOUR ADDITIONAL CODE FIELDS HERE IN FUTURE) -->
                        <div x-show="currentStep === 3" x-cloak class="space-y-4">
                            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                                
                                <h3 class="text-lg font-semibold text-gray-900">Additional Details</h3>
                                <p class="mt-1 text-sm text-gray-500">Provide optional context images or medical documentation regarding your ongoing condition symptoms.</p>
                                
                                <div class="mt-6 space-y-6">
                                    <div>
                                        <label for="online_reason" class="block text-sm font-medium text-gray-700 mb-2">Reason for seeking online consultation</label>
                                        <textarea id="online_reason" name="online_reason" x-ref="online_reason" rows="4" required class="mt-1 block w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-green-100" placeholder="Describe why you are seeking online consultation today."></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Attachments / Images (Optional)</label>
                                        <div class="flex justify-center rounded-3xl border border-dashed border-gray-300 px-6 pt-5 pb-6 bg-slate-50 hover:bg-slate-100 transition relative">
                                            <div class="space-y-1 text-center">
                                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg>
                                                <div class="flex text-sm text-gray-600 justify-center">
                                                    <label for="attachments" class="relative cursor-pointer rounded-md font-semibold text-emerald-600 hover:text-emerald-500 focus-within:outline-none">
                                                        <span>Upload files</span>
                                                        <input id="attachments" name="attachments[]" type="file" class="sr-only" multiple accept="image/*" x-ref="attachmentsInput" @change="handleFiles($event)">
                                                    </label>
                                                    <p class="pl-1">or drag and drop</p>
                                                </div>
                                                <p class="text-xs text-gray-500">PNG, JPG, GIF up to 10MB each</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <template x-if="filePreviews.length > 0">
                                    <div class="mt-6">
                                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">Selected Attachments Preview</p>
                                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                            <template x-for="(image, index) in filePreviews" :key="index">
                                                <div class="relative group h-24 rounded-2xl border border-gray-200 overflow-hidden bg-gray-100 shadow-sm">
                                                    <img :src="image" class="h-full w-full object-cover">
                                                    <button type="button" @click="removeFile(index)" class="absolute right-1 top-1 inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-red-600 shadow hover:bg-red-50">
                                                        &times;
                                                    </button>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                            </div>
                        </div>

                        <!-- STEP 4 PANEL SUMMARY REVIEW -->
                        <div x-show="currentStep === 4" x-cloak class="space-y-4">
                            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                                <div class="pb-4 border-b border-gray-200">
                                    <h3 class="text-lg font-semibold text-gray-900">Review Request Summary</h3>
                                    <p class="mt-1 text-sm text-gray-500">Confirm all recorded fields before final transmission.</p>
                                </div>

                                <div class="mt-6 space-y-4">
                                    <div class="p-4 bg-slate-50 rounded-2xl border border-gray-200">
                                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Category</span>
                                        <p class="mt-1 text-sm font-bold text-slate-800 capitalize" x-text="selectedType + ' Consultation'"></p>
                                    </div>

                                    <div class="p-4 bg-slate-50 rounded-2xl border border-gray-200">
                                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Selected Symptoms</span>
                                        <p class="mt-1 text-sm text-slate-800 font-medium" x-text="selectedSymptoms.length ? selectedSymptomsDisplay() : 'None selected'"></p>
                                    </div>
                                    
                                    <div class="p-4 bg-slate-50 rounded-2xl border border-gray-200" x-show="otherSymptom">
                                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Additional Notes Summary</span>
                                        <p class="mt-1 text-sm text-slate-700 italic" x-text="otherSymptom"></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- NEW: STEP 5 PANEL SUCCESS SCREEN -->
                        <div x-show="currentStep === 5" x-cloak class="py-8 text-center space-y-4">
                            <div class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-green-100 text-green-600 mb-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-900">Consultation Submitted!</h3>
                            <p class="max-w-md mx-auto text-sm text-gray-500">
                                Your request has been securely dispatched. The infirmary medical staff will review your symptoms context to execute deterministic rules routing shortly.
                            </p>
                            <div class="pt-6">
                                <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-full bg-brand-green px-6 py-3 text-sm font-semibold text-white hover:bg-brand-green-deep shadow-sm transition">
                                    Return to Dashboard
                                </a>
                            </div>
                        </div>

                        <!-- FOOTER ACTION BUTTONS PANEL -->
                        <div class="flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
    
                            <button type="button"
                                    @click="goToStep(Math.max(currentStep - 1, 1))"
                                    x-show="currentStep > 1 && currentStep < 5"
                                    class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                Back
                            </button>
                            
                            <a href="{{ route('dashboard') }}" x-show="currentStep === 1" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
                            
                            <button type="button" 
                                    @click="goToStep(currentStep + 1)" 
                                    x-show="currentStep < 4"
                                    class="inline-flex items-center justify-center rounded-full bg-brand-green px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-green-deep">
                                Next
                            </button>

                            <button type="submit"
                                    x-show="currentStep === 4"
                                    :disabled="isSubmitting || !intakeAvailable"
                                    class="inline-flex items-center justify-center rounded-full bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 transition"
                                    x-text="!intakeAvailable ? 'Consultations Unavailable' : (isSubmitting ? 'Uploading Attachments...' : 'Submit Request')">
                            </button>

                            <div x-show="isSubmitting" x-cloak class="mt-4 flex items-center justify-center gap-2 text-sm text-gray-500 animate-pulse">
                                <svg class="animate-spin h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Please wait, sending medical files securely to cloud storage...</span>
                            </div>
                        </div>

                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>