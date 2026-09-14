@props(['href' => null, 'type' => 'button', 'size' => 'md'])

{{--
    Soft Modern Clinical SaaS — Destructive action button/link.

    For rejection, cancellation, revocation, or deletion — anything that
    undoes or refuses something. Same red-600 already used app-wide for
    Reject/Cancel actions, componentized.

    `size`: "md" (default, ≥44px touch target) or "sm" (compact, for a
    dense desktop table row) — see button-primary.blade.php's docblock for
    the full rationale.

    See button-primary.blade.php's docblock for the Alpine `:attr=` vs
    Blade `:attr=` collision — the same rule applies here.

    Usage:
        <x-button-danger @click="rejectRequest(item)">Reject</x-button-danger>
        <x-button-danger size="sm" type="submit">Revoke Invitation</x-button-danger>
--}}
@php
    $sizeClasses = $size === 'sm'
        ? 'px-3 py-1.5 text-xs'
        : 'min-h-11 px-4 py-2.5 text-sm';
    $classes = "inline-flex items-center justify-center gap-1.5 rounded-lg bg-red-600 font-semibold text-white transition hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-red-600 {$sizeClasses}";
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
