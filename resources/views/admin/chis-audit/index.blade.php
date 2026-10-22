<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('CHIS Audit Log') }}
        </h2>
    </x-slot>

    @php
        // Records THAT CHIS data was sent or viewed — never WHAT. No clinical data here.
        $badge = [
            'success' => ['label' => 'Success', 'class' => 'bg-emerald-50 text-emerald-800'],
            'not_found' => ['label' => 'Not found', 'class' => 'bg-slate-100 text-slate-700'],
            'rejected' => ['label' => 'Rejected', 'class' => 'bg-amber-50 text-amber-800'],
            'unavailable' => ['label' => 'Unavailable', 'class' => 'bg-gray-100 text-gray-600'],
        ];
        $sources = ['sync_job' => 'Sync job', 'health_command' => 'Health command', 'consultation_view' => 'Consultation view'];
        $rows = $logs->map(fn ($log) => [
            'log' => $log,
            'action' => \App\Models\ChisAuditLog::ACTION_LABELS[$log->action] ?? $log->action,
            'badge' => $badge[$log->outcome] ?? ['label' => $log->outcome, 'class' => 'bg-gray-100 text-gray-600'],
            'source' => $sources[$log->source] ?? $log->source,
            'patient' => $log->patient ? trim($log->patient->first_name.' '.$log->patient->last_name) : null,
            'actor' => $log->actor ? trim($log->actor->first_name.' '.$log->actor->last_name) : 'System',
            'reasonText' => \App\Services\ChisEncounterSync::describeError($log->reason),
        ]);
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold">Every call Telemed made to CHIS</h3>
                            <p class="text-sm text-gray-500">Who sent or viewed whose CHIS data, and when. Append-only; never the data itself.</p>
                        </div>
                        <x-button-secondary href="{{ route('admin.chis-sync.index') }}">CHIS Sync</x-button-secondary>
                    </div>

                    <form method="GET" action="{{ route('admin.chis-audit.index') }}" class="mb-4 grid gap-3 sm:grid-cols-5">
                        <select name="action" aria-label="Action" class="rounded-md border-gray-300 shadow-sm">
                            <option value="">All actions</option>
                            @foreach(\App\Models\ChisAuditLog::ACTION_LABELS as $value => $label)
                                <option value="{{ $value }}" @selected($filters['action'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="outcome" aria-label="Outcome" class="rounded-md border-gray-300 shadow-sm">
                            <option value="">All outcomes</option>
                            @foreach($badge as $value => $b)
                                <option value="{{ $value }}" @selected($filters['outcome'] === $value)>{{ $b['label'] }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="clsu_id" value="{{ $filters['clsu_id'] }}" placeholder="CLSU ID (exact)" aria-label="CLSU ID" class="rounded-md border-gray-300 shadow-sm">
                        <input type="number" name="session" min="1" value="{{ $filters['session'] }}" placeholder="Consultation #" aria-label="Consultation number" class="rounded-md border-gray-300 shadow-sm">
                        <div class="flex items-center gap-2">
                            <x-button-primary type="submit">Filter</x-button-primary>
                            <x-button-ghost href="{{ route('admin.chis-audit.index') }}">Clear</x-button-ghost>
                        </div>
                    </form>

                    @if($rows->isEmpty())
                        <p class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500">No audit entries to show.</p>
                    @else
                        <div class="space-y-3 sm:hidden">
                            @foreach($rows as $row)
                                <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-gray-900">{{ $row['action'] }}</p>
                                            <p class="text-xs text-gray-500">{{ $row['log']->created_at?->format('M j, Y g:i:s A') }} · {{ $row['source'] }} · {{ $row['actor'] }}</p>
                                        </div>
                                        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $row['badge']['class'] }}">{{ $row['badge']['label'] }}</span>
                                    </div>
                                    <p class="mt-2 text-xs text-gray-600">Patient: {{ $row['patient'] ?? '—' }} <span class="font-mono">{{ $row['log']->clsu_id }}</span>@if($row['log']->consultation_session_id) · Consultation #{{ $row['log']->consultation_session_id }}@endif</p>
                                    @if($row['log']->reason)
                                        <p class="mt-1 text-xs text-gray-700">{{ $row['reasonText'] ?? $row['log']->reason }} @if($row['reasonText'])<span class="font-mono text-gray-400">({{ $row['log']->reason }})</span>@endif</p>
                                    @endif
                                    <p class="mt-1 text-xs text-gray-500">HTTP {{ $row['log']->http_status ?? '—' }} · {{ $row['log']->duration_ms !== null ? $row['log']->duration_ms.' ms' : '—' }}@if($row['log']->chis_reference) · <span class="font-mono">{{ $row['log']->chis_reference }}</span>@endif</p>
                                </article>
                            @endforeach
                        </div>

                        <div class="hidden overflow-x-auto sm:block">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        @foreach(['Time', 'Action', 'Outcome', 'Source', 'Patient', 'Actor', 'Consultation', 'HTTP', 'Reason', 'CHIS reference', 'Duration'] as $heading)
                                            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-600 whitespace-nowrap">{{ $heading }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($rows as $row)
                                        <tr>
                                            <td class="px-3 py-3 text-sm text-gray-700 whitespace-nowrap">{{ $row['log']->created_at?->format('M j, Y g:i:s A') }}</td>
                                            <td class="px-3 py-3 text-sm text-gray-800 whitespace-nowrap">{{ $row['action'] }}</td>
                                            <td class="px-3 py-3 text-sm">
                                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $row['badge']['class'] }}">{{ $row['badge']['label'] }}</span>
                                            </td>
                                            <td class="px-3 py-3 text-sm text-gray-700 whitespace-nowrap">{{ $row['source'] }}</td>
                                            <td class="px-3 py-3 text-sm text-gray-800">
                                                {{ $row['patient'] ?? '—' }}
                                                <span class="block font-mono text-xs text-gray-500">{{ $row['log']->clsu_id }}</span>
                                            </td>
                                            <td class="px-3 py-3 text-sm text-gray-700">{{ $row['actor'] }}</td>
                                            <td class="px-3 py-3 text-sm text-gray-700">{{ $row['log']->consultation_session_id ? '#'.$row['log']->consultation_session_id : '—' }}</td>
                                            <td class="px-3 py-3 text-sm text-gray-700">{{ $row['log']->http_status ?? '—' }}</td>
                                            <td class="px-3 py-3 text-sm text-gray-700">
                                                @if($row['log']->reason)
                                                    {{ $row['reasonText'] ?? $row['log']->reason }}
                                                    @if($row['reasonText'])
                                                        <span class="block font-mono text-xs text-gray-400">{{ $row['log']->reason }}</span>
                                                    @endif
                                                @else
                                                    <span class="text-gray-400">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 font-mono text-xs text-gray-700">{{ $row['log']->chis_reference ?? '—' }}</td>
                                            <td class="px-3 py-3 text-sm text-gray-700 whitespace-nowrap">{{ $row['log']->duration_ms !== null ? $row['log']->duration_ms.' ms' : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            {{ $logs->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
