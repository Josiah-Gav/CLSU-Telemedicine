<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('User Management') }}
        </h2>
    </x-slot>

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

                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">Users</h3>
                        <x-button-primary href="{{ route('admin.users.create') }}">Create User</x-button-primary>
                    </div>

                    {{-- Phase 3: 6 columns (2 of them free-text — name, email)
                         plus a 2-action cell cannot fit at 375/390px without
                         either clipping the actions or forcing page-level
                         horizontal scroll. Mobile gets a card per user
                         instead; same $invitations lookup and same routes,
                         no duplicated logic. --}}
                    <div class="space-y-3 sm:hidden">
                        @foreach($users as $user)
                            @php($invitation = $invitations[$user->user_id] ?? null)
                            <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900">{{ $user->first_name }} {{ $user->last_name }}</p>
                                    <p class="truncate text-xs text-gray-500">{{ $user->email }}</p>
                                </div>

                                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ ucfirst($user->role) }}</span>
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ ucfirst($user->account_status) }}</span>
                                    @if($invitation)
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                                            'bg-emerald-50 text-emerald-800' => $invitation['state'] === 'pending',
                                            'bg-amber-50 text-amber-800' => $invitation['state'] === 'expired',
                                            'bg-gray-100 text-gray-700' => $invitation['state'] === 'missing',
                                        ])>{{ $invitation['label'] }}</span>
                                    @endif
                                </div>

                                <div class="mt-2 flex items-center gap-2">
                                    <x-button-ghost href="{{ route('admin.users.edit', $user) }}">{{ __('Edit') }}</x-button-ghost>
                                    @if($invitation)
                                        <form method="POST" action="{{ route('admin.users.resend_invitation', $user) }}">
                                            @csrf
                                            <x-button-ghost type="submit">
                                                {{ $invitation['state'] === 'missing' ? __('Send Invitation') : __('Resend Invitation') }}
                                            </x-button-ghost>
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
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Name</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Email</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Role</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Status</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Invitation</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($users as $user)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-800">{{ $user->first_name }} {{ $user->last_name }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $user->email }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ ucfirst($user->role) }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ ucfirst($user->account_status) }}</td>
                                        @php($invitation = $invitations[$user->user_id] ?? null)
                                        <td class="px-4 py-3 text-sm">
                                            @if($invitation)
                                                <span @class([
                                                    'inline-block rounded-full px-2 py-0.5 text-xs font-medium',
                                                    'bg-emerald-50 text-emerald-800' => $invitation['state'] === 'pending',
                                                    'bg-amber-50 text-amber-800' => $invitation['state'] === 'expired',
                                                    'bg-gray-100 text-gray-700' => $invitation['state'] === 'missing',
                                                ])>{{ $invitation['label'] }}</span>
                                            @else
                                                <span class="text-gray-400">&mdash;</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex items-center gap-1">
                                                <x-button-ghost href="{{ route('admin.users.edit', $user) }}">Edit</x-button-ghost>

                                                {{-- Offered only for accounts the activation flow would actually accept. --}}
                                                @if($invitation)
                                                    <form method="POST" action="{{ route('admin.users.resend_invitation', $user) }}">
                                                        @csrf
                                                        <x-button-ghost type="submit">
                                                            {{ $invitation['state'] === 'missing' ? 'Send Invitation' : 'Resend Invitation' }}
                                                        </x-button-ghost>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
