{{-- The CHIS record body: one panel per lookup state, then the minimised profile.
     Needs an Alpine scope with chis, chisLoading and chisDate() (messaging page,
     profile page). Everything CHIS sends is rendered with x-text only.
     $isOwnPatient switches the state wording to the patient's own. --}}
<div role="status" aria-live="polite" class="mt-3">
    <p x-show="chisLoading" class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-600">Loading the CHIS record&hellip;</p>
    @if($isOwnPatient)
        <p x-show="!chisLoading && chis.state === 'no_clsu_id'" x-cloak class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700">You have no CLSU ID on file, so no CHIS record can be looked up. You can add it on your <a href="{{ route('profile.edit') }}" class="font-semibold text-brand-green hover:underline">profile</a>.</p>
        <p x-show="!chisLoading && chis.state === 'not_found'" x-cloak class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700">CHIS has no record for your CLSU ID <span class="font-mono" x-text="chis.clsuId"></span>.</p>
        <p x-show="!chisLoading && chis.state === 'identity_mismatch'" x-cloak class="rounded-xl bg-amber-50 px-3 py-2.5 text-sm font-semibold text-amber-900">The CHIS record for your CLSU ID does not match your name, so it is not shown. Please contact the clinic to verify your CLSU ID.</p>
    @else
        <p x-show="!chisLoading && chis.state === 'no_clsu_id'" x-cloak class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700">The patient has no CLSU ID on file, so no CHIS record can be looked up.</p>
        <p x-show="!chisLoading && chis.state === 'not_found'" x-cloak class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700">No CHIS record for CLSU ID <span class="font-mono" x-text="chis.clsuId"></span>.</p>
        <p x-show="!chisLoading && chis.state === 'identity_mismatch'" x-cloak class="rounded-xl bg-amber-50 px-3 py-2.5 text-sm font-semibold text-amber-900">The CHIS record for this CLSU ID does not match this patient's name &mdash; not displayed. Ask an administrator to verify the CLSU ID.</p>
    @endif
    <p x-show="!chisLoading && (chis.state === 'unavailable' || chis.state === 'error')" x-cloak class="rounded-xl bg-amber-50 px-3 py-2.5 text-sm font-semibold text-amber-900">CHIS could not be reached &mdash; try Refresh.</p>
</div>

<div x-show="!chisLoading && chis.state === 'ok'" x-cloak>
    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
        <div><dt class="text-xs font-medium text-slate-500">Blood type</dt><dd class="mt-1 text-sm font-semibold text-slate-900" x-text="chis.profile.blood_type || 'Not recorded'"></dd></div>
        <div><dt class="text-xs font-medium text-slate-500">Sex</dt><dd class="mt-1 text-sm font-semibold text-slate-900" x-text="chis.profile.sex || 'Not recorded'"></dd></div>
        <div><dt class="text-xs font-medium text-slate-500">Birth date</dt><dd class="mt-1 text-sm font-semibold text-slate-900" x-text="chisDate(chis.profile.birth_date)"></dd></div>
    </dl>
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Allergies</h4>
    <p x-show="!(chis.profile.medical_profile.allergies || []).length" class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-500">None recorded in CHIS</p>
    <ul class="mt-3 space-y-2" x-show="(chis.profile.medical_profile.allergies || []).length">
        <template x-for="(item, i) in (chis.profile.medical_profile.allergies || [])" :key="i">
            <li class="rounded-xl px-3 py-2.5 text-sm" :class="String(item.severity).toLowerCase() === 'severe' ? 'border-l-4 border-amber-600 bg-amber-50 font-semibold text-amber-900' : 'bg-slate-50 text-slate-700'">
                <span x-text="item.substance"></span>
                <span x-show="item.reaction" class="font-normal" x-text="' — ' + item.reaction"></span>
                <span x-show="item.severity" class="ml-1 inline-flex rounded-full bg-white px-2 py-0.5 text-xs font-semibold" x-text="String(item.severity).toLowerCase() === 'severe' ? 'Severe' : item.severity"></span>
            </li>
        </template>
    </ul>
</div>
<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Chronic conditions</h4>
    <p x-show="!(chis.profile.medical_profile.chronic_conditions || []).length" class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-500">None recorded in CHIS</p>
    <ul class="mt-3 space-y-2" x-show="(chis.profile.medical_profile.chronic_conditions || []).length">
        <template x-for="(item, i) in (chis.profile.medical_profile.chronic_conditions || [])" :key="i">
            <li class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700"><span class="font-semibold" x-text="item.name"></span><span x-show="item.diagnosed_on" x-text="' · since ' + chisDate(item.diagnosed_on)"></span><span x-show="item.status" x-text="' · ' + item.status"></span></li>
        </template>
    </ul>
</div>
<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Current medications</h4>
    <p x-show="!(chis.profile.medical_profile.current_medications || []).length" class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-500">None recorded in CHIS</p>
    <ul class="mt-3 space-y-2" x-show="(chis.profile.medical_profile.current_medications || []).length">
        <template x-for="(item, i) in (chis.profile.medical_profile.current_medications || [])" :key="i">
            <li class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700"><span class="font-semibold" x-text="item.name"></span><span x-show="item.dose" x-text="' · ' + item.dose"></span><span x-show="item.frequency" x-text="' · ' + item.frequency"></span></li>
        </template>
    </ul>
</div>
<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Immunizations</h4>
    <p x-show="!(chis.profile.medical_profile.immunizations || []).length" class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-500">None recorded in CHIS</p>
    <ul class="mt-3 space-y-2" x-show="(chis.profile.medical_profile.immunizations || []).length">
        <template x-for="(item, i) in (chis.profile.medical_profile.immunizations || [])" :key="i">
            <li class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700"><span class="font-semibold" x-text="item.vaccine"></span><span x-show="item.dose" x-text="' · dose ' + item.dose"></span><span x-show="item.date_administered" x-text="' · ' + chisDate(item.date_administered)"></span></li>
        </template>
    </ul>
</div>
<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Family history</h4>
    <p x-show="!(chis.profile.medical_profile.family_history || []).length" class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-500">None recorded in CHIS</p>
    <ul class="mt-3 space-y-2" x-show="(chis.profile.medical_profile.family_history || []).length">
        <template x-for="(item, i) in (chis.profile.medical_profile.family_history || [])" :key="i">
            <li class="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-700"><span class="font-semibold" x-text="item.relation"></span><span x-text="': ' + item.condition"></span></li>
        </template>
    </ul>
</div>
    </div>
    <p class="mt-4 text-xs text-slate-500">Last updated in CHIS: <span x-text="chisDate(chis.profile.record_updated_at)"></span></p>
</div>
