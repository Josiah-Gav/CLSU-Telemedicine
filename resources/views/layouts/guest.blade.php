<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Sweet Alert -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2">
            <!-- Left panel - branding -->
            <div class="hidden lg:flex flex-col justify-between p-16 bg-gradient-to-br from-green-50 via-green-100 to-white">
                <div class="space-y-6">
                    <a href="/" class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-full bg-white shadow flex items-center justify-center">
                            <x-application-logo class="w-10 h-10 fill-current text-green-700" />
                        </div>
                        <div class="text-lg font-semibold text-green-800">CLSU Campus<br>Telemedicine</div>
                    </a>

                    <h1 class="text-4xl font-extrabold text-emerald-800">Quality care, right on campus.</h1>

                    <p class="text-gray-700 max-w-md">Connect with licensed healthcare professionals anytime, anywhere.</p>

                    <div class="mt-8 space-y-6">
                        <div class="flex items-start gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">💬</div>
                            <div>
                                <div class="font-semibold text-emerald-800">Virtual Consultations</div>
                                <div class="text-sm text-gray-600">Talk to doctors via secure video calls.</div>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">🔒</div>
                            <div>
                                <div class="font-semibold text-emerald-800">Secure & Private</div>
                                <div class="text-sm text-gray-600">Your health information is always protected.</div>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">📅</div>
                            <div>
                                <div class="font-semibold text-emerald-800">Easy Appointments</div>
                                <div class="text-sm text-gray-600">Book, reschedule, and manage appointments with ease.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-sm text-gray-500">Secure Connection • CLSU Telemedicine</div>
            </div>

            <!-- Right panel - form -->
            <div class="flex items-center justify-center p-6 sm:p-8 bg-white">
                <div class="w-full max-w-md">
                    <!-- Mobile header: show logo + site name on small screens -->
                    <div class="lg:hidden flex items-center gap-4 mb-6">
                        <div class="w-12 h-12 rounded-full bg-white shadow flex items-center justify-center">
                            <x-application-logo class="w-8 h-8 fill-current text-green-700" />
                        </div>
                        <div class="text-lg font-semibold text-green-800">CLSU Campus Telemedicine</div>
                    </div>

                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
