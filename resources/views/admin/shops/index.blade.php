<x-layouts.admin title="Shops" heading="Shops" subheading="Physical locations owned by tenants.">
    <x-slot:action>
        <a href="{{ route('admin.shops.create') }}" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Shop</a>
    </x-slot:action>

    <form method="GET" class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 lg:grid-cols-[1fr_180px_220px_auto]">
            <input name="search" value="{{ request('search') }}" placeholder="Search shop, city, or tenant" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <select name="status" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
                <option value="">All statuses</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>
            <select name="payments" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
                <option value="">All payment states</option>
                <option value="configured" @selected(request('payments') === 'configured')>Payments configured</option>
                <option value="unconfigured" @selected(request('payments') === 'unconfigured')>Payments not configured</option>
            </select>
            <div class="flex gap-2">
                <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Filter</button>
                <a href="{{ route('admin.shops.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Reset</a>
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Tenant</th>
                    <th class="px-4 py-3 font-medium">City</th>
                    <th class="px-4 py-3 font-medium">Payments</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($shops as $shop)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $shop->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $shop->routers_count ?? $shop->routers()->count() }} routers · {{ $shop->packages_count ?? $shop->packages()->count() }} packages</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 text-zinc-600">{{ $shop->location_city ?: 'Not set' }}</td>
                        <td class="px-4 py-3">
                            @if ($shop->hasCompleteFlutterwaveCredentials())
                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">Configured</span>
                                <p class="mt-2 text-xs text-zinc-500">{{ $shop->hasFlutterwaveWebhookSecret() ? 'Webhook secret saved' : 'Webhook secret missing' }}</p>
                            @else
                                <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">Not configured</span>
                                <p class="mt-2 text-xs text-zinc-500">Customer payments disabled</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $shop->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600' }}">
                                {{ $shop->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.shops.edit', $shop) }}" class="rounded-md border border-zinc-200 px-3 py-1.5">Edit</a>
                                <form method="POST" action="{{ route('admin.shops.destroy', $shop) }}" onsubmit="return confirm('Delete this shop?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md border border-red-200 px-3 py-1.5 text-red-700">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center">
                            <p class="font-medium">No shops match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Create a shop for each hotspot location or clear the filters.</p>
                            <a href="{{ route('admin.shops.create') }}" class="mt-4 inline-flex rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Shop</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $shops->links() }}</div>
</x-layouts.admin>
