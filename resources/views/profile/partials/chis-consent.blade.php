{{-- Patient-only (included from profile/edit.blade.php). Consent under the
     Data Privacy Act (RA 10173): health data is sensitive personal information. --}}
<section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="chis-consent-heading">
    <header>
        <h4 id="chis-consent-heading" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Share records with CHIS') }}</h4>
        <p class="mt-1 text-sm text-slate-500">{{ __('CHIS is the CLSU Comprehensive Health Information System. Sharing is optional; Telemed works the same either way.') }}</p>
    </header>

    @if ($user->hasChisConsent())
        <div class="mt-5 flex items-start gap-4 rounded-2xl bg-brand-green-deep p-5">
            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-white/20">
                <svg class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <div>
                <p class="text-base font-bold text-white">{{ __('Sharing is on') }}</p>
                <p class="mt-1 text-xs text-white/80">{{ __('You gave consent on :date.', ['date' => $user->chis_consent_at->timezone(config('app.timezone'))->format('F j, Y g:i A')]) }}</p>
            </div>
        </div>
    @else
        <div class="mt-5 flex items-start gap-4 rounded-2xl bg-slate-700 p-5">
            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-white/20">
                <svg class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="9" y1="12" x2="15" y2="12"></line></svg>
            </div>
            <div>
                <p class="text-base font-bold text-white">{{ __('Sharing is off') }}</p>
                <p class="mt-1 text-xs text-white/80">{{ __('Nothing is shared with CHIS until you give consent.') }}</p>
            </div>
        </div>
    @endif

    <div class="mt-5 grid gap-4 text-sm text-slate-700 sm:grid-cols-2">
        <div>
            <p class="font-semibold text-slate-800">{{ __('What is shared') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>{{ __('Your CLSU ID and name.') }}</li>
                <li>{{ __('Consultation summaries: diagnosis, assessment, plan, recommendations, your symptoms, and the prescription file name only.') }}</li>
                <li>{{ __('Physicians will see your CHIS medical profile during consultations: allergies, conditions, medications, immunizations, and family history.') }}</li>
            </ul>
        </div>
        <div>
            <p class="font-semibold text-slate-800">{{ __('What is never shared') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>{{ __('Your chat messages.') }}</li>
                <li>{{ __('Images or files you upload.') }}</li>
                <li>{{ __('The prescription file itself.') }}</li>
                <li>{{ __('Your contact details.') }}</li>
            </ul>
        </div>
    </div>

    <p class="mt-4 text-sm text-slate-600">{{ __('Applies to consultations completed after you give consent; earlier ones are never sent. You can withdraw consent at any time. Withdrawing stops future sharing, but summaries already sent remain in CHIS.') }}</p>

    <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-end">
        <div role="status" aria-live="polite">
            @if (session('status') === 'chis-consent-given')
                <p class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-sm font-medium text-emerald-700">{{ __('Consent recorded.') }}</p>
            @elseif (session('status') === 'chis-consent-withdrawn')
                <p class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 text-sm font-medium text-slate-700">{{ __('Consent withdrawn. Nothing new will be shared.') }}</p>
            @endif
        </div>

        @if ($user->hasChisConsent())
            <form method="post" action="{{ route('profile.chis-consent.destroy') }}">
                @csrf
                @method('delete')
                <x-button-secondary type="submit">{{ __('Withdraw consent') }}</x-button-secondary>
            </form>
        @else
            <form method="post" action="{{ route('profile.chis-consent.store') }}">
                @csrf
                <x-button-primary type="submit">{{ __('I consent to sharing with CHIS') }}</x-button-primary>
            </form>
        @endif
    </div>
</section>
