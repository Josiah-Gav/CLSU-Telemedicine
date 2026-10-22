<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="grid gap-4 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    @include('profile.partials.account-overview')
                </div>

                <div class="space-y-4 lg:col-span-8">
                    @include('profile.partials.update-profile-information-form')
                    @include('profile.partials.update-password-form')
                    @if ($user->role === 'patient')
                        @include('profile.partials.chis-consent')
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
