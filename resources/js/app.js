import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Shared across the header notification bell (notificationPanel(), in
// layouts/notificationUI.blade.php) and the mobile bottom-nav badge
// (mobile-nav-icon.blade.php) so there is exactly one poller for the
// unread count — notificationPanel() is the only thing that writes here,
// the bottom nav only reads it. Registered before Alpine.start() so the
// store exists by the time either component's x-init runs.
document.addEventListener('alpine:init', () => {
    Alpine.store('notifications', { unreadCount: 0 });
});

Alpine.start();
