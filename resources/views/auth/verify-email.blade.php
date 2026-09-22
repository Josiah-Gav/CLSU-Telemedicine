<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="mb-4 text-sm text-gray-500">
        {{ __("Didn't receive the email? Please check your spam or junk folder.") }}
    </div>

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-button-primary type="submit">
                    {{ __('Resend Verification Email') }}
                </x-button-primary>
            </div>
        </form>

        {{-- Kept as a plain text control, not migrated to x-button-ghost:
             every other "Log Out" in the app (layouts/app.blade.php,
             layouts/navigation.blade.php) is styled as quiet inline text,
             never brand-green — matching that existing convention matters
             more here than uniformity with the button component set. --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
