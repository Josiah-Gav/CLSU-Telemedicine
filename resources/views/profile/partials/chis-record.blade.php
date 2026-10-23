{{-- Patient-only (included from profile/edit.blade.php). The patient's own CHIS
     record, read-only (RA 10173 right of access), without needing a consultation.
     Fetched only when the patient asks, never during page render, so opening the
     profile page neither calls CHIS nor writes an audit row. --}}
<section
    class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
    aria-labelledby="chis-record-heading"
    x-data="{
        url: @js(route('profile.chis_record.show')),
        chis: { state: 'idle', profile: null, clsuId: null },
        chisLoading: false,
        async load() {
            if (this.chisLoading) { return; }
            this.chisLoading = true;
            try {
                const response = await fetch(this.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                const data = response.ok ? await response.json() : null;
                this.chis = data && data.state
                    ? { state: data.state, profile: data.profile || null, clsuId: data.clsu_id || null }
                    : { state: 'error', profile: null, clsuId: null };
            } catch (e) {
                this.chis = { state: 'error', profile: null, clsuId: null };
            } finally {
                this.chisLoading = false;
            }
        },
        chisDate(value) {
            if (!value) { return 'Not recorded'; }
            const d = new Date(value);
            return isNaN(d) ? String(value) : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        },
    }"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h4 id="chis-record-heading" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Your CHIS medical record') }}</h4>
        <x-button-secondary size="sm" @click="load()" x-bind:disabled="chisLoading">
            <span x-text="chis.state === 'idle' ? 'Show my record' : 'Refresh'">{{ __('Show my record') }}</span>
        </x-button-secondary>
    </div>
    <p class="mt-2 text-sm text-slate-600">
        {{ __('See what CHIS holds about you, read-only. Your assigned physician can see the same record during a consultation. If something is wrong, contact the clinic or CLSU\'s Data Protection Officer.') }}
    </p>

    <div x-show="chis.state !== 'idle'" x-cloak>
        @include('chis.partials.record', ['isOwnPatient' => true])
    </div>

    <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">{{ __('Viewing this record is logged.') }}</p>
</section>
