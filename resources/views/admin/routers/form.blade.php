<x-layouts.admin
    :title="$router->exists ? 'Edit Router' : 'Add Router'"
    :heading="$router->exists ? 'Edit Router' : 'Add Router'"
    subheading="Router saves are mirrored into the FreeRADIUS nas table."
>
    <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
        <form method="POST" action="{{ $router->exists ? route('admin.routers.update', $router) : route('admin.routers.store') }}" class="rounded-lg border border-zinc-200 bg-white p-6">
            @csrf
            @if ($router->exists)
                @method('PUT')
            @endif

            <div class="grid gap-5 md:grid-cols-2">
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium">Shop</span>
                    <select name="shop_id" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                        <option value="">Select shop</option>
                        @foreach ($shops as $shop)
                            <option value="{{ $shop->id }}" @selected(old('shop_id', $router->shop_id) == $shop->id)>{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs text-zinc-500">Create a tenant and shop first, then attach each MikroTik router to its shop.</span>
                    @error('shop_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Router name</span>
                    <input name="name" value="{{ old('name', $router->name) }}" placeholder="Main Shop Router" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    <span class="mt-1 block text-xs text-zinc-500">Dashboard label only. Example: Main Shop Router.</span>
                    @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">NAS identifier</span>
                    <input name="nas_identifier" value="{{ old('nas_identifier', $router->nas_identifier) }}" placeholder="lagos-shop-01" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    <span class="mt-1 block text-xs text-zinc-500">Unique RouterOS identity. The generated script sets this with <code>/system identity</code>.</span>
                    @error('nas_identifier') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">WireGuard internal IP</span>
                    <input name="wireguard_internal_ip" value="{{ old('wireguard_internal_ip', $router->wireguard_internal_ip) }}" placeholder="10.8.0.10" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (['10.8.0.10', '10.8.0.11', '10.8.0.12', '10.8.0.13'] as $ip)
                            <button type="button" data-set-field="wireguard_internal_ip" data-set-value="{{ $ip }}" class="rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs text-zinc-700 hover:bg-zinc-50">{{ $ip }}</button>
                        @endforeach
                    </div>
                    <span class="mt-1 block text-xs text-zinc-500">Private VPN IP for this router. Keep <code>10.8.0.1</code> for the server, then use <code>10.8.0.10</code>, <code>10.8.0.11</code>, and so on.</span>
                    @error('wireguard_internal_ip') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">RADIUS shared secret</span>
                    <input name="shared_secret" value="{{ old('shared_secret') }}" placeholder="{{ $router->exists ? 'Leave blank to keep current value' : 'QF9mX7vC2pL8nR4sT6wY1zA5' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" @required(! $router->exists)>
                    <span class="mt-1 block text-xs text-zinc-500">Random password shared by MikroTik and FreeRADIUS. Use a different secret per router.</span>
                    @error('shared_secret') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_online" value="1" @checked(old('is_online', $router->is_online ?? false)) class="rounded border-zinc-300">
                    Online
                </label>
            </div>

            <div class="mt-6 flex gap-3">
                <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Router</button>
                <a href="{{ route('admin.routers.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
            </div>
        </form>

        <aside class="rounded-lg border border-zinc-200 bg-white p-5">
            <h2 class="text-base font-semibold">Field Guide</h2>
            <div class="mt-4 space-y-4 text-sm text-zinc-600">
                <p><strong class="text-zinc-900">NAS identifier:</strong> the MikroTik system identity. Example: <code>lagos-shop-01</code>.</p>
                <p><strong class="text-zinc-900">WireGuard IP:</strong> the router's private VPN address. First router can be <code>10.8.0.10</code>.</p>
                <p><strong class="text-zinc-900">Shared secret:</strong> generate a strong random value and keep it private.</p>
                <p class="rounded-md bg-zinc-50 p-3 font-mono text-xs text-zinc-800">openssl rand -base64 24</p>
                <p>After saving, open the router's Script page and paste the generated commands into MikroTik RouterOS terminal.</p>
            </div>
        </aside>
    </div>

    <script>
        document.querySelectorAll('[data-set-field]').forEach((button) => {
            button.addEventListener('click', () => {
                const field = document.querySelector(`[name="${button.dataset.setField}"]`);

                if (field) {
                    field.value = button.dataset.setValue;
                }
            });
        });
    </script>
</x-layouts.admin>
