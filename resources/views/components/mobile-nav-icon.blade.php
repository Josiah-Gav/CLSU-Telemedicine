@props(['href', 'active' => false, 'path', 'label', 'notificationBadge' => false])

{{--
    Icon-only mobile bottom-nav item. No visible label, so the <a>'s
    accessible name normally comes from a static aria-label.

    `notification-badge`: only the "Dashboard" destination on each role's
    bottom nav passes this — see layouts/navigation.blade.php. Reads
    Alpine.store('notifications').unreadCount (registered in
    resources/js/app.js, written to by notificationPanel() in
    layouts/notificationUI.blade.php, the same header-bell poller — this
    component never fetches its own count). $store is a global Alpine
    magic property, so it works here with no local x-data of its own.

    aria-label becomes a dynamic `:aria-label` binding only when the badge
    is enabled, so the accessible name includes the count ("Dashboard — 3
    unread") the same way a sighted user sees it — not decorative text a
    screen-reader user would miss. Every other call site keeps the plain
    static aria-label, unchanged.
--}}
<a
    href="{{ $href }}"
    @if ($notificationBadge)
        x-data
        :aria-label="$store.notifications.unreadCount > 0 ? '{{ __($label) }} — ' + ($store.notifications.unreadCount > 99 ? '99+' : $store.notifications.unreadCount) + ' unread' : '{{ __($label) }}'"
    @else
        aria-label="{{ __($label) }}"
    @endif
    title="{{ __($label) }}"
    @if ($active) aria-current="page" @endif
    class="flex min-h-11 flex-1 items-center justify-center rounded-md py-2 {{ $notificationBadge ? 'relative' : '' }} {{ $active ? 'bg-clsu-green text-white' : 'text-gray-600' }}"
>
    <svg class="h-6 w-6 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $path }}" />
    </svg>
    @if ($notificationBadge)
        <span
            x-show="$store.notifications.unreadCount > 0"
            x-text="$store.notifications.unreadCount > 99 ? '99+' : $store.notifications.unreadCount"
            x-cloak
            aria-hidden="true"
            class="absolute right-2 top-0.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white"
        ></span>
    @endif
</a>
