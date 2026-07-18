<x-layouts.admin title="Users" heading="Users" subheading="Admin accounts that can sign in to manage the platform or tenant workspace.">
    <x-slot:action>
        <a href="{{ route('admin.users.create') }}" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add User</a>
    </x-slot:action>

    <form method="GET" class="mb-4 grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 md:grid-cols-[1fr_180px_180px_auto]">
        <input name="search" value="{{ request('search') }}" placeholder="Search name, email, tenant" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
        <select name="role" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <option value="">All roles</option>
            @foreach (['super_admin' => 'Super admin', 'tenant_admin' => 'Tenant admin'] as $value => $label)
                <option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="status" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
        </select>
        <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Filter</button>
    </form>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">User</th>
                    <th class="px-4 py-3 font-medium">Tenant</th>
                    <th class="px-4 py-3 font-medium">Role</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($users as $managedUser)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $managedUser->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $managedUser->email }}</p>
                        </td>
                        <td class="px-4 py-3">{{ $managedUser->tenant?->company_name ?? 'Platform' }}</td>
                        <td class="px-4 py-3">{{ str_replace('_', ' ', $managedUser->role) }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $managedUser->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600' }}">
                                {{ $managedUser->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.users.edit', $managedUser) }}" class="rounded-md border border-zinc-200 px-3 py-1.5">Edit</a>
                                @if (! auth()->user()->is($managedUser))
                                    <form method="POST" action="{{ route('admin.users.destroy', $managedUser) }}" onsubmit="return confirm('Delete this user?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-md border border-red-200 px-3 py-1.5 text-red-700">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</x-layouts.admin>
