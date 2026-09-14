<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Edit User') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">First name</label>
                                <input type="text" name="first_name" value="{{ old('first_name', $user->first_name) }}" required class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Last name</label>
                                <input type="text" name="last_name" value="{{ old('last_name', $user->last_name) }}" required class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Role</label>
                                <select name="role" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="patient" {{ old('role', $user->role) === 'patient' ? 'selected' : '' }}>Patient</option>
                                    <option value="nurse" {{ old('role', $user->role) === 'nurse' ? 'selected' : '' }}>Nurse</option>
                                    <option value="physician" {{ old('role', $user->role) === 'physician' ? 'selected' : '' }}>Physician</option>
                                    <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>Admin</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Account Status</label>
                                @if($user->account_status === 'inactive')
                                    {{-- Not an admin choice: this account is still waiting on the
                                         invited staff member's own activation link (or, for a
                                         patient, on email verification). It becomes editable —
                                         Active or Suspended — automatically once that happens. --}}
                                    <input type="text" value="Inactive &mdash; awaiting email verification" disabled class="mt-1 w-full rounded-md border-gray-200 bg-gray-100 text-gray-500 shadow-sm">
                                @else
                                    <select name="account_status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                        <option value="active" {{ old('account_status', $user->account_status) === 'active' ? 'selected' : '' }}>Active</option>
                                        <option value="suspended" {{ old('account_status', $user->account_status) === 'suspended' ? 'selected' : '' }}>Suspend</option>
                                    </select>
                                @endif
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Department</label>
                                <input type="text" name="department" value="{{ old('department', $user->department) }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Staff Position</label>
                                <input type="text" name="staff_position" value="{{ old('staff_position', $user->staff_position) }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Specialization</label>
                                <input type="text" name="specialization" value="{{ old('specialization', $user->specialization) }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <x-button-primary type="submit">Save Changes</x-button-primary>
                            <x-button-secondary href="{{ route('admin.users.index') }}">Cancel</x-button-secondary>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
