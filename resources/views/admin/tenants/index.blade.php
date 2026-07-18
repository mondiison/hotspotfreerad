<x-layouts.admin title="Tenants" heading="Tenants" subheading="SaaS customers that own one or more hotspot shops.">
    <x-slot:action>
        <flux:button href="{{ route('admin.tenants.create') }}" variant="primary" icon="plus">Add Tenant</flux:button>
    </x-slot:action>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Company</th>
                    <th class="px-4 py-3 font-medium">Public site</th>
                    <th class="px-4 py-3 font-medium">Owner</th>
                    <th class="px-4 py-3 font-medium">Owner access</th>
                    <th class="px-4 py-3 font-medium">Plan</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($tenants as $tenant)
                    @php($ownerUser = $ownerUsers->get($tenant->id))
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $tenant->company_name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">/{{ $tenant->slug }}</p>
                        </td>
                        <td class="px-4 py-3">
                            @if ($tenant->public_site_enabled)
                                <flux:button href="{{ $tenant->publicUrl() }}" target="_blank" variant="ghost" size="sm" icon="arrow-top-right-on-square">Open</flux:button>
                            @else
                                <flux:badge color="zinc">Disabled</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $tenant->owner_email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-2">
                                @if (! $ownerUser)
                                    <flux:badge color="amber">Login missing</flux:badge>
                                @elseif ($ownerUser->must_change_password)
                                    <flux:badge color="amber">Temporary password</flux:badge>
                                @elseif (! $ownerUser->is_active)
                                    <flux:badge color="red">Login inactive</flux:badge>
                                @else
                                    <flux:badge color="green">Ready</flux:badge>
                                @endif

                                <form method="POST" action="{{ route('admin.tenants.owner-reset-link', $tenant) }}">
                                    @csrf
                                    <flux:button type="submit" variant="outline" size="sm" icon="envelope">Send reset link</flux:button>
                                </form>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ ucfirst($tenant->subscription_plan) }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$tenant->is_active ? 'green' : 'zinc'">{{ $tenant->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button href="{{ route('admin.tenants.edit', $tenant) }}" variant="outline" size="sm" icon="pencil-square">Edit</flux:button>
                                <form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}" onsubmit="return confirm('Delete this tenant?')">
                                    @csrf
                                    @method('DELETE')
                                    <flux:button type="submit" variant="danger" size="sm" icon="trash">Delete</flux:button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-zinc-500">No tenants yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tenants->links() }}</div>
</x-layouts.admin>
