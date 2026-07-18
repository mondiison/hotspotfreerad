<x-layouts.admin title="Packages" heading="Packages" subheading="Internet plans synced as RADIUS group profiles.">
    <x-slot:action>
        <a href="{{ route('admin.packages.create') }}" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Package</a>
    </x-slot:action>

    <form method="GET" class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 md:grid-cols-[1fr_180px_auto]">
            <input name="search" value="{{ request('search') }}" placeholder="Search package, shop, group, or speed" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <select name="status" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
                <option value="">All statuses</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>
            <div class="flex gap-2">
                <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Filter</button>
                <a href="{{ route('admin.packages.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Reset</a>
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Group</th>
                    <th class="px-4 py-3 font-medium">Price</th>
                    <th class="px-4 py-3 font-medium">Time</th>
                    <th class="px-4 py-3 font-medium">Data</th>
                    <th class="px-4 py-3 font-medium">Speed</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($packages as $package)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $package->name }}</p>
                            <p class="mt-1 text-xs">
                                <span class="rounded-full px-2 py-1 font-medium {{ $package->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600' }}">{{ $package->is_active ? 'Active' : 'Inactive' }}</span>
                            </p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $package->shop->name }} / {{ $package->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $package->radius_group_name ?: 'Pending sync' }}</td>
                        <td class="px-4 py-3">{{ $package->currency }} {{ number_format($package->price, 2) }}</td>
                        <td class="px-4 py-3">
                            @php
                                $days = intdiv($package->limit_uptime_seconds, 86400);
                                $hours = intdiv($package->limit_uptime_seconds % 86400, 3600);
                            @endphp
                            {{ $days ? "{$days}d" : "{$hours}h" }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $package->data_limit_bytes ? number_format($package->data_limit_bytes / 1073741824, 1) . ' GB' : 'Unlimited' }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $package->speed_limit_profile }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.packages.edit', $package) }}" class="rounded-md border border-zinc-200 px-3 py-1.5">Edit</a>
                                <form method="POST" action="{{ route('admin.packages.destroy', $package) }}" onsubmit="return confirm('Delete this package?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md border border-red-200 px-3 py-1.5 text-red-700">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center">
                            <p class="font-medium">No packages match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Create internet plans with uptime, speed, and optional data caps.</p>
                            <a href="{{ route('admin.packages.create') }}" class="mt-4 inline-flex rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Package</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $packages->links() }}</div>
</x-layouts.admin>
