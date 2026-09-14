<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-emerald-800">Create an account</h2>
        <p class="text-sm sm:text-base text-gray-600">Register as Student / Faculty to access CLSU Telemedicine</p>
    </div>

    <div class="mb-4">
        <a href="{{ url('/') }}" class="inline-flex items-center text-sm font-medium text-emerald-700 hover:text-emerald-900">
            ← Back to welcome
        </a>
    </div>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
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
</x-guest-layout>
