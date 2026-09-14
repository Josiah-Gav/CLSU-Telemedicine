<section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
    <header>
        <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Personal Information</h4>
        <p class="mt-1 text-sm text-slate-500">{{ __("Update your name and contact details.") }}</p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('patch')

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="first_name" class="block text-sm font-semibold text-slate-800">{{ __('First Name') }}</label>
                <input id="first_name" name="first_name" type="text" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" value="{{ old('first_name', $user->first_name) }}" required autofocus autocomplete="given-name" />
                <x-input-error class="mt-2" :messages="$errors->get('first_name')" />
            </div>

            <div>
                <label for="last_name" class="block text-sm font-semibold text-slate-800">{{ __('Last Name') }}</label>
                <input id="last_name" name="last_name" type="text" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" value="{{ old('last_name', $user->last_name) }}" required autocomplete="family-name" />
                <x-input-error class="mt-2" :messages="$errors->get('last_name')" />
            </div>

            <div>
                <label for="clsu_id" class="block text-sm font-semibold text-slate-800">{{ __('CLSU ID') }}</label>
                <input id="clsu_id" name="clsu_id" type="text" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" value="{{ old('clsu_id', $user->clsu_id) }}" autocomplete="off" />
                <x-input-error class="mt-2" :messages="$errors->get('clsu_id')" />
            </div>

            <div>
                <label for="department" class="block text-sm font-semibold text-slate-800">{{ __('Department') }}</label>
                <input id="department" name="department" type="text" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" value="{{ old('department', $user->department) }}" autocomplete="organization" />
                <x-input-error class="mt-2" :messages="$errors->get('department')" />
            </div>

            <div class="sm:col-span-2">
                <label for="contact_num" class="block text-sm font-semibold text-slate-800">{{ __('Contact Number') }}</label>
                <input id="contact_num" name="contact_num" type="text" class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100" value="{{ old('contact_num', $user->contact_num) }}" autocomplete="tel" />
                <x-input-error class="mt-2" :messages="$errors->get('contact_num')" />
            </div>
        </div>

        <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-end">
            <div role="status" aria-live="polite">
                @if (session('status') === 'profile-updated')
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
                {{ __('Save Changes') }}
            </x-button-primary>
        </div>
    </form>
</section>
