<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Create Staff Account') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($errors->any())
                        <div class="mb-6 rounded-md bg-red-50 p-4 text-sm text-red-700">
                            <ul class="list-disc space-y-1 pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-4">
                        @csrf

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">First name</label>
                                <input type="text" name="first_name" value="{{ old('first_name') }}" required class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Last name</label>
                                <input type="text" name="last_name" value="{{ old('last_name') }}" required class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <p class="mt-1 text-xs text-gray-500">The activation invitation will be addressed to this email.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Role</label>
                                <select name="role" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="nurse" {{ old('role') === 'nurse' ? 'selected' : '' }}>Nurse</option>
                                    <option value="physician" {{ old('role') === 'physician' ? 'selected' : '' }}>Physician</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">CLSU ID</label>
                                <input type="text" name="clsu_id" value="{{ old('clsu_id') }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Department</label>
                                <input type="text" name="department" value="{{ old('department') }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Contact Number</label>
                                <input type="text" name="contact_num" value="{{ old('contact_num') }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Staff Position</label>
                                <input type="text" name="staff_position" value="{{ old('staff_position') }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Specialization</label>
                                <input type="text" name="specialization" value="{{ old('specialization') }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <p class="mt-1 text-xs text-gray-500">Usually only relevant for physicians.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Account Status</label>
                                <input type="text" value="Inactive until activated" disabled class="mt-1 w-full rounded-md border-gray-200 bg-gray-100 text-gray-500 shadow-sm">
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <x-button-primary type="submit">Create Staff Account</x-button-primary>
                            <x-button-secondary href="{{ route('admin.users.index') }}">Cancel</x-button-secondary>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
