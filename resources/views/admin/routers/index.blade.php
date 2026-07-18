<x-layouts.admin title="Routers" heading="Routers" subheading="MikroTik NAS devices synced into FreeRADIUS.">
    <x-slot:action>
        <a href="{{ route('admin.routers.create') }}" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Router</a>
    </x-slot:action>

    <form method="GET" class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 md:grid-cols-[1fr_180px_auto]">
            <input name="search" value="{{ request('search') }}" placeholder="Search router, NAS ID, WireGuard IP, or shop" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <select name="status" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
                <option value="">All statuses</option>
                <option value="online" @selected(request('status') === 'online')>Online</option>
                <option value="offline" @selected(request('status') === 'offline')>Offline</option>
            </select>
            <div class="flex gap-2">
                <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Filter</button>
                <a href="{{ route('admin.routers.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Reset</a>
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Router</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">NAS ID</th>
                    <th class="px-4 py-3 font-medium">WireGuard IP</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($routers as $router)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $router->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Last seen {{ $router->last_seen_at?->diffForHumans() ?? 'never' }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $router->shop->name }} / {{ $router->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $router->nas_identifier }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $router->wireguard_internal_ip }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $router->is_online ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600' }}">
                                {{ $router->is_online ? 'Online' : 'Offline' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.routers.show', $router) }}" class="rounded-md border border-zinc-200 px-3 py-1.5">Script</a>
                                <a href="{{ route('admin.routers.edit', $router) }}" class="rounded-md border border-zinc-200 px-3 py-1.5">Edit</a>
                                <form method="POST" action="{{ route('admin.routers.destroy', $router) }}" onsubmit="return confirm('Delete this router?')">
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
                            <p class="font-medium">No routers match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Register the MikroTik router that will redirect customers to the captive portal.</p>
                            <a href="{{ route('admin.routers.create') }}" class="mt-4 inline-flex rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Router</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $routers->links() }}</div>
</x-layouts.admin>
