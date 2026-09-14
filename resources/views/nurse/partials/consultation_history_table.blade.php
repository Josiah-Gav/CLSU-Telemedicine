@if($historyConsultations->isEmpty())
    <x-dash.empty message="No consultation history found for the selected filters." />
@else
    {{-- Phase 3: 6 columns, no room at 375/390px without clipping or
         page-level horizontal scroll. Read-only history, so the mobile
         card needs no JS wiring — just the same fields in a stacked layout. --}}
    <div class="space-y-3 sm:hidden">
        @foreach($historyConsultations as $consultation)
            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <p class="truncate text-sm font-medium text-gray-900">
                        {{ optional($consultation->patient)->first_name ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name : __('Unknown Patient') }}
                    </p>
                    <x-dash.badge :status="$consultation->request_status" size="sm" />
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <x-dash.badge :priority="$consultation->priority_level" size="sm" />
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                        {{ $consultation->type === 'follow_up' ? __('Follow-up') : __('General') }}
                    </span>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                    <dt class="text-gray-500">{{ __('Physician') }}</dt>
                    <dd class="text-right text-gray-900">{{ trim(optional($consultation->physician)->first_name . ' ' . optional($consultation->physician)->last_name) ?: __('Unassigned') }}</dd>
                    <dt class="text-gray-500">{{ __('Completed') }}</dt>
                    <dd class="text-right text-gray-900">{{ optional(optional($consultation->consultationSession)->completed_at)->format('M. j, Y g:i A') ?? optional($consultation->updated_at)->format('M. j, Y g:i A') ?? __('Unknown') }}</dd>
                </dl>
            </article>
        @endforeach
    </div>

    <div class="hidden overflow-x-auto sm:block">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Priority') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Assigned Physician') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Consultation Type') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Completed At') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @foreach($historyConsultations as $consultation)
                    <tr>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ optional($consultation->patient)->first_name ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name : __('Unknown Patient') }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                            <x-dash.badge :priority="$consultation->priority_level" />
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ trim(optional($consultation->physician)->first_name . ' ' . optional($consultation->physician)->last_name) ?: __('Unassigned') }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ $consultation->type === 'follow_up' ? __('Follow-up') : __('General') }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                            <x-dash.badge :status="$consultation->request_status" />
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ optional(optional($consultation->consultationSession)->completed_at)->format('M. j, Y g:i A') ?? optional($consultation->updated_at)->format('M. j, Y g:i A') ?? __('Unknown') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
