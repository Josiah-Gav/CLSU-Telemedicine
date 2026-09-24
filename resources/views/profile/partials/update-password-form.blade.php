<section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
    <header>
        <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Change Password</h4>
        <p class="mt-1 text-sm text-slate-500">{{ __('Use a long, random password to keep your account secure.') }}</p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('put')

        <div>
            <label for="update_password_current_password" class="block text-sm font-semibold text-slate-800">{{ __('Current Password') }}</label>
            <x-password-reveal>
                <input id="update_password_current_password" name="current_password" :type="show ? 'text' : 'password'" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" autocomplete="current-password" />
            </x-password-reveal>
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="update_password_password" class="block text-sm font-semibold text-slate-800">{{ __('New Password') }}</label>
                <x-password-reveal>
                    <input id="update_password_password" name="password" :type="show ? 'text' : 'password'" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" autocomplete="new-password" />
                </x-password-reveal>
                <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
            </div>

            <div>
                <label for="update_password_password_confirmation" class="block text-sm font-semibold text-slate-800">{{ __('Confirm Password') }}</label>
                <x-password-reveal>
                    <input id="update_password_password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" autocomplete="new-password" />
                </x-password-reveal>
                <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
            </div>
        </div>

        <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-end">
            <div role="status" aria-live="polite">
                @if (session('status') === 'password-updated')
                    <p
                        x-data="{ show: true }"
                        x-show="show"
                        x-transition
                        x-init="setTimeout(() => show = false, 2000)"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-sm font-medium text-emerald-700"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ __('Saved.') }}
                    </p>
                @endif
            </div>
            <x-button-primary type="submit">
                {{ __('Update Password') }}
            </x-button-primary>
        </div>
    </form>
</section>
