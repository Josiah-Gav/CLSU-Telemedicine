<x-guest-layout>
    <div x-data="{
        open: false,
        agreed: false,
        attemptSubmit() {
            if (this.agreed) {
                this.$refs.registerForm.submit();
                return;
            }
            this.open = true;
        },
        cancel() {
            this.open = false;
            this.agreed = false;
        },
        continueRegistration() {
            if (!this.agreed) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Agreement required',
                    text: 'Please check the box confirming you have read and agree to the Privacy Policy before continuing.',
                    confirmButtonColor: '#047857',
                });
                return;
            }
            this.open = false;
            this.$refs.registerForm.submit();
        },
    }">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl max-h-[90vh] overflow-y-auto">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                            <line x1="12" y1="9" x2="12" y2="13" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-lg font-bold text-emerald-800">Before You Register</h3>
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Capstone Testing Notice</span>
                    </div>
                </div>

                <div class="mt-3 space-y-3 text-sm text-gray-700">
                    <p>This telemedicine system is currently being used for <strong>academic capstone testing purposes</strong>. During this 5-day live testing period, the system will be evaluated through actual use by the CLSU Infirmary to assess its functionality, usability, and acceptance.</p>

                    <p>By proceeding with registration and using the system, you acknowledge that:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Your personal information and consultation-related information may be collected and processed for the purposes of providing the system's telemedicine functions and evaluating the system during the testing period.</li>
                        <li>Your information will be handled in accordance with the system's <strong>Privacy Policy</strong> and applicable data protection requirements.</li>
                        <li>This system is <strong>not yet an officially integrated CLSU telemedicine system</strong> and is being further developed toward potential future integration with CLSU's existing systems.</li>
                        <li>Information collected during this testing period may be used by the researchers for <strong>system evaluation and capstone research purposes</strong>, subject to the stated privacy and data-handling provisions.</li>
                    </ul>

                    <p>Please review the <strong>Privacy Policy</strong> before continuing.</p>

                    <a href="{{ route('privacy.policy') }}" target="_blank" class="inline-block font-medium text-emerald-700 underline hover:text-emerald-900">View Privacy Policy</a>

                    <label class="flex items-start gap-2 pt-2">
                        <input type="checkbox" x-model="agreed" class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span>I have read and understood the Privacy Policy and agree to the collection and processing of my information for the purposes stated above.</span>
                    </label>
                </div>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" @click="cancel()" class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-100">Cancel</button>
                    <x-button-primary type="button" @click="continueRegistration()">I Agree &amp; Continue</x-button-primary>
                </div>
            </div>
        </div>

        <div class="mb-6">
            <h2 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-emerald-800">Create an account</h2>
            <p class="text-sm sm:text-base text-gray-600">Register as Student / Faculty to access CLSU Telemedicine</p>
        </div>

        <div class="mb-4">
            <a href="{{ url('/') }}" class="inline-flex items-center text-sm font-medium text-emerald-700 hover:text-emerald-900">
                ← Back to welcome
            </a>
        </div>

        <form method="POST" action="{{ route('register') }}" class="space-y-4" x-ref="registerForm" @submit.prevent="attemptSubmit()">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="first_name" :value="__('First Name')" />
            <x-text-input id="first_name" class="block mt-1 w-full" type="text" name="first_name" :value="old('first_name')" required autofocus autocomplete="given-name" />
            <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="last_name" :value="__('Last Name')" />
            <x-text-input id="last_name" class="block mt-1 w-full" type="text" name="last_name" :value="old('last_name')" required autocomplete="family-name" />
            <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
        </div>

        <!-- CLSU ID -->
        <div class="mt-4">
            <x-input-label for="clsu_id" :value="__('CLSU ID')" />
            <x-text-input id="clsu_id" class="block mt-1 w-full" type="text" name="clsu_id" :value="old('clsu_id')" required autocomplete="off" />
            <x-input-error :messages="$errors->get('clsu_id')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <a class="text-sm text-emerald-700 hover:text-emerald-900" href="{{ route('login') }}">{{ __('Already registered?') }}</a>

            <x-button-primary type="submit" class="ms-4 w-32 bg-gradient-to-r from-emerald-600 to-emerald-700 border-0">
                {{ __('Register') }}
            </x-button-primary>
        </div>
    </form>
    </div>
</x-guest-layout>
