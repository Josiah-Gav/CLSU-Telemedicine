@props(['href' => null, 'type' => 'button', 'size' => 'md'])

{{--
    Soft Modern Clinical SaaS — Primary action button/link.

    For the one main recommended action on a page or workflow (Submit,
    Approve, Forward, Save, Request a Consultation). This is the app's
    already-existing on-brand pattern (brand-green, rounded-lg — see
    design-system/clsu-telemedicine/pages/soft-modern-clinical-saas.md
    §Buttons), extracted into a component so it stops being retyped by hand
    on every page. Not a new visual design.

    `size`: "md" (default) — the ≥44px touch target Task 3/7 require, for
    standalone CTAs and mobile cards. "sm" — compact, no forced min-height,
    for a dense desktop table row where several actions share one line;
    the same visual language, at a scale that doesn't inflate row height.
    Never use "sm" as the only way an action is reachable on a touch
    device — pair it with a "md" (or a card) at narrow widths, the way the
    existing dashboards already do.

    Renders an <a> when `href` is given, a <button> otherwise. Every other
    attribute — including Alpine directives — passes through untouched via
    $attributes, since neither is declared as a @props key.

    IMPORTANT — Alpine + Blade colon collision: Blade's own `:attr="..."`
    shorthand (for passing a PHP value into a component) and Alpine's
    identical-looking `:attr="..."` shorthand (for reactive JS binding) both
    use a bare leading colon, and Blade wins on a <x-component> tag — it
    evaluates the right-hand side as PHP, not JS. Passing `:disabled="isSubmitting"`
    here would try to evaluate a PHP variable named $isSubmitting and fail
    (or silently do the wrong thing). For Alpine-reactive attributes on this
    component, use Alpine's unabbreviated form instead — `x-bind:disabled=`,
    `x-bind:class=` — which Blade does not intercept. Plain `@click`, `x-show`,
    `x-text`, `x-cloak` etc. are unaffected either way since they don't start
    with a colon.

    Usage:
        <x-button-primary href="{{ route('newconsultation') }}">Request a Consultation</x-button-primary>
        <x-button-primary type="submit">Save</x-button-primary>
        <x-button-primary size="sm" @click="forwardRequest(item)">Forward</x-button-primary>
        <x-button-primary x-bind:disabled="isSubmitting" x-text="isSubmitting ? 'Saving…' : 'Save'"></x-button-primary>
--}}
@php
    $sizeClasses = $size === 'sm'
        ? 'px-3 py-1.5 text-xs'
        : 'min-h-11 px-4 py-2.5 text-sm';
    $classes = "inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-green font-semibold text-white transition hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-brand-green {$sizeClasses}";
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
