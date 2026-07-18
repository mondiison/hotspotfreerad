<x-layouts.admin title="Tenants" heading="Tenants" subheading="SaaS customers that own one or more hotspot shops.">
    <x-slot:action>
        <a href="{{ route('admin.tenants.create') }}" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Tenant</a>
    </x-slot:action>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Company</th>
                    <th class="px-4 py-3 font-medium">Public site</th>
                    <th class="px-4 py-3 font-medium">Owner</th>
                    <th class="px-4 py-3 font-medium">Plan</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($tenants as $tenant)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $tenant->company_name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">/{{ $tenant->slug }}</p>
                        </td>
                        <td class="px-4 py-3">
                            @if ($tenant->public_site_enabled)
                                <a href="{{ $tenant->publicUrl() }}" target="_blank" class="text-zinc-950 underline decoration-zinc-300 underline-offset-4">Open</a>
                            @else
                                <span class="text-zinc-500">Disabled</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $tenant->owner_email }}</td>
                        <td class="px-4 py-3">{{ ucfirst($tenant->subscription_plan) }}</td>
                        <td class="px-4 py-3">{{ $tenant->is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.tenants.edit', $tenant) }}" class="rounded-md border border-zinc-200 px-3 py-1.5">Edit</a>
                                <form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}" onsubmit="return confirm('Delete this tenant?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md border border-red-200 px-3 py-1.5 text-red-700">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No tenants yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tenants->links() }}</div>
</x-layouts.admin>
