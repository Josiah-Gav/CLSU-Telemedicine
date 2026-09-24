@props([
    'label',
    'value',
    'supporting' => null,
    'tone' => 'neutral', // neutral | critical | active
    'href' => null,
    'icon' => null, // pulse | calendar | inbox | alert | repeat | folder
])

@php
    $toneClasses = match ($tone) {
        'critical' => 'bg-amber-700',
        'active' => 'bg-brand-green-deep',
        default => 'border border-brand-border bg-white',
    };
    $isSolid = in_array($tone, ['critical', 'active']);
    $valueClasses = $isSolid ? 'text-white' : 'text-slate-900';
    $labelClasses = $isSolid ? 'text-white/75' : 'text-slate-500';
    $supportingClasses = $isSolid ? 'text-white/70' : 'text-slate-500';
    $chipClasses = $isSolid ? 'bg-white/20' : 'bg-brand-green-soft';
    $iconStroke = $isSolid ? '#ffffff' : '#0f6b3d';
    $tag = $href ? 'a' : 'div';

    $icons = [
        'pulse' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"></path>',
        'alert' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        'repeat' => '<polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path>',
        'folder' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path>',
    ];
    $iconPaths = $icon ? ($icons[$icon] ?? null) : null;
@endphp

{{-- Interactivity is opt-in via $href only — a non-linked stat card must
     never look clickable (no cursor-pointer, no hover lift), per Phase 2's
     "no fake interactivity" rule. --}}
<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge([
        'class' => trim("rounded-2xl p-5 transition $toneClasses " . ($href ? 'cursor-pointer hover:shadow-sm' : '')),
    ]) }}
>
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            @if ($iconPaths)
                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl {{ $chipClasses }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="{{ $iconStroke }}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">{!! $iconPaths !!}</svg>
                </span>
            @endif
            <p class="text-xs font-semibold uppercase tracking-wide {{ $labelClasses }}">{{ $label }}</p>
        </div>
        @if ($tone === 'active')
            <span class="relative flex h-2.5 w-2.5 flex-shrink-0" aria-hidden="true">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-white opacity-75"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-white"></span>
            </span>
        @endif
    </div>
    <p class="mt-3 text-3xl font-bold tabular-nums {{ $valueClasses }}">{{ $value }}</p>
    @if ($supporting)
        <p class="mt-1 text-xs {{ $supportingClasses }}">{{ $supporting }}</p>
    @endif
    {{ $slot }}
</{{ $tag }}>
