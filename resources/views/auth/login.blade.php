<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-emerald-800">Welcome back</h2>
        <p class="text-sm sm:text-base text-gray-600">Sign in to your CLSU Campus Telemedicine account</p>
    </div>

    <div class="mb-4">
        <a href="{{ url('/') }}" class="inline-flex items-center text-sm font-medium text-emerald-700 hover:text-emerald-900">
            ← Back to welcome
        </a> 
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-2">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-between mt-4">
            <div>
                @if (Route::has('password.request'))
                    <a class="text-sm text-emerald-700 hover:text-emerald-900" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
                @endif
            </div>

            <div class="w-1/2">
                <x-button-primary type="submit" class="w-full bg-gradient-to-r from-emerald-600 to-emerald-700 border-0">
                    {{ __('Log in') }}
                </x-button-primary>
            </div>
        </div>
    </form>
</x-guest-layout>
