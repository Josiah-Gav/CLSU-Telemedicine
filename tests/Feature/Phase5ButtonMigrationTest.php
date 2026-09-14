<?php

/**
 * Phase 5A: remaining safe button migrations onto the Phase 4 components.
 * Pest cannot execute Alpine/measure rendered buttons, so this locks the
 * source-level wiring in place — component used, correct variant, the
 * original @click/onclick/data-* attributes preserved untouched — per the
 * project's established convention (ButtonComponentsTest, Phase 3/4
 * source-assertion tests).
 */

/**
 * Blade comments ({{-- ... --}}) are compiled away and never reach the
 * browser, but they can quote the very same live code they're explaining —
 * stripping them keeps a substr_count/toContain assertion from matching a
 * docblock's own prose instead of the real markup.
 */
function stripBladeComments(string $source): string
{
    return preg_replace('/\{\{--.*?--\}\}/s', '', $source);
}
it('migrates the scheduled-consultation Start/Reschedule and schedule-builder buttons without losing their handlers', function () {
    $source = file_get_contents(resource_path('views/physician/scheduled_consultation.blade.php'));

    // Start (primary) and Reschedule (secondary) appear twice each — once
    // per table (Follow-up, Initial) — and mobile card + desktop table
    // share the same component tags, verified via MobileWorkflowTableCardsTest.
    expect(substr_count($source, '<x-button-primary'))->toBeGreaterThanOrEqual(4)
        ->and(substr_count($source, '<x-button-secondary'))->toBeGreaterThanOrEqual(4)
        ->and($source)->toContain('@click="startConsultation(consultation)"')
        ->and($source)->toContain('@click="promptReschedule(consultation)"')
        // Alpine's bare :disabled shorthand must never appear on a component
        // tag — Blade would intercept it as a PHP-expression prop binding
        // instead of passing it through to Alpine. Checked as " :disabled="
        // (leading space) so this doesn't false-positive on the correct
        // "x-bind:disabled=" form, which also contains ":disabled=" as a
        // substring.
        ->and($source)->not->toContain(' :disabled="generating"')
        ->and($source)->toContain('x-bind:disabled="generating"')
        ->and($source)->not->toContain(' :disabled="saving"')
        ->and($source)->toContain('x-bind:disabled="saving"')
        ->and($source)->toContain('@click="generateSchedule()"')
        ->and($source)->toContain('@click="saveSchedule()"')
        ->and($source)->toContain('@click="toggleAllGenerated(true)"')
        ->and($source)->toContain('@click="toggleAllGenerated(false)"');
});

it('migrates the physician consultation-history View record/Schedule Follow-up buttons, preserving their data attributes', function () {
    $source = stripBladeComments(file_get_contents(resource_path('views/physician/partials/consultation_history_table.blade.php')));

    expect(substr_count($source, '<x-button-secondary'))->toBe(2)
        ->and(substr_count($source, '<x-button-primary'))->toBe(2)
        ->and(substr_count($source, 'onclick="scheduleFollowUpFromHistory(this)"'))->toBe(2)
        ->and(substr_count($source, 'data-follow-up-url='))->toBe(2)
        ->and(substr_count($source, 'data-slots-url='))->toBe(2)
        ->and(substr_count($source, "route('consultations.messaging.show'"))->toBe(2);
});

it('migrates the patient consultation-details action buttons, preserving routes and the cancel handler', function () {
    $source = stripBladeComments(file_get_contents(resource_path('views/patient/consultation-details.blade.php')));

    expect($source)->toContain('<x-button-ghost href="{{ route(\'consultations.show\', $consultation->parentConsultation->request) }}"')
        ->and($source)->toContain('<x-button-danger')
        ->and($source)->toContain('onclick="cancelConsultation(this);"')
        ->and($source)->toContain("route('consultations.cancel', \$consultation)")
        ->and($source)->toContain('<x-button-primary href="{{ route(\'consultations.messaging.show\', $consultation->consultationSession) }}"')
        ->and($source)->toContain("__('View Chats & Assessment')")
        ->and($source)->toContain("<x-button-secondary href=\"{{ route('dashboard') }}\">Back to Dashboard</x-button-secondary>")
        // The Cancel action is a same-page action with no destination URL —
        // it must render as a real <button> (no href prop), not a link.
        ->and($source)->not->toContain('href="javascript:void(0);"');
});

it('leaves the icon-only attachment-thumbnail and modal-close buttons, and the Alpine-dynamic-href attachment link, unmigrated', function () {
    // These three are deliberately not wrapped in the button components:
    // a thumbnail-shaped preview trigger, a circular icon-only modal-close
    // button (both a different shape entirely from the button components),
    // and an <a :href="previewFile"> whose destination is only known
    // client-side inside x-show — a static Blade `href` prop can't express
    // that without evaluating an Alpine expression as PHP.
    $source = file_get_contents(resource_path('views/patient/consultation-details.blade.php'));

    expect($source)->toContain('@click="previewFile = @js($attachment)"')
        ->and($source)->toContain('@click="previewFile = null"')
        ->and($source)->toContain('<a :href="previewFile"');
});
