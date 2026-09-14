<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Welcome, :name. Set a password to activate your account.', ['name' => $user->first_name.' '.$user->last_name]) }}
    </div>

    <div class="mb-4 rounded-md bg-gray-50 p-4 text-sm text-gray-700">
        <div><span class="font-medium">{{ __('Email') }}:</span> {{ $user->email }}</div>
        <div><span class="font-medium">{{ __('Role') }}:</span> {{ ucfirst($user->role) }}</div>
    </div>

    <form method="POST" action="{{ route('staff.activate.store') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">
        {{-- The broker looks invitations up by email, so it must be submitted. --}}
        <input type="hidden" name="email" value="{{ $user->email }}">

        <x-input-error :messages="$errors->get('email')" class="mb-4" />

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autofocus autocomplete="new-password" />
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

        <div class="flex items-center justify-end mt-4">
            <x-button-primary type="submit">
                {{ __('Activate Account') }}
            </x-button-primary>
        </div>
    </form>
</x-guest-layout>
