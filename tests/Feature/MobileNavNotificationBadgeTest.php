<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;

/**
 * Phase 5A: the mobile bottom-nav's "Dashboard" icon (every role has one)
 * now carries the same unread-notification count already computed by
 * NotificationController::unreadCount() and already displayed by the
 * header bell (notificationPanel(), layouts/notificationUI.blade.php).
 * There is exactly one poller — this component only reads
 * Alpine.store('notifications').unreadCount, which notificationPanel()
 * writes to. Pest cannot execute the Alpine reactivity itself, so this
 * locks in the markup/wiring at the source level, per the project's
 * established convention (ButtonComponentsTest, PhysicianConsultationInboxTableOverflowTest).
 */
it('gives every role a Dashboard nav icon with the notification badge enabled', function () {
    $source = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

    // Nurse, physician, admin, and patient/default each render exactly one
    // Dashboard mobile-nav-icon — all four must carry the badge.
    expect(substr_count($source, ':notification-badge="true"'))->toBe(4);
});

it('does not add the badge to any other mobile-nav destination', function () {
    $source = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

    $iconCalls = substr_count($source, '<x-mobile-nav-icon');
    $badgedCalls = substr_count($source, ':notification-badge="true"');

    expect($iconCalls)->toBeGreaterThan($badgedCalls);
});

it('renders no badge markup at all when notification-badge is not passed', function () {
    $html = Blade::render('<x-mobile-nav-icon href="/x" :active="false" path="M0 0" label="Consultation Inbox" />');

    expect($html)->not->toContain('$store.notifications')
        ->and($html)->toContain('aria-label="Consultation Inbox"');
});

it('renders the badge bound to the shared Alpine store when enabled, with a 99+ cap and an accessible name that includes the count', function () {
    $html = Blade::render('<x-mobile-nav-icon href="/dashboard" :active="true" path="M0 0" label="Dashboard" :notification-badge="true" />');

    expect($html)->toContain("\$store.notifications.unreadCount")
        ->and($html)->toContain("'99+'")
        ->and($html)->toContain('aria-current="page"')
        // The dynamic :aria-label must still mention the plain label when
        // there are zero unread, not just when there's a count.
        ->and($html)->toContain('Dashboard');
});

it('registers the shared notifications store before Alpine starts', function () {
    $source = file_get_contents(resource_path('js/app.js'));

    // Search for the real Alpine.start(); call (with its terminating
    // semicolon) rather than the bare substring "Alpine.start()", which
    // also appears inside this file's own explanatory comment above the
    // store registration.
    expect($source)->toContain("Alpine.store('notifications'")
        ->and(strpos($source, "Alpine.store('notifications'"))->toBeLessThan(strpos($source, 'Alpine.start();'));
});

it('writes every unread-count change into the shared store, not just the header bell\'s own state', function () {
    $source = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

    // fetchUnreadCount (poll), markAsRead (decrement), and markAllRead
    // (reset to 0) each mutate the header's local unreadCount — every one
    // of those must also sync the store the bottom nav reads.
    expect(substr_count($source, "Alpine.store('notifications').unreadCount"))->toBe(3);
});

it('still scopes the unread count to the authenticated user only, unchanged by this phase', function () {
    $viewer = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $other = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    \App\Models\Notification::create([
        'user_id' => $other->user_id,
        'type' => \App\Enums\NotificationType::NEW_MESSAGE->value,
        'title' => 'Not yours',
        'message' => 'Should never be counted for $viewer',
    ]);

    $response = $this->actingAs($viewer)->getJson(route('notifications.unread_count'));

    $response->assertOk()->assertJsonPath('data.unread_count', 0);
});
