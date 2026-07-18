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
            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input name="name" value="{{ old('name', $managedUser->name) }}" icon="user" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>Email</flux:label>
                <flux:input type="email" name="email" value="{{ old('email', $managedUser->email) }}" icon="envelope" required />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>Tenant</flux:label>
                <flux:select name="tenant_id" :required="! auth()->user()->isSuperAdmin()">
                    @if (auth()->user()->isSuperAdmin())
                        <option value="">Platform account</option>
                    @endif
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(old('tenant_id', $managedUser->tenant_id) == $tenant->id)>{{ $tenant->company_name }}</option>
                    @endforeach
                </flux:select>
                <flux:description>Tenant admins are always attached to one tenant.</flux:description>
                <flux:error name="tenant_id" />
            </flux:field>

            <flux:field>
                <flux:label>Role</flux:label>
                <flux:select name="role" required>
                    @if (auth()->user()->isSuperAdmin())
                        <option value="super_admin" @selected(old('role', $managedUser->role) === 'super_admin')>Super admin</option>
                    @endif
                    <option value="tenant_admin" @selected(old('role', $managedUser->role ?: 'tenant_admin') === 'tenant_admin')>Tenant admin</option>
                </flux:select>
                <flux:error name="role" />
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label>{{ $managedUser->exists ? 'New password' : 'Password' }}</flux:label>
                <flux:input type="password" name="password" icon="key" viewable :required="! $managedUser->exists" />
                <flux:description>{{ $managedUser->exists ? 'Leave blank to keep the current password.' : 'Use at least 8 characters.' }}</flux:description>
                <flux:error name="password" />
            </flux:field>

            <div class="md:col-span-2">
                <flux:checkbox name="is_active" value="1" :checked="(bool) old('is_active', $managedUser->is_active ?? true)" label="Active account" :disabled="auth()->user()->is($managedUser)" />
            </div>
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save User</flux:button>
            <flux:button href="{{ route('admin.users.index') }}" variant="outline">Cancel</flux:button>
        </div>
    </form>
</x-layouts.admin>
