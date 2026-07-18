<x-layouts.admin title="Routers" heading="Routers" subheading="MikroTik NAS devices synced into FreeRADIUS.">
    <x-slot:action>
        <a href="{{ route('admin.routers.create') }}" class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Add Router</a>
    </x-slot:action>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
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
                        <td class="px-4 py-3 font-medium">{{ $router->name }}</td>
                        <td class="px-4 py-3">{{ $router->shop->name }} / {{ $router->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3">{{ $router->nas_identifier }}</td>
                        <td class="px-4 py-3">{{ $router->wireguard_internal_ip }}</td>
                        <td class="px-4 py-3">{{ $router->is_online ? 'Online' : 'Offline' }}</td>
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
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No routers yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $routers->links() }}</div>
</x-layouts.admin>
