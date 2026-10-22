<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('CHIS Sync') }}
        </h2>
    </x-slot>

    @php
        // Sync status only — this page never shows clinical data.
        $badge = [
            'synced' => ['label' => 'Synced', 'class' => 'bg-emerald-50 text-emerald-800'],
            'pending' => ['label' => 'Pending', 'class' => 'bg-slate-100 text-slate-700'],
            'failed' => ['label' => 'Failed', 'class' => 'bg-amber-50 text-amber-800'],
            'skipped' => ['label' => 'Skipped', 'class' => 'bg-gray-100 text-gray-600'],
        ];
        $tabs = ['' => 'All'] + array_map(fn ($b) => $b['label'], $badge);
        $rows = $sessions->map(fn ($s) => [
            'session' => $s,
            'patient' => $s->request?->patient,
            'physician' => $s->physician,
            'badge' => $badge[$s->chis_sync_status],
            'error' => $s->chis_last_error,
            'errorText' => \App\Services\ChisEncounterSync::describeError($s->chis_last_error),
            'retryable' => in_array($s->chis_sync_status, ['failed', 'pending'], true),
        ]);
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if(session('status'))
                        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if(session('warning'))
                        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                            {{ session('warning') }}
                        </div>
                    @endif

                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold">Consultation summaries sent to CHIS</h3>
                            <p class="text-sm text-gray-500">Comprehensive Health Information System. Sync status only; no clinical details are shown here.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-button-secondary href="{{ route('admin.chis-audit.index') }}">Audit log</x-button-secondary>
                            <p @class([
                                'inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium',
                                'bg-emerald-50 text-emerald-800' => $chisReachable,
                                'bg-amber-50 text-amber-800' => ! $chisReachable,
                            ])>
                                @if($chisReachable)
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    CHIS reachable
                                @else
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line><circle cx="12" cy="12" r="10"></circle></svg>
                                    CHIS not reachable
                                @endif
                            </p>
                        </div>
                    </div>

                    {{-- Summary counts double as the status filter. --}}
                    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Filter by sync status">
                        @foreach($tabs as $key => $label)
                            @php($active = ($status ?? '') === $key)
                            <a href="{{ route('admin.chis-sync.index', $key === '' ? [] : ['status' => $key]) }}"
                               @if($active) aria-current="page" @endif
                               @class([
                                   'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition',
                                   'border-brand-green bg-brand-green-soft text-brand-green-deep' => $active,
                                   'border-gray-200 text-gray-700 hover:bg-gray-50' => ! $active,
                               ])>
                                {{ $label }}
                                <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-gray-700">{{ $key === '' ? $counts->sum() : ($counts[$key] ?? 0) }}</span>
                            </a>
                        @endforeach
                    </nav>

                    @if($rows->isEmpty())
                        <p class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500">No consultations to show.</p>
                    @else
                        {{-- Mobile: one card per consultation, like the users page. --}}
                        <div class="space-y-3 sm:hidden">
                            @foreach($rows as $row)
                                <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-gray-900">{{ $row['patient']?->first_name }} {{ $row['patient']?->last_name }}</p>
                                            <p class="text-xs text-gray-500">{{ $row['patient']?->clsu_id ?? 'No CLSU ID' }} · {{ $row['session']->completed_at?->format('M j, Y g:i A') }}</p>
                                        </div>
                                        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $row['badge']['class'] }}">{{ $row['badge']['label'] }}</span>
                                    </div>
                                    <p class="mt-2 text-xs text-gray-600">Physician: {{ $row['physician']?->first_name }} {{ $row['physician']?->last_name }} · Attempts: {{ $row['session']->chis_attempts }}</p>
                                    @if($row['session']->chis_reference)
                                        <p class="mt-1 text-xs text-gray-600">CHIS reference: <span class="font-mono">{{ $row['session']->chis_reference }}</span></p>
                                    @endif
                                    @if($row['error'])
                                        <p class="mt-1 text-xs text-gray-700">{{ $row['errorText'] ?? $row['error'] }} @if($row['errorText'])<span class="font-mono text-gray-400">({{ $row['error'] }})</span>@endif</p>
                                    @endif
                                    <div class="mt-2 flex items-center gap-2">
                                        <x-button-ghost href="{{ route('admin.chis-audit.index', ['session' => $row['session']->id]) }}">History</x-button-ghost>
                                        @if($row['retryable'])
                                            <form method="POST" action="{{ route('admin.chis-sync.retry', $row['session']) }}">
                                                @csrf
                                                <x-button-ghost type="submit">Retry</x-button-ghost>
                                            </form>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <div class="hidden overflow-x-auto sm:block">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Completed</th>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Patient</th>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Physician</th>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Status</th>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Attempts</th>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">CHIS reference</th>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Last error</th>
                                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($rows as $row)
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">{{ $row['session']->completed_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-800">
                                                {{ $row['patient']?->first_name }} {{ $row['patient']?->last_name }}
                                                <span class="block font-mono text-xs text-gray-500">{{ $row['patient']?->clsu_id ?? 'No CLSU ID' }}</span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700">{{ $row['physician']?->first_name }} {{ $row['physician']?->last_name }}</td>
                                            <td class="px-4 py-3 text-sm">
                                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $row['badge']['class'] }}">{{ $row['badge']['label'] }}</span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700">{{ $row['session']->chis_attempts }}</td>
                                            <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $row['session']->chis_reference ?? '—' }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-700">
                                                @if($row['error'])
                                                    {{ $row['errorText'] ?? $row['error'] }}
                                                    @if($row['errorText'])
                                                        <span class="block font-mono text-xs text-gray-400">{{ $row['error'] }}</span>
                                                    @endif
                                                @else
                                                    <span class="text-gray-400">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                <div class="flex items-center gap-1">
                                                    <x-button-ghost href="{{ route('admin.chis-audit.index', ['session' => $row['session']->id]) }}">History</x-button-ghost>
                                                    @if($row['retryable'])
                                                        <form method="POST" action="{{ route('admin.chis-sync.retry', $row['session']) }}">
                                                            @csrf
                                                            <x-button-ghost type="submit">Retry</x-button-ghost>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            {{ $sessions->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
