<?php

use Illuminate\Support\Facades\Blade;

/**
 * Phase 4: the four button components (primary/secondary/danger/ghost) are
 * thin wrappers around the app's existing on-brand inline button classes —
 * no new visual design, just one place to maintain it. The behavior that
 * actually needs a test is the href/button dual-rendering and that
 * arbitrary attributes (Alpine directives included) pass through
 * untouched, since a mistake there would silently break every @click
 * handler on every migrated button.
 */
it('renders as a button by default, with the given type', function () {
    $html = Blade::render('<x-button-primary type="submit">Save</x-button-primary>');

    expect($html)->toContain('<button')
        ->and($html)->toContain('type="submit"')
        ->and($html)->toContain('Save</button>')
        ->and($html)->not->toContain('<a ');
});

it('renders as a link when href is given, with no type attribute', function () {
    $html = Blade::render('<x-button-primary href="/newconsultation">Request a Consultation</x-button-primary>');

    expect($html)->toContain('<a href="/newconsultation"')
        ->and($html)->toContain('Request a Consultation</a>')
        ->and($html)->not->toContain('<button')
        ->and($html)->not->toContain('type=');
});

it('passes an Alpine @click handler through untouched', function () {
    $html = Blade::render('<x-button-danger @click="rejectRequest(item)">Reject</x-button-danger>');

    expect($html)->toContain('@click="rejectRequest(item)"');
});

it('passes an Alpine x-bind:disabled binding through untouched, not as a Blade PHP expression', function () {
    // The bare `:disabled="isSubmitting"` shorthand would be intercepted by
    // Blade as a PHP-expression prop binding and fail (undefined variable)
    // — this is exactly the collision documented in the component. The
    // unabbreviated x-bind: form must survive untouched instead.
    $html = Blade::render('<x-button-primary x-bind:disabled="isSubmitting">Save</x-button-primary>');

    expect($html)->toContain('x-bind:disabled="isSubmitting"');
});

it('lets a caller extend the class list without losing the base styling', function () {
    $html = Blade::render('<x-button-secondary class="w-full">Reset</x-button-secondary>');

    expect($html)->toContain('w-full')
        ->and($html)->toContain('rounded-lg');
});

it('drops the forced 44px touch target for a compact size, for a dense desktop table row', function () {
    $html = Blade::render('<x-button-secondary size="sm">Details</x-button-secondary>');

    expect($html)->not->toContain('min-h-11')
        ->and($html)->toContain('py-1.5');
});

it('gives every variant a visible focus-visible ring and a real touch target', function () {
    foreach (['button-primary', 'button-secondary', 'button-danger', 'button-ghost'] as $component) {
        $html = Blade::render("<x-{$component}>Action</x-{$component}>");

        expect($html)->toContain('focus-visible:ring-2')
            ->and($html)->toContain('min-h-11');
    }
});
