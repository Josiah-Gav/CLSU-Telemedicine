<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Sweet Alert -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <!-- jQuery -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-[#f4f8f3] text-slate-800">
        <div class="md:min-h-dvh bg-[radial-gradient(circle_at_top,_rgba(217,182,72,0.12),transparent_30%),linear-gradient(180deg,_#eff8f1_0%,_#f5f7f3_100%)]">
            <div x-data="{ sidebarOpen: true }" class="flex md:min-h-dvh flex-col lg:flex-row">
                @include('layouts.navigation')

                <div class="min-w-0 flex-1 transition-all duration-300">
                    <!-- Page Heading -->
                    @isset($header)
                        <header class="border-b border-brand-border bg-gradient-to-r from-brand-green to-brand-green-deep text-white shadow-sm">
                            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    {{ $header }}
                                </div>
                                @auth
                                    <div class="flex-shrink-0 flex items-center gap-1">
                                        @include('layouts.notificationUI')

                                        <div class="md:hidden">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="trigger">
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center justify-center rounded-full bg-white/10 p-2 text-white/90 transition hover:bg-white/20 hover:text-white"
                                                        aria-label="{{ __('Account menu') }}"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-6">
                                                            <path fill-rule="evenodd" d="M11.828 2.25c-.916 0-1.699.663-1.85 1.567l-.091.549a.798.798 0 0 1-.517.608 7.45 7.45 0 0 0-.478.198.798.798 0 0 1-.796-.064l-.453-.324a1.875 1.875 0 0 0-2.416.2l-.243.243a1.875 1.875 0 0 0-.2 2.416l.324.453a.798.798 0 0 1 .064.796 7.448 7.448 0 0 0-.198.478.798.798 0 0 1-.608.517l-.55.092a1.875 1.875 0 0 0-1.566 1.849v.344c0 .916.663 1.699 1.567 1.85l.549.091c.281.047.508.25.608.517.06.162.127.321.198.478a.798.798 0 0 1-.064.796l-.324.453a1.875 1.875 0 0 0 .2 2.416l.243.243c.648.648 1.67.733 2.416.2l.453-.324a.798.798 0 0 1 .796-.064c.157.071.316.137.478.198.267.1.47.327.517.608l.092.55c.15.903.932 1.566 1.849 1.566h.344c.916 0 1.699-.663 1.85-1.567l.091-.549a.798.798 0 0 1 .517-.608 7.52 7.52 0 0 0 .478-.198.798.798 0 0 1 .796.064l.453.324a1.875 1.875 0 0 0 2.416-.2l.243-.243c.648-.648.733-1.67.2-2.416l-.324-.453a.798.798 0 0 1-.064-.796c.071-.157.137-.316.198-.478.1-.267.327-.47.608-.517l.55-.091a1.875 1.875 0 0 0 1.566-1.85v-.344c0-.916-.663-1.699-1.567-1.85l-.549-.091a.798.798 0 0 1-.608-.517 7.507 7.507 0 0 0-.198-.478.798.798 0 0 1 .064-.796l.324-.453a1.875 1.875 0 0 0-.2-2.416l-.243-.243a1.875 1.875 0 0 0-2.416-.2l-.453.324a.798.798 0 0 1-.796.064 7.462 7.462 0 0 0-.478-.198.798.798 0 0 1-.517-.608l-.091-.55a1.875 1.875 0 0 0-1.85-1.566h-.344ZM12 15.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z" clip-rule="evenodd" />
                                                        </svg>

                                                    </button>
                                                </x-slot>

                                                <x-slot name="content">
                                                    <x-dropdown-link :href="route('profile.edit')">
                                                        {{ __('Profile') }}
                                                    </x-dropdown-link>

                                                    <form method="POST" action="{{ route('logout') }}">
                                                        @csrf
                                                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                                            {{ __('Log Out') }}
                                                        </x-dropdown-link>
                                                    </form>
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </div>
                                @endauth
                            </div>
                        </header>
                    @endisset

                    <!-- Page Content -->
                    <main class="pb-[calc(6rem+env(safe-area-inset-bottom))] sm:pb-0">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>

        @auth
        @php
            // Physician consultation intake rides along on the presence
            // heartbeat below rather than running a timer of its own, so a
            // physician who opens intake and then works elsewhere in the app
            // keeps it alive. Resolved here because the layout has no
            // controller of its own; it is one indexed lookup on
            // (physician_id, status), and only for physicians.
            //
            // currentSessionFor() returns only an already-open session, which
            // is the only thing a heartbeat may ever touch — this flag can
            // start the beat but can never create or reopen a session.
            $intakeHeartbeatUrl = null;
            $intakeIsOpen = false;

            if (Auth::user()->role === 'physician') {
                $intakeHeartbeatUrl = route('physician.consultation_intake.heartbeat', ['physician' => Auth::id()]);
                $intakeIsOpen = app(\App\Services\PhysicianAvailabilityService::class)
                    ->currentSessionFor(Auth::user()) !== null;
            }
        @endphp
        <script>
            // Shared with the Consultation Intake page, which flips `open` when
            // the physician opens or closes intake so this one timer picks the
            // change up immediately. Declared for every authenticated user so
            // that page never has to null-check it.
            window.telemedIntakeHeartbeat = {
                url: @json($intakeHeartbeatUrl),
                open: @json($intakeIsOpen),
            };
        </script>
        <script>
            (function () {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

                if (!csrfToken) {
                    return;
                }

                function sendHeartbeat() {
                    fetch('{{ route('presence.heartbeat') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    }).catch(() => {});

                    sendIntakeHeartbeat();
                }

                // Keeps an already-open intake session alive. Skipped entirely
                // unless intake is believed open, so a closed or expired
                // session is never repeatedly touched. CSRF-protected like
                // every other authenticated POST — the token above is the same
                // one the endpoint expects.
                function sendIntakeHeartbeat() {
                    const intake = window.telemedIntakeHeartbeat;

                    if (!intake || !intake.url || !intake.open) {
                        return;
                    }

                    fetch(intake.url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            // The server is the only authority on whether the
                            // session survived. Once it says the session is no
                            // longer open, stop beating and let the physician
                            // decide whether to open intake again — nothing
                            // here ever reopens one.
                            if (!data.open) {
                                intake.open = false;
                            }

                            // Lets the Consultation Intake page keep its status
                            // card current without owning a second timer.
                            window.dispatchEvent(new CustomEvent('telemed:intake-heartbeat', { detail: data }));
                        })
                        // A transient network failure changes nothing locally.
                        // Heartbeats simply stop arriving and the server's own
                        // staleness threshold decides when the session stops
                        // counting.
                        .catch(() => {});
                }

                // Send an initial heartbeat on page load
                sendHeartbeat();

                // Send a heartbeat every 60 seconds to keep presence fresh
                window.setInterval(sendHeartbeat, 60000);
            })();
        </script>
        @endauth
    </body>
</html>
