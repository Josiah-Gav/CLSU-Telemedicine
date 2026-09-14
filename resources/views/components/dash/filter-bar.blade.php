@props(['dateRange', 'action', 'scopeNote' => null])

{{--
    The filter boundary — page-reload based (query string), not AJAX, so
    the selected range is bookmarkable and correct on back/forward. This is
    the visible line between "current state" (rendered above, unaffected by
    any of this) and "the selected period" (below).
--}}
@php
    $presets = [
        'today' => 'Today',
        'this_week' => 'This Week',
        'this_month' => 'This Month',
        'last_30_days' => 'Last 30 Days',
        'this_year' => 'This Year',
        'custom' => 'Custom Range',
    ];
@endphp

<div class="rounded-xl border border-brand-border bg-white px-4 py-3" x-data="{ preset: '{{ $dateRange->preset }}' }">
    <form method="GET" action="{{ $action }}" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <label for="range" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Showing</label>

        <select
            id="range"
            name="range"
            x-model="preset"
            @change="if (preset !== 'custom') { $el.form.submit(); }"
            class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-green focus:ring-brand-green sm:w-auto"
        >
            @foreach ($presets as $value => $label)
                <option value="{{ $value }}" @selected($dateRange->preset === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <template x-if="preset === 'custom'">
            <div class="flex flex-wrap items-center gap-2">
                <label for="start" class="sr-only">Start date</label>
                <input
                    type="date" id="start" name="start"
                    value="{{ $dateRange->preset === 'custom' ? $dateRange->start->toDateString() : '' }}"
                    class="min-h-11 rounded-lg border-gray-300 text-sm focus:border-brand-green focus:ring-brand-green"
                />
                <span class="text-sm text-slate-400" aria-hidden="true">to</span>
                <label for="end" class="sr-only">End date</label>
                <input
                    type="date" id="end" name="end"
                    value="{{ $dateRange->preset === 'custom' ? $dateRange->end->toDateString() : '' }}"
                    class="min-h-11 rounded-lg border-gray-300 text-sm focus:border-brand-green focus:ring-brand-green"
                />
                <x-button-primary type="submit">
                    Apply
                </x-button-primary>
            </div>
        </template>

        <noscript>
            <x-button-primary type="submit">Apply</x-button-primary>
        </noscript>
    </form>

    @if ($scopeNote)
        <p class="mt-2 text-xs text-slate-500">{{ $scopeNote }}</p>
    @endif
</div>
