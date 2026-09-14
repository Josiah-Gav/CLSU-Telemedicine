@props(['href' => null, 'type' => 'button', 'size' => 'md'])

{{--
    Soft Modern Clinical SaaS — Secondary action button/link.

    For a useful but non-primary action alongside a primary one (Cancel,
    Reset, Details, View record) — bordered, neutral, never competing with
    the primary action's color. Same on-brand pattern already used for
    "Cancel"/"Reset" across the app, componentized.

    `size`: "md" (default, ≥44px touch target) or "sm" (compact, for a
    dense desktop table row) — see button-primary.blade.php's docblock for
    the full rationale.

    See button-primary.blade.php's docblock for the Alpine `:attr=` vs
    Blade `:attr=` collision — the same rule applies here: use
    `x-bind:disabled=`/`x-bind:class=` for Alpine-reactive bindings, never
    the bare-colon shorthand, on this component.

    Usage:
        <x-button-secondary href="{{ route('consultations.history') }}">Reset</x-button-secondary>
        <x-button-secondary size="sm" @click="openDetails(item)">Details</x-button-secondary>
--}}
@php
    $sizeClasses = $size === 'sm'
        ? 'px-3 py-1.5 text-xs'
        : 'min-h-11 px-4 py-2.5 text-sm';
    $classes = "inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-border bg-white font-semibold text-slate-700 transition hover:bg-brand-green-soft hover:text-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-white {$sizeClasses}";
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
