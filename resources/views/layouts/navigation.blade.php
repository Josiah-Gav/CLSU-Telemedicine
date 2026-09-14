<nav :class="sidebarOpen ? 'lg:w-72' : 'lg:w-20'" class="lg:flex-shrink-0">
    <aside :class="sidebarOpen ? 'w-72' : 'w-20 sidebar-collapsed'" class="hidden md:flex h-screen flex-col border-r border-brand-border bg-white/90 shadow-[0_0_0_1px_rgba(15,23,42,0.04)] backdrop-blur-sm lg:flex fixed lg:sticky lg:top-0 z-40 transition-all duration-300">
        <div :class="sidebarOpen ? 'px-5' : 'px-2'" class="flex items-center gap-3 border-b border-brand-border bg-brand-green py-4">
            <a :class="sidebarOpen ? 'justify-start' : 'justify-center'" href="{{ Auth::check() && Auth::user()->role === 'nurse' ? route('nurse.dashboard', ['nurse' => Auth::user()]) : route('dashboard') }}" class="flex w-full items-center gap-3">
                <x-application-logo class="block h-8 w-auto fill-current text-white flex-shrink-0" />
                <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="text-base font-bold tracking-wide text-white whitespace-nowrap">CLSU Telemedicine</span>
            </a>
        </div>

        <div class="flex-1 overflow-y-auto px-3 py-5">
            <div class="space-y-1">
                <!-- Collapse toggle button at top of nav links -->
                <button @click="sidebarOpen = !sidebarOpen" class="mb-2 flex w-full items-center justify-center rounded-xl border border-brand-border bg-white p-2 text-slate-700 transition hover:bg-brand-green-soft hover:text-brand-green-deep">
                    <svg x-show="sidebarOpen" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 16.811c0 .864-.933 1.406-1.683.977l-7.108-4.061a1.125 1.125 0 0 1 0-1.954l7.108-4.061A1.125 1.125 0 0 1 21 8.689v8.122ZM11.25 16.811c0 .864-.933 1.406-1.683.977l-7.108-4.061a1.125 1.125 0 0 1 0-1.954l7.108-4.061a1.125 1.125 0 0 1 1.683.977v8.122Z"/>
                    </svg>
                    <svg x-show="!sidebarOpen" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                @if(Auth::check() && Auth::user()->role === 'nurse')
                    <x-nav-link :href="route('nurse.dashboard', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.dashboard')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Dashboard') }}</span>
                    </x-nav-link>

                    <x-nav-link :href="route('nurse.consultation_inbox', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.consultation_inbox')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Consultation Inbox') }}</span>
                    </x-nav-link>

                    <x-nav-link :href="route('nurse.follow_up_requests', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.follow_up_requests')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Follow-up Requests') }}</span>
                    </x-nav-link>

                    <x-nav-link :href="route('nurse.consultation_history', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.consultation_history')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Consultation History') }}</span>
                    </x-nav-link>
                @elseif(Auth::check() && Auth::user()->role === 'physician')
                    <x-nav-link :href="route('physician.dashboard', ['physician' => Auth::user()])" :active="request()->routeIs('physician.dashboard')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Dashboard') }}</span>
                    </x-nav-link>
                    <x-nav-link :href="route('physician.consultation_inbox', ['physician' => Auth::user()])" :active="request()->routeIs('physician.consultation_inbox')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Consultation Inbox') }}</span>
                    </x-nav-link>
                    <x-nav-link :href="route('physician.follow_up_requests', ['physician' => Auth::user()])" :active="request()->routeIs('physician.follow_up_requests')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Follow-up Requests') }}</span>
                    </x-nav-link>
                    <x-nav-link :href="route('physician.active_consultation', ['physician' => Auth::user()])" :active="request()->routeIs('physician.active_consultation')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Active Consultations') }}</span>
                    </x-nav-link>
                    <x-nav-link :href="route('physician.scheduled_consultation', ['physician' => Auth::user()])" :active="request()->routeIs('physician.scheduled_consultation')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Scheduled Consultations') }}</span>
                    </x-nav-link>
                    <x-nav-link :href="route('physician.consultation_history', ['physician' => Auth::user()])" :active="request()->routeIs('physician.consultation_history')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Consultation History') }}</span>
                    </x-nav-link>
                @elseif(Auth::check() && Auth::user()->role === 'admin')
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Dashboard') }}</span>
                    </x-nav-link>

                    <x-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.index')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 014 4V7h1.5a2.5 2.5 0 012.5 2.5v11a1 1 0 01-1 1H4a1 1 0 01-1-1v-11a2.5 2.5 0 012.5-2.5H8V8.354a4 4 0 014-4z"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('User Management') }}</span>
                    </x-nav-link>
                @else
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Dashboard') }}</span>
                    </x-nav-link>

                    <x-nav-link :href="route('newconsultation')" :active="request()->routeIs('newconsultation')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('New Consultation') }}</span>
                    </x-nav-link>

                    <x-nav-link :href="route('consultations.history')" :active="request()->routeIs('consultations.history')">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="whitespace-nowrap">{{ __('Consultation History') }}</span>
                    </x-nav-link>
                @endif
            </div>
        </div>

        @auth
        <div class="border-t border-brand-border bg-brand-green-soft/70 px-3 py-4">
            <div class="flex items-center gap-2">
                <div class="flex-1">
                    <div x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in-out duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="rounded-xl border border-brand-border bg-white p-2 shadow-sm">
                        <div class="mb-2 truncate px-2 text-sm font-semibold text-slate-800">{{ Auth::user()->name }}</div>

                        <div class="flex items-center gap-2">
                            <a href="{{ route('profile.edit') }}" class="inline-flex flex-1 items-center justify-center rounded-lg border border-brand-border bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-brand-green-soft hover:text-brand-green-deep">
                                {{ __('Profile') }}
                            </a>

                            <form method="POST" action="{{ route('logout') }}" class="flex-1">
                                @csrf

                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-brand-border bg-brand-green px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-green-deep">
                                    {{ __('Log Out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                    <div x-show="!sidebarOpen" x-cloak class="flex justify-center">
                        <div class="relative group">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-green text-white font-semibold">
                                {{ substr(Auth::user()->name, 0, 1) }}
                            </div>
                            <div class="absolute left-full ml-2 hidden group-hover:block z-50 w-48 rounded-lg border border-brand-border bg-white p-2 shadow-lg">
                                <div class="mb-2 px-2 text-sm font-semibold text-slate-800">{{ Auth::user()->name }}</div>
                                <div class="flex flex-col gap-2">
                                    <a href="{{ route('profile.edit') }}" class="text-sm text-slate-700 hover:text-brand-green">{{ __('Profile') }}</a>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="text-sm text-slate-700 hover:text-red-600">{{ __('Log Out') }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endauth
    </aside>

    <!-- Responsive navigation removed; mobile access provided by bottom nav and logout button -->
</nav>

    <script>
        // Shared by notificationPanel() (the header dropdown) and the full
        // "All Notifications" page (notifications/index.blade.php), so a
        // notification always opens the same place no matter where it was
        // clicked from — one switch statement, not two copies to keep in sync.
        window.notificationNav = {
            resolveUrl(n, role, userId) {
                const d = n.data || {};
                const id = userId;
                let url = '{{ route('dashboard') }}';

                switch (n.type) {
                    case 'new_message':
                    case 'new_attachment':
                        if (d.session_id) {
                            url = '{{ url('consultation-sessions') }}' + '/' + d.session_id + '/messaging';
                        }
                        break;
                    case 'consultation_scheduled':
                    case 'consultation_rescheduled':
                    case 'consultation_started':
                    case 'consultation_completed':
                    case 'consultation_missed':
                        if (role === 'patient' && d.request_id) {
                            url = '{{ url('consultations') }}' + '/' + d.request_id;
                        } else if (role === 'physician') {
                            url = '{{ url('physicians') }}' + '/' + id + '/consultation-inbox';
                        }
                        break;
                    case 'consultation_submitted':
                        url = '{{ url('nurses') }}' + '/' + id + '/consultation-inbox';
                        break;
                    case 'consultation_assigned':
                    case 'high_priority_consultation':
                        url = '{{ url('physicians') }}' + '/' + id + '/consultation-inbox';
                        break;
                    case 'follow_up_submitted':
                        if (role === 'nurse') {
                            url = '{{ url('nurses') }}' + '/' + id + '/follow-up-requests';
                        } else if (role === 'physician') {
                            url = '{{ url('physicians') }}' + '/' + id + '/follow-up-requests';
                        }
                        break;
                    case 'follow_up_approved':
                    case 'follow_up_rejected':
                    case 'follow_up_scheduled':
                    case 'follow_up_starting_soon':
                    case 'physician_request':
                        if (role === 'patient') {
                            url = '{{ route('patient.follow_up_list') }}';
                        }
                        break;
                }

                return url;
            },
            formatTime(iso) {
                if (!iso) return '';
                const date = new Date(iso);
                if (Number.isNaN(date.getTime())) return '';
                const diff = (Date.now() - date.getTime()) / 1000;
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
                if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
                if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
                return date.toLocaleDateString();
            }
        };

        function notificationPanel() {
            return {
                open: false,
                loading: false,
                notifList: [],
                unreadCount: 0,
                poller: null,
                csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                userId: {{ (int) auth()->id() }},
                role: @js(auth()->user()->role),
                routes: {
                    index: '{{ route('notifications.index') }}',
                    unread: '{{ route('notifications.unread_count') }}',
                    readAll: '{{ route('notifications.read_all') }}'
                },
                init() {
                    this.fetchUnreadCount();
                    this.poller = setInterval(() => this.fetchUnreadCount(), 30000);
                },
                togglePanel() {
                    this.open = !this.open;
                    if (this.open && this.notifList.length === 0) {
                        this.fetchNotifications();
                    }
                },
                fetchUnreadCount() {
                    $.ajax({
                        url: this.routes.unread,
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        success: (data) => {
                            this.unreadCount = data?.data?.unread_count ?? 0;
                        }
                    });
                },
                fetchNotifications() {
                    this.loading = true;
                    $.ajax({
                        url: this.routes.index,
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        success: (data) => {
                            this.notifList = data?.data ?? [];
                        },
                        complete: () => {
                            this.loading = false;
                        }
                    });
                },
                markAsRead(n) {
                    if (n.read_at) {
                        this.navigate(n);
                        return;
                    }

                    const url = this.routes.index + '/' + n.notification_id + '/read';

                    $.ajax({
                        url: url,
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        success: () => {
                            n.read_at = new Date().toISOString();
                            this.unreadCount = Math.max(0, this.unreadCount - 1);
                            this.navigate(n);
                        },
                        error: () => {
                            this.navigate(n);
                        }
                    });
                },
                markAllRead() {
                    $.ajax({
                        url: this.routes.readAll,
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        success: () => {
                            this.notifList.forEach(n => {
                                if (!n.read_at) {
                                    n.read_at = new Date().toISOString();
                                }
                            });
                            this.unreadCount = 0;
                        }
                    });
                },
                navigate(n) {
                    window.location.href = window.notificationNav.resolveUrl(n, this.role, this.userId);
                },
                formatTime(iso) {
                    return window.notificationNav.formatTime(iso);
                }
            };
        }

        window.notificationPanel = notificationPanel;
    </script>

    @php
        // Shared with the desktop sidebar above — same paths, so a module's
        // icon on mobile is always the exact icon it has on desktop.
        $navIcon = [
            'dashboard' => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
            'inbox' => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z',
            'follow_up' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
            'history' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            'active' => 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z',
            'scheduled' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
            'users' => 'M12 4.354a4 4 0 014 4V7h1.5a2.5 2.5 0 012.5 2.5v11a1 1 0 01-1 1H4a1 1 0 01-1-1v-11a2.5 2.5 0 012.5-2.5H8V8.354a4 4 0 014-4z',
            'new' => 'M12 4v16m8-8H4',
        ];

        // A patient's floating action button is a shortcut straight into their
        // live consultation's chat, so it only exists when there is one. Resolved
        // here (once per render) rather than in each controller because this
        // partial is included on every page.
        $patientLiveSession = Auth::check() && Auth::user()->role === 'patient'
            ? Auth::user()->activeConsultationSession()
            : null;
    @endphp

    <!-- Mobile bottom navigation (visible only on small screens). Icon-only
         by design: matching each role's full sidebar link set (not just a
         subset) only fits in this space without labels. Every icon reuses
         the exact path from the corresponding sidebar link above, and each
         link's accessible name comes from aria-label since there's no
         visible text. -->
    <nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 sm:hidden z-40">
        <div class="max-w-7xl mx-auto px-2 pt-1 pb-[calc(0.75rem+env(safe-area-inset-bottom))] flex items-center">
            @if(Auth::check() && Auth::user()->role === 'nurse')
                <x-mobile-nav-icon :href="route('nurse.dashboard', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.dashboard')" :path="$navIcon['dashboard']" label="Dashboard" />
                <x-mobile-nav-icon :href="route('nurse.consultation_inbox', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.consultation_inbox')" :path="$navIcon['inbox']" label="Consultation Inbox" />
                <x-mobile-nav-icon :href="route('nurse.follow_up_requests', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.follow_up_requests')" :path="$navIcon['follow_up']" label="Follow-up Requests" />
                <x-mobile-nav-icon :href="route('nurse.consultation_history', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.consultation_history')" :path="$navIcon['history']" label="Consultation History" />
            @elseif(Auth::check() && Auth::user()->role === 'physician')
                <x-mobile-nav-icon :href="route('physician.dashboard', ['physician' => Auth::user()])" :active="request()->routeIs('physician.dashboard')" :path="$navIcon['dashboard']" label="Dashboard" />
                <x-mobile-nav-icon :href="route('physician.consultation_inbox', ['physician' => Auth::user()])" :active="request()->routeIs('physician.consultation_inbox')" :path="$navIcon['inbox']" label="Consultation Inbox" />
                <x-mobile-nav-icon :href="route('physician.follow_up_requests', ['physician' => Auth::user()])" :active="request()->routeIs('physician.follow_up_requests')" :path="$navIcon['follow_up']" label="Follow-up Requests" />
                <x-mobile-nav-icon :href="route('physician.scheduled_consultation', ['physician' => Auth::user()])" :active="request()->routeIs('physician.scheduled_consultation')" :path="$navIcon['scheduled']" label="Scheduled Consultations" />
                <x-mobile-nav-icon :href="route('physician.consultation_history', ['physician' => Auth::user()])" :active="request()->routeIs('physician.consultation_history')" :path="$navIcon['history']" label="Consultation History" />
            @elseif(Auth::check() && Auth::user()->role === 'admin')
                <x-mobile-nav-icon :href="route('dashboard')" :active="request()->routeIs('dashboard')" :path="$navIcon['dashboard']" label="Dashboard" />
                <x-mobile-nav-icon :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')" :path="$navIcon['users']" label="User Management" />
            @else
                <x-mobile-nav-icon :href="route('dashboard')" :active="request()->routeIs('dashboard')" :path="$navIcon['dashboard']" label="Dashboard" />
                <x-mobile-nav-icon :href="route('newconsultation')" :active="request()->routeIs('newconsultation')" :path="$navIcon['new']" label="New Consultation" />
                <x-mobile-nav-icon :href="route('consultations.history')" :active="request()->routeIs('consultations.history')" :path="$navIcon['history']" label="Consultation History" />
            @endif
        </div>
    </nav>

    @if(Auth::check() && Auth::user()->role === 'physician' && ! request()->routeIs('physician.active_consultation') && ! request()->routeIs('consultations.messaging.show'))
        {{-- Floating action button — the active-consultation icon moved here
             from the bottom nav row so it reads as the primary action
             rather than one item among equals. Hidden on the destination
             page itself, and positioned clear of the fixed bottom nav.
             Also hidden on the messaging page: it would float directly over
             the message composer, which sits in that same bottom-right area. --}}
        <a
            href="{{ route('physician.active_consultation', ['physician' => Auth::user()]) }}"
            aria-label="{{ __('Active Consultations') }}"
            title="{{ __('Active Consultations') }}"
            class="fixed bottom-[calc(5rem+env(safe-area-inset-bottom))] right-4 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-brand-green text-white shadow-lg transition hover:bg-brand-green-deep sm:hidden"
        >
            <svg class="h-6 w-6 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $navIcon['active'] }}" />
            </svg>
        </a>
    @elseif($patientLiveSession && ! request()->routeIs('consultations.messaging.show'))
        {{-- Patients get the FAB only while a consultation is actually running,
             and it drops them straight into that consultation's messaging page
             — the only thing there is to act on. Hidden on the messaging page
             itself, where it would float over the message composer. --}}
        <a
            href="{{ route('consultations.messaging.show', $patientLiveSession) }}"
            aria-label="{{ __('Active Consultation') }}"
            title="{{ __('Active Consultation') }}"
            class="fixed bottom-[calc(5rem+env(safe-area-inset-bottom))] right-4 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-brand-green text-white shadow-lg transition hover:bg-brand-green-deep sm:hidden"
        >
            <svg class="h-6 w-6 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $navIcon['active'] }}" />
            </svg>
        </a>
    @endif
