<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'CLSU Telemedicine') }} — CLSU Infirmary</title>
        <meta name="description" content="Request a consultation, message CLSU Infirmary medical staff, and manage follow-up care online.">

        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

        <!-- Fonts (matches layouts/app.blade.php and layouts/guest.blade.php) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            @media (prefers-reduced-motion: no-preference) {
                .reveal { animation: reveal-in .5s ease-out both; }
                .reveal-delay-1 { animation-delay: .08s; }
                .reveal-delay-2 { animation-delay: .16s; }
            }
            @keyframes reveal-in {
                from { opacity: 0; transform: translateY(8px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .animate-pulse { animation: none; }
            }
        </style>
    </head>
    <body class="font-sans antialiased bg-white text-gray-900">

        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-[60] focus:rounded-lg focus:bg-brand-green focus:px-4 focus:py-2 focus:text-white">
            Skip to content
        </a>

        {{-- ============================= NAVIGATION ============================= --}}
        <header class="sticky top-0 z-50 border-b border-brand-border bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80">
            <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3 lg:px-8" aria-label="Primary">
                <a href="{{ url('/') }}" class="flex items-center gap-2.5 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                    <img src="{{ asset('images/clsu_logo.png') }}" alt="Central Luzon State University seal" class="h-9 w-9 shrink-0 rounded-full">
                    <span class="text-base font-semibold leading-tight text-gray-900">
                        CLSU Infirmary
                        <span class="block text-xs font-medium text-brand-green-deep">Telemedicine</span>
                    </span>
                </a>

                @if (Route::has('login'))
                    <div class="flex items-center gap-2 sm:gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}"
                               class="inline-flex min-h-11 items-center rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-brand-green-soft hover:text-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                Go to Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}"
                               class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-brand-green-soft hover:text-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 sm:px-4">
                                Sign In
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}"
                                   class="inline-flex min-h-11 items-center rounded-lg bg-brand-green px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                    Get Started
                                </a>
                            @endif
                        @endauth
                    </div>
                @endif
            </nav>
        </header>

        <main id="main-content">
            {{-- ============================= HERO ============================= --}}
            <section class="border-b border-brand-border bg-gradient-to-br from-brand-green-soft via-white to-brand-gold-soft">
                <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 lg:grid-cols-2 lg:gap-16 lg:px-8 lg:py-24">
                    <div class="reveal">
                        <p class="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-brand-green-deep">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                            CLSU Infirmary
                        </p>
                        <h1 class="text-4xl font-bold leading-tight tracking-tight text-gray-900 sm:text-5xl">
                            Healthcare support, wherever you are on campus.
                        </h1>
                        <p class="mt-5 max-w-xl text-lg leading-relaxed text-gray-600">
                            Students, faculty, and staff can request a consultation, message
                            CLSU Infirmary medical staff, and follow up on care — without a
                            trip to the infirmary desk.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}"
                                   class="inline-flex min-h-12 items-center justify-center rounded-lg bg-brand-green px-6 py-3 text-base font-semibold text-white shadow-sm transition-colors hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                    Get Started
                                </a>
                            @endif
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}"
                                   class="inline-flex min-h-12 items-center justify-center rounded-lg border border-brand-border bg-white px-6 py-3 text-base font-semibold text-gray-900 transition-colors hover:bg-brand-green-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                    Sign In
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Hero visual: abstract SVG composition (no stock photo — see design-system/pages/welcome.md) --}}
                    <div class="reveal reveal-delay-1" role="img" aria-label="Illustration of the consultation status flow: pending, reviewed, then connected with medical staff">
                        <div class="relative rounded-2xl border border-brand-border bg-white p-6 shadow-sm sm:p-8">
                            <div class="flex items-center gap-3 border-b border-brand-border pb-5">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-green-soft text-brand-green-deep" aria-hidden="true">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                                </span>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">Consultation request</p>
                                    <p class="text-xs text-gray-500">Submitted just now</p>
                                </div>
                            </div>

                            <ol class="mt-5 space-y-4">
                                <li class="flex items-center gap-3">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-green text-white" aria-hidden="true">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                    </span>
                                    <span class="text-sm font-medium text-gray-900">Request received</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-green text-white" aria-hidden="true">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                    </span>
                                    <span class="text-sm font-medium text-gray-900">Reviewed by nurse triage</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <span class="flex h-7 w-7 shrink-0 animate-pulse items-center justify-center rounded-full bg-brand-gold-soft text-brand-green-deep ring-2 ring-brand-gold" aria-hidden="true">
                                        <span class="h-2 w-2 rounded-full bg-brand-gold"></span>
                                    </span>
                                    <span class="text-sm font-semibold text-brand-green-deep">Connecting with medical staff…</span>
                                </li>
                            </ol>

                            <div class="mt-6 flex items-center gap-2 rounded-lg bg-brand-gold-soft px-3 py-2.5">
                                <img src="{{ asset('images/clsu_logo.png') }}" alt="" class="h-6 w-6 shrink-0 opacity-80">
                                <p class="text-xs text-brand-green-deep">Central Luzon State University · Infirmary</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ============================= TRUST INDICATORS ============================= --}}
            <section class="border-b border-brand-border bg-brand-muted" aria-label="Why use this system">
                <div class="mx-auto max-w-6xl px-6 py-14 lg:px-8">
                    <div class="grid gap-8 sm:grid-cols-3">
                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-brand-border bg-white text-brand-green-deep shadow-sm" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                            </span>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">University Infirmary</h2>
                                <p class="mt-1 text-sm leading-relaxed text-gray-600">Care connected to your CLSU community, from the same infirmary staff you'd see in person.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-brand-border bg-white text-brand-green-deep shadow-sm" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                            </span>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Secure Access</h2>
                                <p class="mt-1 text-sm leading-relaxed text-gray-600">Your account and consultation information are only visible to you and the staff assigned to your case.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-brand-border bg-white text-brand-green-deep shadow-sm" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                            </span>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">Remote Consultation</h2>
                                <p class="mt-1 text-sm leading-relaxed text-gray-600">Connect with infirmary medical staff when visiting in person isn't practical.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ============================= CORE SERVICES ============================= --}}
            <section id="services" class="bg-white py-20 lg:py-24" aria-labelledby="services-heading">
                <div class="mx-auto max-w-6xl px-6 lg:px-8">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-wide text-brand-green-deep">Services</p>
                        <h2 id="services-heading" class="mt-2 text-3xl font-bold text-gray-900 sm:text-4xl">What you can do here</h2>
                        <p class="mt-3 text-lg leading-relaxed text-gray-600">A straightforward path from raising a concern to getting care.</p>
                    </div>

                    <div class="mt-12 grid gap-6 sm:grid-cols-2">
                        <div class="rounded-lg border border-brand-border bg-white p-6 transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-green-soft text-brand-green-deep" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            </span>
                            <h3 class="mt-4 text-lg font-semibold text-gray-900">Request a Consultation</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Submit your symptoms and health concern to the infirmary, and it's triaged by a nurse.</p>
                        </div>

                        <div class="rounded-lg border border-brand-border bg-white p-6 transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-green-soft text-brand-green-deep" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 10.5h7.5m-7.5 3h4.5m-7.5-9h15A2.25 2.25 0 0 1 21.75 6.75v7.5A2.25 2.25 0 0 1 19.5 16.5H8.25l-4.5 4.5V6.75A2.25 2.25 0 0 1 6 4.5Z"/></svg>
                            </span>
                            <h3 class="mt-4 text-lg font-semibold text-gray-900">Chat with Medical Staff</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Once a physician accepts your request, communicate directly through the consultation workflow.</p>
                        </div>

                        <div class="rounded-lg border border-brand-border bg-white p-6 transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-gold-soft text-brand-green-deep" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                            </span>
                            <h3 class="mt-4 text-lg font-semibold text-gray-900">Follow-up Care</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Continue communication after a completed consultation when a follow-up is needed.</p>
                        </div>

                        <div class="rounded-lg border border-brand-border bg-white p-6 transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-gold-soft text-brand-green-deep" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
                            </span>
                            <h3 class="mt-4 text-lg font-semibold text-gray-900">Consultation Updates</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Stay informed about the status of your request and get notified as it progresses.</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ============================= HOW IT WORKS ============================= --}}
            <section class="border-y border-brand-border bg-brand-green-soft py-20 lg:py-24" aria-labelledby="how-it-works-heading">
                <div class="mx-auto max-w-6xl px-6 lg:px-8">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-wide text-brand-green-deep">Process</p>
                        <h2 id="how-it-works-heading" class="mt-2 text-3xl font-bold text-gray-900 sm:text-4xl">How it works</h2>
                        <p class="mt-3 text-lg leading-relaxed text-gray-600">Predictable, from the first message to any follow-up.</p>
                    </div>

                    <ol class="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                        <li class="border-t-2 border-brand-gold pt-6">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-green text-base font-bold text-white" aria-hidden="true">1</span>
                            <h3 class="mt-4 text-base font-semibold text-gray-900">Tell us what you need</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Submit your concern and symptom information.</p>
                        </li>
                        <li class="border-t-2 border-brand-gold pt-6">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-green text-base font-bold text-white" aria-hidden="true">2</span>
                            <h3 class="mt-4 text-base font-semibold text-gray-900">Get reviewed</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Infirmary staff review your request and determine the appropriate priority.</p>
                        </li>
                        <li class="border-t-2 border-brand-gold pt-6">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-green text-base font-bold text-white" aria-hidden="true">3</span>
                            <h3 class="mt-4 text-base font-semibold text-gray-900">Connect with medical staff</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Continue through the consultation workflow with the assigned physician.</p>
                        </li>
                        <li class="border-t-2 border-brand-gold pt-6">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-green text-base font-bold text-white" aria-hidden="true">4</span>
                            <h3 class="mt-4 text-base font-semibold text-gray-900">Receive follow-up care</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Continue communication whenever follow-up is required.</p>
                        </li>
                    </ol>
                </div>
            </section>

            {{-- ============================= UNIVERSITY IDENTITY ============================= --}}
            <section class="bg-brand-gold-soft">
                <div class="mx-auto flex max-w-6xl flex-col items-center gap-4 px-6 py-14 text-center lg:px-8">
                    <img src="{{ asset('images/clsu_logo.png') }}" alt="Central Luzon State University seal" class="h-16 w-16">
                    <p class="max-w-xl text-base leading-relaxed text-gray-700">
                        This platform is operated for the Central Luzon State University community by the
                        <span class="font-semibold text-gray-900">CLSU Infirmary</span> — the same staff
                        who provide on-campus medical care.
                    </p>
                </div>
            </section>

            {{-- ============================= ENTRY POINTS ============================= --}}
            <section class="bg-white py-20 lg:py-24">
                <div class="mx-auto max-w-6xl px-6 lg:px-8">
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="rounded-lg border border-brand-border p-8">
                            <h2 class="text-xl font-semibold text-gray-900">Already have an account?</h2>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Sign in to view your consultations and messages.</p>
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}"
                                   class="mt-5 inline-flex min-h-11 items-center rounded-lg border border-brand-border bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 transition-colors hover:bg-brand-green-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                    Sign in to your account
                                </a>
                            @endif
                        </div>
                        <div class="rounded-lg border border-brand-green/20 bg-brand-green-soft p-8">
                            <h2 class="text-xl font-semibold text-gray-900">New to the system?</h2>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">Create an account to request your first consultation.</p>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}"
                                   class="mt-5 inline-flex min-h-11 items-center rounded-lg bg-brand-green px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                    Create your account
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        </main>

        {{-- ============================= FOOTER ============================= --}}
        <footer class="border-t-2 border-brand-gold bg-brand-green-deep">
            <div class="mx-auto max-w-6xl px-6 py-12 lg:px-8">
                <div class="flex flex-col gap-8 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-3">
                        <img src="{{ asset('images/clsu_logo.png') }}" alt="Central Luzon State University seal" class="h-10 w-10 shrink-0 rounded-full ring-2 ring-white/20">
                        <div>
                            <p class="font-semibold text-white">CLSU Infirmary Telemedicine</p>
                            <p class="mt-1 max-w-sm text-sm text-brand-green-soft/80">Central Luzon State University, Science City of Muñoz, Nueva Ecija.</p>
                        </div>
                    </div>

                    <nav aria-label="Account" class="flex gap-6 text-sm font-medium">
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="text-brand-green-soft underline-offset-4 hover:text-white hover:underline">Sign In</a>
                        @endif
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="text-brand-green-soft underline-offset-4 hover:text-white hover:underline">Create Account</a>
                        @endif
                    </nav>
                </div>

                <div class="mt-8 border-t border-white/10 pt-6">
                    <p class="text-xs leading-relaxed text-brand-green-soft/70">
                        This platform is for non-emergency consultations. For a medical emergency, seek in-person care immediately.
                    </p>
                    <p class="mt-2 text-xs text-brand-green-soft/70">&copy; {{ now()->year }} CLSU Infirmary. All rights reserved.</p>
                </div>
            </div>
        </footer>
    </body>
</html>
