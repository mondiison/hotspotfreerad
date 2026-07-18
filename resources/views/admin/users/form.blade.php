<x-layouts.admin
    :title="$managedUser->exists ? 'Edit User' : 'Add User'"
    :heading="$managedUser->exists ? 'Edit User' : 'Add User'"
    subheading="Create and maintain admin sign-in accounts."
>
    <form method="POST" action="{{ $managedUser->exists ? route('admin.users.update', $managedUser) : route('admin.users.store') }}" class="max-w-3xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($managedUser->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">Name</span>
                <input name="name" value="{{ old('name', $managedUser->name) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Email</span>
                <input type="email" name="email" value="{{ old('email', $managedUser->email) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Tenant</span>
                <select name="tenant_id" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" @required(! auth()->user()->isSuperAdmin())>
                    @if (auth()->user()->isSuperAdmin())
                        <option value="">Platform account</option>
                    @endif
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(old('tenant_id', $managedUser->tenant_id) == $tenant->id)>{{ $tenant->company_name }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-zinc-500">Tenant admins are always attached to one tenant.</span>
                @error('tenant_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Role</span>
                <select name="role" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    @if (auth()->user()->isSuperAdmin())
                        <option value="super_admin" @selected(old('role', $managedUser->role) === 'super_admin')>Super admin</option>
                    @endif
                    <option value="tenant_admin" @selected(old('role', $managedUser->role ?: 'tenant_admin') === 'tenant_admin')>Tenant admin</option>
                </select>
                @error('role') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium">{{ $managedUser->exists ? 'New password' : 'Password' }}</span>
                <input type="password" name="password" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" @required(! $managedUser->exists)>
                <span class="mt-1 block text-xs text-zinc-500">{{ $managedUser->exists ? 'Leave blank to keep the current password.' : 'Use at least 8 characters.' }}</span>
                @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-2 text-sm md:col-span-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $managedUser->is_active ?? true)) class="rounded border-zinc-300" @disabled(auth()->user()->is($managedUser))>
                Active account
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save User</button>
            <a href="{{ route('admin.users.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
