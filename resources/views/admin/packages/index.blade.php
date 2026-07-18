<x-layouts.admin title="Packages" heading="Packages" subheading="Internet plans synced as RADIUS group profiles.">
    <x-slot:action>
        <a href="{{ route('admin.packages.create') }}" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Package</a>
    </x-slot:action>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
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
                        <td class="px-4 py-3 font-medium">{{ $package->name }}</td>
                        <td class="px-4 py-3">{{ $package->shop->name }} / {{ $package->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3">{{ $package->radius_group_name ?: 'Pending sync' }}</td>
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
                        <td class="px-4 py-3">{{ $package->speed_limit_profile }}</td>
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
                    <tr><td colspan="8" class="px-4 py-8 text-center text-zinc-500">No packages yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $packages->links() }}</div>
</x-layouts.admin>
