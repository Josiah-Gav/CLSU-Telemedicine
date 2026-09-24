<div class="relative ml-auto" x-data="notificationPanel()" x-init="init()">
    <!-- Notification Bell Button -->
    <button 
        type="button" 
        @click="togglePanel()" 
        class="relative inline-flex items-center justify-center rounded-full border border-transparent p-2 text-yellow-400 transition hover:border-brand-green hover:bg-white hover:text-yellow-500"
        aria-label="Notifications"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-6">
  <path fill-rule="evenodd" d="M5.25 9a6.75 6.75 0 0 1 13.5 0v.75c0 2.123.8 4.057 2.118 5.52a.75.75 0 0 1-.297 1.206c-1.544.57-3.16.99-4.831 1.243a3.75 3.75 0 1 1-7.48 0 24.585 24.585 0 0 1-4.831-1.244.75.75 0 0 1-.298-1.205A8.217 8.217 0 0 0 5.25 9.75V9Zm4.502 8.9a2.25 2.25 0 1 0 4.496 0 25.057 25.057 0 0 1-4.496 0Z" clip-rule="evenodd" />
</svg>

        
        <!-- Unread Count Badge -->
        <span
            x-show="unreadCount > 0"
            x-text="unreadCount > 99 ? '99+' : unreadCount"
            class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-bold text-white"
        ></span>
    </button>

    <!-- Notification Dropdown Panel -->
    <div 
        x-show="open" 
        x-cloak 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        @click.away="open = false"
        {{-- Anchored to the viewport on mobile (fixed inset-x-4 top-24), not
             to the bell button: this trigger is never flush with the
             viewport's right edge — the header's own px-4 padding and, on
             mobile, the account-menu button that follows it both eat into
             that space — so a width of calc(100vw-3rem) computed from
             `right-0` on the bell's wrapper overflowed off the left edge.
             Every page's header slot is a single-line <h2> (confirmed
             across resources/views), so a fixed top clearing the header's
             ~5.5rem height is safe everywhere, not just on this page. The
             sm: breakpoint keeps the original trigger-relative behavior,
             which was never affected by this. --}}
        class="fixed inset-x-4 top-24 sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-[calc(100vw-3rem)] max-w-sm rounded-2xl border border-brand-border bg-white shadow-xl z-50"
    >
        <!-- Panel Header -->
        <div class="flex items-center justify-between border-b border-brand-border px-5 py-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-brand-green">Notifications</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">Recent updates</p>
            </div>
            <button 
                type="button" 
                @click="markAllRead()" 
                class="text-xs font-semibold text-brand-green hover:text-brand-green-deep transition"
            >
                Mark all read
            </button>
        </div>

        <!-- Notification List -->
        <div class="max-h-96 overflow-y-auto p-3">
            <!-- Loading State -->
            <template x-if="loading">
                <div class="rounded-2xl border border-dashed border-brand-border bg-slate-50 p-5 text-sm text-slate-500">
                    Loading notifications...
                </div>
            </template>

            <!-- Empty State -->
            <template x-if="!loading && notifList.length === 0">
                <div class="rounded-2xl border border-dashed border-brand-border bg-slate-50 p-5 text-sm text-slate-500">
                    No notifications yet. New alerts will appear here.
                </div>
            </template>

            <!-- Notification Items -->
            <template x-for="n in notifList.slice(0, 5)" :key="n.notification_id">
                <button 
                    type="button" 
                    @click="markAsRead(n)" 
                    class="mb-2 w-full rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-sm"
                    :class="n.read_at ? 'border-slate-200 bg-white' : 'border-brand-green/30 bg-brand-green-soft'"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span 
                            class="inline-flex items-center rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide"
                            :class="n.read_at ? 'bg-slate-200 text-slate-600' : 'bg-brand-green text-white'"
                            x-text="n.read_at ? 'Read' : 'New'"
                        ></span>
                        <span class="text-[11px] font-medium text-slate-500" x-text="formatTime(n.created_at)"></span>
                    </div>
                    <p class="mt-2 text-sm font-bold text-slate-900" x-text="n.title"></p>
                    <p class="mt-1 text-xs text-slate-600 line-clamp-2" x-text="n.message"></p>
                </button>
            </template>
        </div>

        <!-- Panel Footer -->
        <div class="border-t border-brand-border px-5 py-3">
            <a
                href="{{ route('notifications.all') }}"
                class="block text-center text-sm font-semibold text-brand-green hover:text-brand-green-deep transition"
            >
                View all Notifications
            </a>
        </div>
    </div>
</div>