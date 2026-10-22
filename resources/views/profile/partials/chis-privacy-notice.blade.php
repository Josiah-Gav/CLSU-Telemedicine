{{-- Patient-only (included from profile/edit.blade.php). Privacy notice, not a
     consent form: summaries go to CHIS under RA 10173 Sec. 13 (medical treatment). --}}
<section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="chis-privacy-notice-heading">
    <header>
        <h4 id="chis-privacy-notice-heading" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('How your records are shared with CHIS') }}</h4>
        <p class="mt-1 text-sm text-slate-600">{{ __('Telemed sends a summary of every completed consultation to CHIS, the CLSU Comprehensive Health Information System, for your continuing care. These are CLSU\'s own medical records, shared under the Data Privacy Act (RA 10173) Section 13: medical treatment. This is not optional and needs no consent. Consultations completed earlier may also be sent.') }}</p>
    </header>

    <div class="mt-5 grid gap-4 text-sm text-slate-700 sm:grid-cols-2">
        <div>
            <p class="font-semibold text-slate-800">{{ __('What is sent') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>{{ __('Your CLSU ID and name.') }}</li>
                <li>{{ __('Your physician.') }}</li>
                <li>{{ __('Your symptoms, diagnosis, assessment, plan, and recommendations.') }}</li>
                <li>{{ __('The prescription file name only.') }}</li>
            </ul>
        </div>
        <div>
            <p class="font-semibold text-slate-800">{{ __('What is never sent') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>{{ __('Your chat messages.') }}</li>
                <li>{{ __('Images or files you upload.') }}</li>
                <li>{{ __('The prescription file itself.') }}</li>
                <li>{{ __('Your contact details.') }}</li>
            </ul>
        </div>
    </div>

    <p class="mt-4 text-sm text-slate-600">{{ __('Your assigned physician may view your CHIS medical record during a consultation, and every view is logged.') }}</p>
    <p class="mt-2 text-sm text-slate-600">{{ __('For questions or corrections, contact the clinic or CLSU\'s Data Protection Officer.') }}</p>
</section>
