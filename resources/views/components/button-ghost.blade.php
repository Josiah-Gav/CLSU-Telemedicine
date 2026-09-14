@props(['href' => null, 'type' => 'button', 'size' => 'md'])

{{--
    Soft Modern Clinical SaaS — Tertiary/ghost action button/link.

    For low-emphasis utility actions that shouldn't visually compete with
    anything else on the row/card (Edit, Open in new tab) — text-only,
    brand-colored, underline on hover. Same pattern already used for "Edit"
    links throughout the app, componentized.

    Deliberately no visible border/fill/focus-ring-offset background, so it
    stays low-emphasis at rest — but keeps a real focus-visible ring for
    keyboard users, since "low emphasis" must never mean "invisible focus".

    `size`: "md" (default, ≥44px touch target) or "sm" (compact, for a
    dense desktop table row) — see button-primary.blade.php's docblock for
    the full rationale.

    See button-primary.blade.php's docblock for the Alpine `:attr=` vs
    Blade `:attr=` collision — the same rule applies here.

    Usage:
        <x-button-ghost href="{{ route('admin.users.edit', $user) }}">Edit</x-button-ghost>
--}}
@php
    $sizeClasses = $size === 'sm'
        ? 'px-1.5 py-1 text-xs'
        : 'min-h-11 px-2 py-2.5 text-sm';
    $classes = "inline-flex items-center justify-center gap-1 rounded-lg font-semibold text-brand-green transition hover:text-brand-green-deep hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:no-underline {$sizeClasses}";
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
