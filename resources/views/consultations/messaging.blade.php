<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Consultation Messaging') }}
        </h2>
    </x-slot>

    @php
        $consultationRequest = $session->request;
        $patientName = trim((optional($consultationRequest->patient)->first_name ?? '') . ' ' . (optional($consultationRequest->patient)->last_name ?? '')) ?: 'Patient';
        $physicianName = trim((optional($session->physician)->first_name ?? '') . ' ' . (optional($session->physician)->last_name ?? '')) ?: 'Physician';
        $nurseName = trim((optional($consultationRequest->nurse)->first_name ?? '') . ' ' . (optional($consultationRequest->nurse)->last_name ?? '')) ?: 'Unassigned';
        $currentUser = auth()->user();
        $backUrl = $currentUser && $currentUser->role === 'physician'
            ? route('physician.active_consultation', ['physician' => $currentUser->user_id])
            : route('dashboard');
        $backLabel = $currentUser && $currentUser->role === 'physician'
            ? 'Back to Active Consultations'
            : 'Back to Dashboard';
        $isAssignedPhysician = $currentUser && $currentUser->role === 'physician' && (int) $session->physician_id === (int) $currentUser->user_id;
        $isCompletedSession = $session->consultation_status === 'completed';

        // "Who am I talking to?" is the first question this page has to answer, so
        // the header is built around the *other* participant rather than listing
        // both names. Derived from data already loaded by the controller.
        $viewerIsPhysician = $currentUser && $currentUser->role === 'physician';
        $peerName = $viewerIsPhysician ? $patientName : $physicianName;
        $peerRoleLabel = $viewerIsPhysician ? 'Patient' : 'Attending physician';
        $peerInitial = strtoupper(mb_substr(trim($peerName), 0, 1)) ?: '?';
    @endphp

    <div class="py-10" x-data="consultationMessaging()" x-init="init()" @keydown.escape.window="previewFile ? closeAttachmentPreview() : clearAttachmentSelection()">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4">
                <a href="{{ $backUrl }}" class="group inline-flex items-center gap-1.5 rounded-lg px-1 py-1 text-sm font-semibold text-slate-600 transition hover:text-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                    {{ __($backLabel) }}
                </a>
            </div>
            <div class="bg-white shadow-sm sm:rounded-xl border border-slate-200 overflow-hidden">
                {{-- Participant-first header: who, which session, what status, and the
                     video affordance all read in one glance before the conversation. --}}
                <div class="border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="relative h-12 w-12 flex-shrink-0">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-green text-lg font-semibold text-white" aria-hidden="true">
                                    {{ $peerInitial }}
                                </div>
                                {{-- Decorative: the same state is stated in words in the
                                     status line below, so it is not colour-only. --}}
                                <span
                                    class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white"
                                    :class="peerOnline ? 'bg-emerald-500' : 'bg-slate-400'"
                                    aria-hidden="true"
                                ></span>
                            </div>
                            <div class="min-w-0">
                                <h3 class="truncate text-lg font-semibold text-slate-900">{{ $peerName }}</h3>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    <span class="font-medium text-slate-600">{{ __($peerRoleLabel) }}</span>
                                    <span class="text-slate-300" aria-hidden="true">&middot;</span>
                                    <span>{{ __('Session') }} #{{ $session->id }}</span>
                                    <span class="text-slate-300" aria-hidden="true">&middot;</span>
                                    {{-- One polite status region for presence/typing rather than
                                         several competing live regions, and never colour alone:
                                         the dot is always paired with its text. --}}
                                    <span role="status" aria-live="polite" aria-atomic="true" class="inline-flex items-center gap-1.5">
                                        <span x-show="!peerIsTyping" class="inline-flex items-center gap-1.5">
                                            <span
                                                class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0"
                                                :class="peerOnline ? 'bg-emerald-500' : 'bg-slate-400'"
                                                aria-hidden="true"
                                            ></span>
                                            <span :class="peerOnline ? 'text-emerald-700 font-semibold' : 'text-slate-500'" x-text="peerOnline ? 'Online' : 'Offline'"></span>
                                        </span>
                                        <span class="text-brand-green-deep font-medium" x-show="peerIsTyping" x-text="presenceText"></span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 lg:flex-shrink-0 lg:justify-end">
                            <x-dash.badge :status="$session->consultation_status" />
                            @if($session->completed_at)
                                <span class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                                    </svg>
                                    {{ __('Completed') }} {{ $session->completed_at->format('M d, Y @ h:i A') }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 sm:px-6">
                    <div class="-mx-1 flex items-center gap-1 overflow-x-auto px-1 py-1">
                        <button
                            type="button"
                            @click="activeTab = 'messages'"
                            :aria-pressed="activeTab === 'messages'"
                            :class="activeTab === 'messages' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100'"
                            class="inline-flex flex-shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                            </svg>
                            Messages
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'details'"
                            :aria-pressed="activeTab === 'details'"
                            :class="activeTab === 'details' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100'"
                            class="inline-flex flex-shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                            Details
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'patient'"
                            :aria-pressed="activeTab === 'patient'"
                            :class="activeTab === 'patient' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100'"
                            class="inline-flex flex-shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21c-4.97-3.14-9-6.9-9-11.25A5.25 5.25 0 0112 5.5a5.25 5.25 0 019 4.25c0 4.35-4.03 8.11-9 11.25z" />
                            </svg>
                            Patient Details
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'assessment'; assessmentTabOpened = true"
                            :aria-pressed="activeTab === 'assessment'"
                            :class="activeTab === 'assessment' ? 'bg-brand-green text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100'"
                            class="inline-flex flex-shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6" />
                            </svg>
                            Assessment
                        </button>
                    </div>
                </div>

                {{-- Video status and its action live in one component so the state
                     ("live" vs "not started") and the thing you do about it are never
                     read separately. State is icon + text, never colour alone. --}}
                <div
                    x-show="!inVideoCall && consultationStatus === 'active' && (isAssignedPhysician || videoActive)"
                    x-cloak
                    class="border-b border-slate-200 px-4 py-3 sm:px-6"
                    :class="videoActive ? 'bg-emerald-50' : 'bg-white'"
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl"
                                :class="videoActive ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500'"
                                aria-hidden="true"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900">Video consultation</p>
                                <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs">
                                    <span x-show="videoActive" class="inline-flex items-center gap-1.5 font-semibold text-emerald-700">
                                        <span class="relative flex h-2 w-2" aria-hidden="true">
                                            <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
                                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-600"></span>
                                        </span>
                                        Live now
                                    </span>
                                    <span x-show="!videoActive" class="inline-flex items-center gap-1.5 text-slate-500">
                                        <span class="inline-block h-2 w-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                                        Not started
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-shrink-0 items-center gap-2">
                            <button
                                type="button"
                                x-show="isAssignedPhysician && !videoActive"
                                @click="startVideoCall"
                                :disabled="isStartingVideo"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-green px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 disabled:opacity-60 sm:w-auto"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                <span x-show="!isStartingVideo">Start Video Consultation</span>
                                <span x-show="isStartingVideo">Starting...</span>
                            </button>
                            <button
                                type="button"
                                x-show="videoActive"
                                @click="joinVideoCall"
                                :disabled="isJoiningVideo"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-60 sm:w-auto"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                <span x-show="!isJoiningVideo">Join Video Consultation</span>
                                <span x-show="isJoiningVideo">Joining...</span>
                            </button>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500" x-show="!videoActive && isAssignedPhysician">Starting will open the room and invite your patient to join.</p>
                </div>

                <div x-show="inVideoCall" x-cloak class="border-b border-slate-200 bg-slate-900 px-4 py-4 sm:px-6">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <p class="inline-flex items-center gap-2 text-sm font-semibold text-white">
                            <span class="relative flex h-2 w-2" aria-hidden="true">
                                <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                            </span>
                            Video consultation
                        </p>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="leaveVideoCall" class="rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900">
                                Leave call
                            </button>
                            <button
                                type="button"
                                x-show="isAssignedPhysician"
                                @click="endVideoCall"
                                :disabled="isEndingVideo"
                                class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 disabled:opacity-60"
                            >
                                <span x-show="!isEndingVideo">End call for everyone</span>
                                <span x-show="isEndingVideo">Ending...</span>
                            </button>
                        </div>
                    </div>
                    <div x-ref="videoContainer" class="h-[50vh] w-full overflow-hidden rounded-xl bg-black sm:h-[60vh]"></div>
                </div>

                <div x-show="activeTab === 'messages'" x-cloak>
                    {{-- The conversation canvas sits on a tint so white bubbles read as
                         surfaces. role="log" announces arriving messages politely without
                         stealing focus from the composer. --}}
                    <div
                        id="messagesContainer"
                        role="log"
                        aria-live="polite"
                        aria-relevant="additions"
                        aria-label="{{ __('Consultation conversation') }}"
                        class="relative h-[52vh] min-h-[18rem] overflow-y-auto bg-brand-muted px-3 py-4 sm:h-[55vh] sm:px-5"
                    >
                        <template x-if="messages.length === 0">
                            <div class="flex h-full flex-col items-center justify-center px-6 text-center">
                                <div class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-green-soft text-brand-green" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                    </svg>
                                </div>
                                <p class="mt-3 text-sm font-semibold text-slate-900">{{ __('Start the consultation') }}</p>
                                <p class="mt-1 max-w-sm text-sm text-slate-500">
                                    {{ __('No messages yet. Send the first message to :name below — everything sent here stays with this consultation record.', ['name' => $peerName]) }}
                                </p>
                            </div>
                        </template>

                        <template x-for="(msg, index) in messages" :key="msg.message_id">
                            <div>
                                {{-- Day dividers are derived from the timestamps the API already
                                     returns; nothing new is fetched or stored for them. --}}
                                <template x-if="showDayDivider(index)">
                                    <div class="flex items-center justify-center py-3">
                                        <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-[11px] font-semibold text-slate-500" x-text="messageDayLabel(msg.created_at)"></span>
                                    </div>
                                </template>

                                <div class="flex" :class="[isMine(msg) ? 'justify-end' : 'justify-start', showSenderHeading(index) ? 'mt-3' : 'mt-1']">
                                    <div class="min-w-0 max-w-[85%] sm:max-w-[75%]">
                                        {{-- Consecutive messages from the same sender are grouped:
                                             the name prints once per group, not once per bubble. --}}
                                        <template x-if="!isMine(msg) && showSenderHeading(index)">
                                            <p class="mb-1 truncate px-1 text-xs font-semibold text-slate-500" x-text="msg.sender_name || 'Unknown user'"></p>
                                        </template>

                                        <div class="rounded-2xl px-4 py-2.5 shadow-sm"
                                            :class="isMine(msg)
                                                ? 'bg-brand-green text-white ' + (showSenderHeading(index) ? 'rounded-br-md' : '')
                                                : 'bg-white border border-slate-200 text-slate-800 ' + (showSenderHeading(index) ? 'rounded-bl-md' : '')">
                                            <template x-if="msg.message">
                                                <p class="whitespace-pre-wrap break-words text-sm leading-relaxed" x-text="msg.message"></p>
                                            </template>

                                            <template x-if="msg.attachments && msg.attachments.length">
                                                <div class="space-y-1.5" :class="msg.message ? 'mt-2.5' : ''">
                                                    <template x-for="file in msg.attachments" :key="file.attachment_id">
                                                        <div>
                                                            {{-- An image attachment previews inline, like a chat image
                                                                 bubble, and opens the same click-to-zoom popup used for
                                                                 the prescription preview. A video opens the same popup
                                                                 rather than navigating the tab away to play/download it —
                                                                 the popup itself offers "open in new tab" and "download".
                                                                 Any other attachment has no sensible inline preview, so
                                                                 it stays a download row. --}}
                                                            <template x-if="attachmentIsImage(file)">
                                                                <button
                                                                    type="button"
                                                                    @click="openAttachmentPreview(file.download_url, file.file_name)"
                                                                    class="block max-w-full overflow-hidden rounded-xl ring-1 transition hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                                    :class="isMine(msg) ? 'ring-white/30 focus-visible:ring-white' : 'ring-slate-200 focus-visible:ring-brand-green'"
                                                                >
                                                                    <span class="sr-only" x-text="file.file_name"></span>
                                                                    <img :src="file.download_url" :alt="file.file_name" class="max-h-96 w-full object-cover" loading="lazy">
                                                                </button>
                                                            </template>
                                                            <template x-if="attachmentIsVideo(file)">
                                                                <button
                                                                    type="button"
                                                                    @click="openAttachmentPreview(file.download_url, file.file_name, true)"
                                                                    class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                                    :class="isMine(msg) ? 'bg-brand-green-deep text-green-50 hover:bg-brand-green focus-visible:ring-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-brand-green'"
                                                                >
                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                                                    </svg>
                                                                    <span class="min-w-0 flex-1 truncate" x-text="file.file_name"></span>
                                                                    <span class="flex-shrink-0 whitespace-nowrap opacity-80" x-text="formatFileSize(file.file_size)"></span>
                                                                </button>
                                                            </template>
                                                            <template x-if="!attachmentIsImage(file) && !attachmentIsVideo(file)">
                                                                <a :href="file.download_url"
                                                                    class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                                    :class="isMine(msg) ? 'bg-brand-green-deep text-green-50 hover:bg-brand-green focus-visible:ring-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-brand-green'">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                                    </svg>
                                                                    <span class="min-w-0 flex-1 truncate" x-text="file.file_name"></span>
                                                                    <span class="flex-shrink-0 whitespace-nowrap opacity-80" x-text="formatFileSize(file.file_size)"></span>
                                                                </a>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>

                                            <div class="mt-1.5 flex items-center gap-1 text-[11px]" :class="isMine(msg) ? 'text-green-100 justify-end' : 'text-slate-400 justify-start'">
                                                <span :title="formatTime(msg.created_at)" x-text="shortTime(msg.created_at)"></span>

                                                <template x-if="isMine(msg)">
                                                    <span class="inline-flex items-center">
                                                        <span class="sr-only" x-text="msg.read_at ? 'Seen' : 'Sent'"></span>
                                                        <template x-if="!msg.read_at">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        </template>
                                                        <template x-if="msg.read_at">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-emerald-200" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13l4 4L17 7" />
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 13l4 4L21 7" />
                                                            </svg>
                                                        </template>
                                                    </span>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="border-t border-slate-200 bg-white px-3 py-3 sm:px-5 sm:py-4">
                        @if($isCompletedSession)
                            <div class="flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>This consultation has been completed. Messaging is now read-only.</span>
                            </div>
                        @else
                        <form @submit.prevent="sendMessage" class="space-y-2">
                            <!-- <p class="px-1 text-[11px] text-slate-400">Images &amp; documents up to 10 MB &middot; 1 video up to 50 MB &middot; up to 3 files per message</p> -->

                            {{-- Selected files sit above the bar so the composer itself keeps a
                                 stable height as files are added or cleared. --}}
                            <template x-if="selectedFiles.length">
                                <div class="flex flex-wrap items-center gap-2">
                                    <template x-for="(item, idx) in selectedFiles" :key="idx">
                                        <span class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-brand-green-soft pl-1.5 pr-1.5 py-1 text-xs font-medium text-brand-green-deep">
                                            <template x-if="item.previewUrl">
                                                <button
                                                    type="button"
                                                    @click="openAttachmentPreview(item.previewUrl, item.file.name)"
                                                    class="h-5 w-5 flex-shrink-0 overflow-hidden rounded-full ring-1 ring-brand-green/30 transition hover:ring-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
                                                >
                                                    <span class="sr-only">{{ __('Preview selected file') }}</span>
                                                    <img :src="item.previewUrl" alt="" class="h-full w-full object-cover">
                                                </button>
                                            </template>
                                            <svg x-show="!item.previewUrl" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                            </svg>
                                            <span class="truncate pl-0.5" x-text="item.file.name"></span>
                                            <button
                                                type="button"
                                                @click="removeSelectedFile(idx)"
                                                class="inline-flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full text-brand-green-deep/70 transition hover:bg-white/60 hover:text-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
                                            >
                                                <span class="sr-only">{{ __('Remove selected file') }}</span>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>
                                    <button type="button" @click="clearAttachmentSelection" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 transition hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">Clear files</button>
                                </div>
                            </template>

                            <div class="flex items-end gap-2 rounded-2xl border border-slate-300 bg-white p-1.5 transition focus-within:border-brand-green focus-within:ring-2 focus-within:ring-green-100">
                                {{-- The file input keeps its ref/handler and is simply visually
                                     hidden inside its own label, so clicking or keyboard-
                                     activating the label opens the picker with no extra JS. --}}
                                <label class="inline-flex h-10 w-10 flex-shrink-0 cursor-pointer items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 focus-within:ring-2 focus-within:ring-brand-green">
                                    <input type="file" x-ref="attachments" @change="handleAttachments" multiple class="sr-only" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.mp4" />
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                    </svg>
                                    <span class="sr-only">{{ __('Attach files') }}</span>
                                </label>

                                <label for="messageDraft" class="sr-only">{{ __('Message') }}</label>
                                <textarea
                                    id="messageDraft"
                                    x-model="draft"
                                    @input="handleDraftInput"
                                    @blur="handleDraftBlur"
                                    rows="2"
                                    maxlength="2000"
                                    class="max-h-40 min-h-[2.5rem] w-full flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-0 focus:outline-none focus:ring-0"
                                    placeholder="Type your message..."></textarea>

                                <button
                                    type="submit"
                                    :disabled="isSending"
                                    class="inline-flex h-10 flex-shrink-0 items-center justify-center gap-1.5 rounded-xl bg-brand-green px-3 text-sm font-semibold text-white transition hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 disabled:opacity-60 sm:px-4">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                    </svg>
                                    <span class="hidden sm:inline" x-show="!isSending">Send</span>
                                    <span class="hidden sm:inline" x-show="isSending">Sending...</span>
                                    <span class="sr-only sm:hidden" x-text="isSending ? 'Sending...' : 'Send'"></span>
                                </button>
                            </div>
                        </form>
                        @endif
                    </div>
                </div>

                <div x-show="activeTab === 'details'" x-cloak class="bg-brand-muted px-3 py-4 sm:px-6 sm:py-5">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Consultation Summary</h4>
                            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Patient</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $patientName }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Physician</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $physicianName }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Assigned Nurse</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $nurseName }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Status</dt>
                                    <dd class="mt-1"><x-dash.badge :status="$consultationRequest->request_status" size="sm" /></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Concern Category</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ ucfirst($consultationRequest->concern_category) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Submitted</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ optional($consultationRequest->submitted_at)->format('M d, Y @ h:i A') }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Reason for Online Consultation</h4>
                            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-700">{{ $consultationRequest->online_reason ?? 'No reason provided.' }}</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                        <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Symptoms</h4>
                        <div class="mt-3 text-sm text-slate-700">
                            @if(is_array($consultationRequest->symptoms_desc) && count($consultationRequest->symptoms_desc) > 0)
                                <ul class="grid gap-3 sm:grid-cols-2">
                                    @foreach($consultationRequest->symptoms_desc as $symptom)
                                        <li class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-900">{{ $symptom['name'] ?? $symptom }}</p>
                                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                                @if(!empty($symptom['date']) || !empty($symptom['time']))
                                                    <span>Started: {{ $symptom['date'] ?? 'Unknown' }} {{ $symptom['time'] ?? '' }}</span>
                                                @endif
                                                @if(!empty($symptom['severity']))
                                                    <span class="inline-flex items-center whitespace-nowrap rounded-full bg-white px-2 py-0.5 font-semibold text-slate-600 ring-1 ring-slate-200">Severity: {{ $symptom['severity'] }}</span>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-sm text-slate-500">No symptoms were recorded for this consultation.</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                        <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Attachments</h4>
                        @if(!empty($consultationRequest->file_attachments))
                            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach($consultationRequest->file_attachments as $attachment)
                                    {{-- Served through the authorizing route, never as the stored
                                         reference: this page is shown to the patient and the
                                         assigned physician, and both reach intake attachments
                                         only via AttachmentController::show. --}}
                                    <a href="{{ route('consultation.attachment', ['consultation' => $consultationRequest->request_id, 'file' => basename($attachment)]) }}" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-900 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0 text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                        </svg>
                                        View attachment
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-3 text-sm text-slate-500">No attachments were uploaded for this consultation.</p>
                        @endif
                    </div>
                </div>

                <div x-show="activeTab === 'patient'" x-cloak class="bg-brand-muted px-3 py-4 sm:px-6 sm:py-5">
                    {{-- Placeholder only: no Hospital Information System integration exists
                         yet. Every value below is a static "No Data" stand-in so the layout
                         is ready for when that feed is wired up — nothing here is fetched,
                         stored, or backed by a route. --}}
                    <div class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                        <span><span class="font-semibold">Not connected yet.</span> This tab is reserved for records pulled from the Hospital Information System (HIS) integration. Fields will populate automatically once that connection is built &mdash; for now they show as placeholders.</span>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Vitals &amp; Identifiers</h4>
                            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Blood Type</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-400">No Data</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Height</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-400">No Data</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Weight</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-400">No Data</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">BMI</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-400">No Data</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Emergency Contact</h4>
                            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Name</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-400">No Data</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium text-slate-500">Relationship</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-400">No Data</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium text-slate-500">Contact Number</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-400">No Data</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Known Allergies</h4>
                            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-400">No Data</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Chronic Illnesses / Conditions</h4>
                            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-400">No Data</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Current Medications</h4>
                            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-400">No Data</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Past Injuries / Surgeries</h4>
                            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-400">No Data</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Immunization History</h4>
                            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-400">No Data</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Family Medical History</h4>
                            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-400">No Data</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">CHIS Sync Status</h4>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">
                                <span class="inline-block h-2 w-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                                Not connected
                            </span>
                        </div>
                        <p class="mt-2 text-sm text-slate-400">Last synced: No Data</p>
                    </div>
                </div>

                <div x-show="activeTab === 'assessment'" x-cloak class="bg-brand-muted px-3 py-4 sm:px-6 sm:py-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <h4 class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Clinical Documentation</h4>
                                <p class="mt-1 text-base font-semibold text-slate-900 sm:text-lg">Assessment, plan, recommendations, diagnosis, and prescription</p>
                            </div>
                            <div class="flex flex-col items-start gap-2 sm:flex-shrink-0 sm:items-end">
                                <div role="status" aria-live="polite">
                                    <template x-if="saveMessage">
                                        <p class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-sm font-medium text-emerald-700">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span x-text="saveMessage"></span>
                                        </p>
                                    </template>
                                </div>
                                @if($isCompletedSession)
                                    <x-dash.badge status="completed" size="sm" />
                                @endif
                            </div>
                        </div>

                        @if($isAssignedPhysician && !$isCompletedSession)
                            <form @submit.prevent="saveClinicalDetails" class="mt-6 space-y-5">
                                <div class="grid gap-5 lg:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-800">Diagnosis</label>
                                        <input
                                            type="text"
                                            x-model="clinical.diagnosis"
                                            maxlength="255"
                                            class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100"
                                            placeholder="Enter diagnosis"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-800">Prescription Upload</label>
                                        <input type="file" x-ref="prescription" @change="handlePrescription" class="mt-2 block w-full text-sm text-slate-600" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" />
                                        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                            <template x-if="selectedPrescriptionName">
                                                <span class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 pl-1.5 pr-1.5 py-1 font-medium text-slate-700">
                                                    <template x-if="selectedPrescriptionPreviewUrl">
                                                        <button
                                                            type="button"
                                                            @click="openAttachmentPreview(selectedPrescriptionPreviewUrl, selectedPrescriptionName)"
                                                            class="h-5 w-5 flex-shrink-0 overflow-hidden rounded-full ring-1 ring-slate-300 transition hover:ring-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
                                                        >
                                                            <span class="sr-only">{{ __('Preview selected prescription file') }}</span>
                                                            <img :src="selectedPrescriptionPreviewUrl" alt="" class="h-full w-full object-cover">
                                                        </button>
                                                    </template>
                                                    <span class="truncate pl-1" x-text="selectedPrescriptionName"></span>
                                                    <button
                                                        type="button"
                                                        @click="clearSelectedPrescription"
                                                        class="inline-flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-200 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
                                                    >
                                                        <span class="sr-only">{{ __('Cancel selected prescription file') }}</span>
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </span>
                                            </template>
                                            <template x-if="clinical.prescription.download_url && !selectedPrescriptionName">
                                                <span class="inline-flex items-center gap-2">
                                                    {{-- x-show only hides this tab, it does not stop the
                                                         browser fetching an <img> inside it, so the
                                                         thumbnail is not rendered at all until the tab
                                                         has been opened at least once. --}}
                                                    <template x-if="assessmentTabOpened && isImageFilename(clinical.prescription.file_name)">
                                                        <button
                                                            type="button"
                                                            @click="openAttachmentPreview(clinical.prescription.download_url, clinical.prescription.file_name)"
                                                            class="h-6 w-6 flex-shrink-0 overflow-hidden rounded-md ring-1 ring-slate-300 transition hover:ring-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
                                                        >
                                                            <span class="sr-only">{{ __('Preview current prescription file') }}</span>
                                                            <img :src="clinical.prescription.download_url" alt="" class="h-full w-full object-cover">
                                                        </button>
                                                    </template>
                                                    <a :href="clinical.prescription.download_url" class="font-semibold text-brand-green hover:text-brand-green-deep">Download current prescription</a>
                                                </span>
                                            </template>
                                            <template x-if="clinical.prescription.file_name && !selectedPrescriptionName">
                                                <button type="button" @click="removePrescription" class="font-semibold text-rose-600 hover:text-rose-700">Remove current prescription</button>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-800">Assessment</label>
                                    <textarea
                                        x-model="clinical.assessment"
                                        rows="4"
                                        maxlength="10000"
                                        class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100"
                                        placeholder="Document the assessment"></textarea>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-800">Plan</label>
                                    <textarea
                                        x-model="clinical.plan"
                                        rows="4"
                                        maxlength="10000"
                                        class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100"
                                        placeholder="Document the treatment plan"></textarea>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-800">Recommendations</label>
                                    <textarea
                                        x-model="clinical.recommendations"
                                        rows="4"
                                        maxlength="10000"
                                        class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-brand-green focus:ring-green-100"
                                        placeholder="Provide physician recommendations"></textarea>
                                </div>

                                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-end">
                                    <button
                                        type="button"
                                        @click="completeConsultation"
                                        :disabled="isCompletingConsultation"
                                        class="inline-flex items-center justify-center rounded-lg border border-emerald-600 px-4 py-2.5 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-60">
                                        <span x-show="!isCompletingConsultation">Complete consultation</span>
                                        <span x-show="isCompletingConsultation">Completing...</span>
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="isSavingClinical"
                                        class="inline-flex items-center justify-center rounded-lg bg-brand-green px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 disabled:opacity-60">
                                        <span x-show="!isSavingClinical">Save clinical details</span>
                                        <span x-show="isSavingClinical">Saving...</span>
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Diagnosis</p>
                                    <p class="mt-2 text-sm text-slate-700" x-text="clinical.diagnosis || 'No diagnosis added yet.'"></p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Prescription</p>
                                    <template x-if="clinical.prescription.download_url">
                                        <div class="mt-2 space-y-2">
                                            <p class="break-words text-sm font-medium text-slate-800" x-text="clinical.prescription.file_name"></p>
                                            <a :href="clinical.prescription.download_url" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-green px-3 py-2 text-sm font-semibold text-white transition hover:bg-brand-green-deep focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                </svg>
                                                Download prescription
                                            </a>
                                        </div>
                                    </template>
                                    <template x-if="!clinical.prescription.download_url">
                                        <p class="mt-2 text-sm text-slate-700">No prescription uploaded yet.</p>
                                    </template>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Assessment</p>
                                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700" x-text="clinical.assessment || 'No assessment recorded yet.'"></p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Plan</p>
                                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700" x-text="clinical.plan || 'No plan recorded yet.'"></p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 lg:col-span-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Recommendations</p>
                                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700" x-text="clinical.recommendations || 'No recommendations recorded yet.'"></p>
                                </div>
                            </div>

                            @if($isCompletedSession)
                                <div class="mt-4 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                    <span>This clinical documentation is locked because the consultation has been completed.</span>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Same click-to-zoom popup used for attachment thumbnails on the
             consultation inbox / active consultations pages, reused here for
             the prescription file preview. --}}
        <div
            x-show="previewFile"
            x-cloak
            @click.self="closeAttachmentPreview()"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/80 p-3 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Prescription file preview') }}"
        >
            <div
                x-effect="if (previewFile) { $nextTick(() => $refs.previewCloseButton?.focus()); }"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
            >
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                    <p class="truncate text-sm font-semibold text-gray-900" x-text="previewFileName"></p>
                    <button
                        type="button"
                        x-ref="previewCloseButton"
                        @click="closeAttachmentPreview()"
                        class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2"
                    >
                        <span class="sr-only">{{ __('Close') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="flex-1 overflow-auto bg-gray-100 p-2 sm:p-4">
                    <template x-if="!previewIsVideo">
                        <img :src="previewFile" :alt="previewFileName" class="mx-auto max-h-[70vh] w-auto max-w-full rounded-lg object-contain">
                    </template>
                    <template x-if="previewIsVideo">
                        <video :src="previewFile" controls class="mx-auto max-h-[70vh] w-full rounded-lg bg-black"></video>
                    </template>
                </div>
                <div class="flex items-center justify-end gap-4 border-t border-gray-200 px-4 py-3">
                    <a :href="previewFile" download class="text-sm font-semibold text-brand-green hover:underline">
                        {{ __('Download') }}
                    </a>
                    <!-- <a :href="previewFile" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-brand-green hover:underline">
                        {{ __('Open in new tab') }}
                    </a> -->
                </div>
            </div>
        </div>
    </div>

    <script>
        function consultationMessaging() {
            return {
                activeTab: 'messages',
                messages: [],
                draft: '',
                selectedFiles: [],
                selectedPrescriptionFile: null,
                selectedPrescriptionName: '',
                // Object URL for the pending file's thumbnail/popup preview —
                // only set when the selected file is an image. Revoked whenever
                // the selection changes so it never leaks memory.
                selectedPrescriptionPreviewUrl: null,
                removePrescriptionOnSave: false,
                // Shared click-to-zoom popup state for the prescription preview.
                previewFile: null,
                previewFileName: '',
                previewIsVideo: false,
                isSending: false,
                isSavingClinical: false,
                isCompletingConsultation: false,
                // Guards so a poll cannot start while the previous one is still
                // in flight. Without them a slow response lets 3s/4s timers stack
                // requests on top of each other until the server catches up.
                isFetchingMessages: false,
                isFetchingPresence: false,
                // Latched the first time the assessment tab is opened, so the
                // prescription thumbnail is not requested by browsers that never
                // open that tab, and is not re-requested on every tab switch once
                // it has been.
                assessmentTabOpened: false,
                poller: null,
                presencePoller: null,
                typingTimeout: null,
                isTyping: false,
                presenceText: 'Checking participant status...',
                peerOnline: false,
                peerIsTyping: false,
                peerName: '',
                offlineUrl: '{{ route('consultations.messaging.offline', $session) }}',
                saveMessage: '',
                // Video consultation. videoActive reflects the presence poll only — it is
                // never trusted as authorization. The Join click is what actually asks the
                // server (POST /video/join), and that response is the only place a JWT or
                // room identifier is ever obtained.
                videoActive: false,
                // Only affects which buttons render. The server re-checks the assigned
                // physician on every start/end request, so this is never load-bearing.
                isAssignedPhysician: @js($isAssignedPhysician),
                videoStartUrl: '{{ route('consultations.video.start', $session) }}',
                videoJoinUrl: '{{ route('consultations.video.join', $session) }}',
                videoEndUrl: '{{ route('consultations.video.end', $session) }}',
                isStartingVideo: false,
                isJoiningVideo: false,
                isEndingVideo: false,
                inVideoCall: false,
                jitsiApi: null,
                consultationStatus: @js($session->consultation_status),
                consultationCompletedAt: @js(optional($session->completed_at)?->toIso8601String()),
                clinical: {
                    assessment: @js($session->assessment),
                    plan: @js($session->plan),
                    recommendations: @js($session->recommendations),
                    diagnosis: @js($session->diagnosis),
                    prescription: {
                        file_name: @js($session->prescription_file_name),
                        file_size: @js($session->prescription_file_size),
                        download_url: @js($session->prescription_file_path ? route('consultations.messaging.prescription.download', $session) : null),
                    }
                },
                currentUserId: {{ (int) auth()->user()->user_id }},
                sessionId: {{ (int) $session->id }},
                fetchUrl: '{{ route('consultations.messaging.index', $session) }}',
                postUrl: '{{ route('consultations.messaging.store', $session) }}',
                readUrl: '{{ route('consultations.messaging.read', $session) }}',
                clinicalUpdateUrl: '{{ route('consultations.messaging.clinical_details.update', $session) }}',
                completeUrl: '{{ route('consultations.messaging.complete', $session) }}',
                typingUrl: '{{ route('consultations.messaging.typing', $session) }}',
                presenceUrl: '{{ route('consultations.messaging.presence', $session) }}',
                init() {
                    this.fetchMessages(true);
                    this.fetchPresence();
                    this.poller = setInterval(() => this.fetchMessages(false), 3000);
                    this.presencePoller = setInterval(() => this.fetchPresence(), 4000);
                    window.addEventListener('beforeunload', () => {
                        if (this.poller) {
                            clearInterval(this.poller);
                        }

                        if (this.presencePoller) {
                            clearInterval(this.presencePoller);
                        }

                        this.sendTypingState(false);
                        this.markOffline();
                    });
                },
                markOffline() {
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.offlineUrl,
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                },
                isMine(msg) {
                    return Number(msg.sender_id) === Number(this.currentUserId);
                },
                formatTime(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    return date.toLocaleString();
                },
                formatFileSize(size) {
                    if (!size) return '(0 B)';
                    if (size < 1024) return `(${size} B)`;
                    if (size < 1024 * 1024) return `(${(size / 1024).toFixed(1)} KB)`;
                    return `(${(size / (1024 * 1024)).toFixed(1)} MB)`;
                },
                scrollToBottom() {
                    const container = $('#messagesContainer').get(0);
                    if (!container) return;
                    container.scrollTop = container.scrollHeight;
                },
                fetchMessages(scroll) {
                    if (this.consultationStatus !== 'active' && this.consultationStatus !== 'completed') {
                        return;
                    }

                    // A request is already on its way; whatever it returns will be
                    // at least as fresh as what this call would ask for, so skip
                    // rather than queue a second one behind it.
                    if (this.isFetchingMessages) {
                        return;
                    }

                    this.isFetchingMessages = true;

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.fetchUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const previousCount = this.messages.length;
                            this.messages = data.messages || [];

                            if (scroll || this.messages.length !== previousCount) {
                                this.$nextTick(() => this.scrollToBottom());
                            }

                            $.ajax({
                                url: this.readUrl,
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            });
                        },
                        error: (xhr) => {
                            console.error('Failed to fetch messages:', xhr);
                        },
                        // complete runs on success and failure alike, so the guard
                        // can never be left stuck on after a dropped request.
                        complete: () => {
                            this.isFetchingMessages = false;
                        }
                    });
                },
                handleAttachments(event) {
                    const files = Array.from(event.target.files || []);
                    const error = this.validateAttachmentSelection(files);

                    if (error) {
                        Swal.fire('Cannot attach files', error, 'warning');
                        if (this.$refs.attachments) {
                            this.$refs.attachments.value = '';
                        }
                        return;
                    }

                    this.revokeSelectedFilePreviews();
                    this.selectedFiles = files.map((file) => ({
                        file,
                        previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
                    }));
                },
                // Mirrors the server-side rules in ConsultationMessageController::store()
                // so an obviously invalid selection is caught before upload. The server
                // re-checks everything itself and remains the authority.
                validateAttachmentSelection(files) {
                    const maxFiles = 3;
                    const maxFileMb = 10;
                    const maxVideoMb = 50;
                    const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'mp4'];

                    if (files.length > maxFiles) {
                        return `You can attach up to ${maxFiles} files per message.`;
                    }

                    let videoCount = 0;

                    for (const file of files) {
                        const extension = (file.name.split('.').pop() || '').toLowerCase();

                        if (!allowedExtensions.includes(extension)) {
                            return 'This file type is not supported.';
                        }

                        const isVideo = extension === 'mp4';
                        if (isVideo) {
                            videoCount++;
                        }

                        const maxBytes = (isVideo ? maxVideoMb : maxFileMb) * 1024 * 1024;
                        if (file.size > maxBytes) {
                            return isVideo
                                ? 'This video is too large. Videos must be 50 MB or smaller.'
                                : 'This file is too large. Images and documents must be 10 MB or smaller.';
                        }
                    }

                    if (videoCount > 1) {
                        return 'You can attach only 1 video per message.';
                    }

                    return null;
                },
                // Removes one pending file without discarding the rest of the
                // selection. sendMessage() reads only this array, never the raw
                // <input>'s FileList, so no DataTransfer rebuild is needed.
                removeSelectedFile(index) {
                    const [removed] = this.selectedFiles.splice(index, 1);
                    if (removed?.previewUrl) {
                        URL.revokeObjectURL(removed.previewUrl);
                    }
                },
                revokeSelectedFilePreviews() {
                    this.selectedFiles.forEach((item) => {
                        if (item.previewUrl) {
                            URL.revokeObjectURL(item.previewUrl);
                        }
                    });
                },
                clearAttachmentSelection() {
                    this.revokeSelectedFilePreviews();
                    this.selectedFiles = [];
                    if (this.$refs.attachments) {
                        this.$refs.attachments.value = '';
                    }
                },
                handlePrescription(event) {
                    this.revokeSelectedPrescriptionPreview();
                    this.selectedPrescriptionFile = event.target.files?.[0] || null;
                    this.selectedPrescriptionName = this.selectedPrescriptionFile ? this.selectedPrescriptionFile.name : '';
                    this.selectedPrescriptionPreviewUrl = (this.selectedPrescriptionFile && this.selectedPrescriptionFile.type.startsWith('image/'))
                        ? URL.createObjectURL(this.selectedPrescriptionFile)
                        : null;
                    this.removePrescriptionOnSave = false;
                },
                // Cancels the *pending* file selection only — unlike removePrescription(),
                // it never flags the already-saved prescription for removal on save.
                clearSelectedPrescription() {
                    this.revokeSelectedPrescriptionPreview();
                    this.selectedPrescriptionFile = null;
                    this.selectedPrescriptionName = '';
                    if (this.$refs.prescription) {
                        this.$refs.prescription.value = '';
                    }
                },
                revokeSelectedPrescriptionPreview() {
                    if (this.selectedPrescriptionPreviewUrl) {
                        URL.revokeObjectURL(this.selectedPrescriptionPreviewUrl);
                    }
                    this.selectedPrescriptionPreviewUrl = null;
                },
                isImageFilename(name) {
                    return /\.(jpe?g|png|gif|webp)$/i.test(name || '');
                },
                openAttachmentPreview(url, name, isVideo = false) {
                    if (!url) return;
                    this.previewFile = url;
                    this.previewFileName = name || '';
                    this.previewIsVideo = isVideo;
                },
                closeAttachmentPreview() {
                    this.previewFile = null;
                    this.previewFileName = '';
                    this.previewIsVideo = false;
                },
                removePrescription() {
                    this.revokeSelectedPrescriptionPreview();
                    this.selectedPrescriptionFile = null;
                    this.selectedPrescriptionName = '';
                    this.removePrescriptionOnSave = true;
                    this.clinical.prescription = {
                        file_name: null,
                        file_size: null,
                        download_url: null,
                    };

                    if (this.$refs.prescription) {
                        this.$refs.prescription.value = '';
                    }
                },
                formatLastSeen(iso) {
                    if (!iso) return 'Last seen unavailable';
                    const date = new Date(iso);
                    if (Number.isNaN(date.getTime())) return 'Last seen unavailable';
                    return 'Last seen ' + date.toLocaleString();
                },
                fetchPresence() {
                    if (this.consultationStatus !== 'active') {
                        return;
                    }

                    if (this.isFetchingPresence) {
                        return;
                    }

                    this.isFetchingPresence = true;

                    $.ajax({
                        url: this.presenceUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const peer = data.peer || {};

                            this.peerName = peer.name || 'Participant';
                            this.peerOnline = Boolean(peer.is_online);
                            this.peerIsTyping = Boolean(peer.is_typing);

                            // Only the boolean is ever read here. video.jwt, video.room_name,
                            // and video.domain do not exist on this endpoint's response, and
                            // even if they did, presence must never be treated as a source of
                            // join credentials — only POST /video/join is.
                            this.videoActive = Boolean((data.video || {}).active);

                            if (!this.videoActive && this.inVideoCall) {
                                // The physician ended the call while we were in it: tear down
                                // the local iframe rather than leaving a dead call on screen.
                                this.leaveVideoCall();
                            }

                            if (peer.is_typing) {
                                this.presenceText = this.peerName + ' is typing...';
                                return;
                            }

                            this.presenceText = this.formatLastSeen(peer.last_seen_at);
                        },
                        complete: () => {
                            this.isFetchingPresence = false;
                        }
                    });
                },
                startVideoCall() {
                    if (this.isStartingVideo || this.inVideoCall) return;

                    this.isStartingVideo = true;
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.videoStartUrl,
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            if (!data.success) {
                                Swal.fire('Unable to start', data.message || 'Unable to start the video consultation.', 'error');
                                return;
                            }

                            // /video/start returns the same payload shape as /video/join,
                            // so the physician goes straight into the room they just opened.
                            this.videoActive = true;
                            this.startJitsiCall(data);
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to start the video consultation.';
                            Swal.fire('Unable to start', message, 'error');
                        },
                        complete: () => {
                            this.isStartingVideo = false;
                        }
                    });
                },
                endVideoCall() {
                    if (this.isEndingVideo) return;

                    this.isEndingVideo = true;
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.videoEndUrl,
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: () => {
                            // Closes the room server-side; the patient's next presence poll
                            // sees video.active false and tears their iframe down too.
                            this.videoActive = false;
                            this.leaveVideoCall();
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to end the video consultation.';
                            Swal.fire('Unable to end', message, 'error');
                        },
                        complete: () => {
                            this.isEndingVideo = false;
                        }
                    });
                },
                joinVideoCall() {
                    if (this.isJoiningVideo || this.inVideoCall) return;

                    this.isJoiningVideo = true;
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.videoJoinUrl,
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            if (!data.success) {
                                Swal.fire('Unable to join', data.message || 'Unable to join the video consultation.', 'error');
                                return;
                            }

                            // The join response is the ONLY place domain/room_name/jwt come
                            // from. They are never read from presence and never hard-coded.
                            this.startJitsiCall(data);
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to join the video consultation.';
                            Swal.fire('Unable to join', message, 'error');

                            if (xhr.status === 409) {
                                // The physician ended the room between the last presence poll
                                // and this click; reflect that immediately.
                                this.videoActive = false;
                            }
                        },
                        complete: () => {
                            this.isJoiningVideo = false;
                        }
                    });
                },
                loadJitsiExternalApi(domain, tenant) {
                    if (window.JitsiMeetExternalAPI) {
                        return Promise.resolve();
                    }

                    if (!this._jitsiScriptPromise) {
                        this._jitsiScriptPromise = new Promise((resolve, reject) => {
                            const script = document.createElement('script');
                            // JaaS serves the library under the tenant path
                            // (/{appId}/external_api.js); the bare-domain URL 404s.
                            // Both segments come from the authorized join response.
                            script.src = `https://${domain}/${tenant}/external_api.js`;
                            script.async = true;
                            script.onload = () => resolve();
                            script.onerror = () => reject(new Error('Unable to load the video call client.'));
                            document.head.appendChild(script);
                        });
                    }

                    return this._jitsiScriptPromise;
                },
                startJitsiCall(joinData) {
                    // room_name is the "{appId}/{room}" iframe form, so the tenant the
                    // script URL needs is already in the authorized response — no extra
                    // field and no hard-coded app id required.
                    const tenant = String(joinData.room_name || '').split('/')[0];

                    this.loadJitsiExternalApi(joinData.domain, tenant).then(() => {
                        this.$nextTick(() => {
                            const container = this.$refs.videoContainer;
                            if (!container) return;

                            this.jitsiApi = new window.JitsiMeetExternalAPI(joinData.domain, {
                                roomName: joinData.room_name,
                                jwt: joinData.jwt,
                                parentNode: container,
                                width: '100%',
                                height: '100%',
                                configOverwrite: {
                                    prejoinPageEnabled: false
                                },
                                userInfo: {
                                    displayName: joinData.display_name
                                }
                            });

                            this.inVideoCall = true;

                            this.jitsiApi.addListener('readyToClose', () => {
                                this.leaveVideoCall();
                            });
                        });
                    }).catch((error) => {
                        Swal.fire('Unable to join', error.message || 'Unable to load the video call client.', 'error');
                    });
                },
                leaveVideoCall() {
                    if (this.jitsiApi) {
                        this.jitsiApi.dispose();
                        this.jitsiApi = null;
                    }

                    this.inVideoCall = false;
                },
                sendTypingState(isTyping) {
                    if (this.consultationStatus !== 'active') {
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    this.isTyping = isTyping;

                    $.ajax({
                        url: this.typingUrl,
                        method: 'POST',
                        data: {
                            is_typing: isTyping ? 1 : 0
                        },
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                },
                handleDraftInput() {
                    if (this.consultationStatus !== 'active') {
                        return;
                    }

                    if (!this.isTyping) {
                        this.sendTypingState(true);
                    }

                    if (this.typingTimeout) {
                        clearTimeout(this.typingTimeout);
                    }

                    this.typingTimeout = setTimeout(() => {
                        this.sendTypingState(false);
                    }, 2500);
                },
                handleDraftBlur() {
                    if (this.typingTimeout) {
                        clearTimeout(this.typingTimeout);
                        this.typingTimeout = null;
                    }

                    this.sendTypingState(false);
                },
                saveClinicalDetails() {
                    if (this.isSavingClinical) return;
                    if (this.consultationStatus !== 'active') return;

                    this.isSavingClinical = true;
                    this.saveMessage = '';

                    const formData = new FormData();
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    formData.append('assessment', this.clinical.assessment || '');
                    formData.append('plan', this.clinical.plan || '');
                    formData.append('recommendations', this.clinical.recommendations || '');
                    formData.append('diagnosis', this.clinical.diagnosis || '');
                    formData.append('remove_prescription', this.removePrescriptionOnSave ? '1' : '0');

                    if (this.selectedPrescriptionFile) {
                        formData.append('prescription', this.selectedPrescriptionFile);
                    }

                    $.ajax({
                        url: this.clinicalUpdateUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            if (!data.success) {
                                Swal.fire('Save failed', data.message || 'Unable to update clinical details.', 'error');
                                return;
                            }

                            if (data.clinical_details) {
                                this.clinical = data.clinical_details;
                                this.consultationStatus = data.clinical_details.status || this.consultationStatus;
                                this.consultationCompletedAt = data.clinical_details.completed_at || this.consultationCompletedAt;
                            }

                            this.revokeSelectedPrescriptionPreview();
                            this.selectedPrescriptionFile = null;
                            this.selectedPrescriptionName = '';
                            this.removePrescriptionOnSave = false;
                            if (this.$refs.prescription) {
                                this.$refs.prescription.value = '';
                            }

                            this.saveMessage = data.message || 'Clinical details updated successfully.';
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to update clinical details.';
                            Swal.fire('Save failed', message, 'error');
                        },
                        complete: () => {
                            this.isSavingClinical = false;
                        }
                    });
                },
                completeConsultation() {
                    if (this.isCompletingConsultation || this.consultationStatus !== 'active') return;

                    Swal.fire({
                        title: 'Complete consultation?',
                        text: 'This will lock messaging and the assessment tab for further edits.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Complete',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#059669'
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.isCompletingConsultation = true;
                        const csrfToken = $('meta[name="csrf-token"]').attr('content');

                        $.ajax({
                            url: this.completeUrl,
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            success: (data) => {
                                this.consultationStatus = data.session_status || 'completed';
                                this.consultationCompletedAt = data.completed_at || null;
                                this.saveMessage = data.message || 'Consultation completed successfully.';

                                // The consultation is no longer active: stale video UI state
                                // must not survive the transition, even briefly before reload.
                                this.videoActive = false;
                                if (this.inVideoCall) {
                                    this.leaveVideoCall();
                                }

                                this.handleDraftBlur();
                                this.activeTab = 'assessment';
                                window.location.reload();
                            },
                            error: (xhr) => {
                                const message = xhr.responseJSON?.message || 'Unable to complete the consultation.';
                                Swal.fire('Completion failed', message, 'error');
                            },
                            complete: () => {
                                this.isCompletingConsultation = false;
                            }
                        });
                    });
                },
                sendMessage() {
                    if (this.isSending) return;
                    if (this.consultationStatus !== 'active') return;

                    const content = this.draft.trim();
                    if (!content && this.selectedFiles.length === 0) {
                        Swal.fire('Cannot send', 'Add a message or attach a file first.', 'warning');
                        return;
                    }

                    this.isSending = true;

                    const formData = new FormData();
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    formData.append('message', content);
                    this.selectedFiles.forEach(item => formData.append('attachments[]', item.file));

                    $.ajax({
                        url: this.postUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            if (!data.success) {
                                Swal.fire('Send failed', data.message || 'Unable to send message.', 'error');
                                return;
                            }

                            this.draft = '';
                            this.handleDraftBlur();
                            this.clearAttachmentSelection();

                            // store() returns the message it just created in the
                            // same shape index() uses, so it can go straight into
                            // the list instead of costing a second round trip for
                            // the whole conversation. If it is ever missing, fall
                            // back to the original full refresh.
                            if (data.created_message && data.created_message.message_id) {
                                this.appendSentMessage(data.created_message);
                            } else {
                                this.fetchMessages(true);
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to send message.';
                            Swal.fire('Send failed', message, 'error');
                        },
                        complete: () => {
                            this.isSending = false;
                        }
                    });
                },
                // Adds a just-sent message to the conversation without refetching
                // it. The message_id check keeps a poll that landed first from
                // producing a duplicate; the next poll replaces this list with the
                // server's own copy either way, so nothing here can drift.
                appendSentMessage(message) {
                    const alreadyListed = this.messages.some(
                        (existing) => Number(existing.message_id) === Number(message.message_id)
                    );

                    if (!alreadyListed) {
                        this.messages.push(message);
                    }

                    this.$nextTick(() => this.scrollToBottom());
                },
                // Presentation-only helpers for the conversation view. They derive
                // everything from the message payload the API already returns —
                // no extra request, no stored state, no change to how messages are
                // sent, fetched, or validated.
                dayKey(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    if (Number.isNaN(date.getTime())) return '';
                    return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
                },
                messageDayLabel(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    if (Number.isNaN(date.getTime())) return '';

                    const today = new Date();
                    const yesterday = new Date();
                    yesterday.setDate(today.getDate() - 1);

                    if (this.dayKey(iso) === this.dayKey(today.toISOString())) return 'Today';
                    if (this.dayKey(iso) === this.dayKey(yesterday.toISOString())) return 'Yesterday';

                    return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
                },
                showDayDivider(index) {
                    if (index === 0) return true;
                    return this.dayKey(this.messages[index].created_at) !== this.dayKey(this.messages[index - 1].created_at);
                },
                showSenderHeading(index) {
                    if (this.showDayDivider(index)) return true;
                    return Number(this.messages[index].sender_id) !== Number(this.messages[index - 1].sender_id);
                },
                shortTime(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    if (Number.isNaN(date.getTime())) return '';
                    return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                },
                attachmentIsImage(file) {
                    return String(file?.mime_type || '').startsWith('image/');
                },
                attachmentIsVideo(file) {
                    return String(file?.mime_type || '').startsWith('video/');
                }
            }
        }
    </script>
</x-app-layout>
