<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="space-y-2">
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif

            @error('user')
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            Add User
        </flux:button>
    </div>

    <section class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 lg:grid-cols-[1fr_180px_180px_auto]">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search name, email, or tenant" />
            <flux:select wire:model.live="role">
                <flux:select.option value="">All roles</flux:select.option>
                <flux:select.option value="super_admin">Super admin</flux:select.option>
                <flux:select.option value="tenant_admin">Tenant admin</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="status">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
            <flux:button type="button" variant="outline" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,role,status">
                Reset
            </flux:button>
        </div>
    </section>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
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
                    <tr wire:key="user-{{ $managedUser->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $managedUser->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $managedUser->email }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $managedUser->tenant?->company_name ?? 'Platform' }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$managedUser->isSuperAdmin() ? 'purple' : 'sky'">
                                {{ str($managedUser->role)->replace('_', ' ')->title() }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$managedUser->is_active ? 'green' : 'zinc'">{{ $managedUser->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                            @if ($managedUser->must_change_password)
                                <p class="mt-2 text-xs text-amber-700">Password change required</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $managedUser->id }})" wire:loading.attr="disabled" wire:target="edit({{ $managedUser->id }})">Edit</flux:button>
                                @if (! auth()->user()->is($managedUser))
                                    <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $managedUser->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $managedUser->id }})">Delete</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center">
                            <p class="font-medium">No users match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Create admin accounts for platform operators or tenant workspace managers.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add User</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingUserId ? 'Edit User' : 'Add User' }}</flux:heading>
                <flux:text class="mt-2">Create controlled admin access without sharing tenant owner passwords.</flux:text>
            </div>

            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Name</flux:label>
                        <flux:input wire:model.blur="name" icon="user" required />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Email</flux:label>
                        <flux:input type="email" wire:model.blur="email" icon="envelope" required />
                        <flux:error name="email" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Tenant</flux:label>
                        <flux:select wire:model.live="tenant_id" :disabled="! auth()->user()->isSuperAdmin()">
                            @if (auth()->user()->isSuperAdmin())
                                <flux:select.option value="">Platform account</flux:select.option>
                            @endif
                            @foreach ($tenants as $tenant)
                                <flux:select.option value="{{ $tenant->id }}">{{ $tenant->company_name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>Tenant admins are scoped to one tenant. Super admins use Platform account.</flux:description>
                        <flux:error name="tenant_id" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Role</flux:label>
                        <flux:select wire:model.live="user_role" :disabled="! auth()->user()->isSuperAdmin()" required>
                            @if (auth()->user()->isSuperAdmin())
                                <flux:select.option value="super_admin">Super admin</flux:select.option>
                            @endif
                            <flux:select.option value="tenant_admin">Tenant admin</flux:select.option>
                        </flux:select>
                        <flux:description>Tenant admins manage only their workspace records.</flux:description>
                        <flux:error name="role" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>{{ $editingUserId ? 'New password' : 'Password' }}</flux:label>
                        <flux:input type="password" wire:model.blur="password" icon="key" viewable :required="! $editingUserId" />
                        <flux:description>{{ $editingUserId ? 'Leave blank to keep the current password.' : 'Use at least 8 characters, or create tenants from Tenant Management to email temporary passwords.' }}</flux:description>
                        <flux:error name="password" />
                    </flux:field>

                    <div class="md:col-span-2">
                        <flux:checkbox wire:model.live="is_active" label="Active account" :disabled="$editingUserId && auth()->id() === $editingUserId" />
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <h2 class="text-sm font-semibold text-zinc-950">Access guide</h2>
                    <p class="mt-1 text-sm leading-6 text-zinc-600">
                        Use Super admin for platform billing, tenant creation, and global reporting. Use Tenant admin for a business owner or staff member who should only see that tenant's shops, routers, packages, customers, sales, and expenses.
                    </p>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save User</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete User</flux:heading>
                <flux:text class="mt-2">This removes the admin sign-in account. Existing business records remain intact.</flux:text>
            </div>

            @if ($deletingUser)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingUser->name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $deletingUser->email }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete User</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
