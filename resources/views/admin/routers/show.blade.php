<x-layouts.admin
    :title="$router->name"
    :heading="$router->name"
    subheading="MikroTik RouterOS provisioning script for this NAS."
>
    <x-slot:action>
        <a href="{{ route('admin.routers.edit', $router) }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Edit Router</a>
    </x-slot:action>

    <div class="grid gap-6 xl:grid-cols-[320px_1fr]">
        <section class="rounded-lg border border-zinc-200 bg-white p-5">
            <h2 class="text-base font-semibold">Router Details</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Shop</dt>
                    <dd class="font-medium">{{ $router->shop->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Tenant</dt>
                    <dd class="font-medium">{{ $router->shop->tenant->company_name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">NAS identifier</dt>
                    <dd class="font-medium">{{ $router->nas_identifier }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">WireGuard IP</dt>
                    <dd class="font-medium">{{ $router->wireguard_internal_ip }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">RADIUS status</dt>
                    <dd class="font-medium">Synced to nas on save</dd>
                </div>
            </dl>

            <div class="mt-6 rounded-md bg-zinc-50 p-4 text-sm text-zinc-700">
                <p class="font-medium text-zinc-950">Before pasting</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Confirm the server WireGuard public key in `.env`.</li>
                    <li>Confirm `RADIUS_SERVER_IP` points to FreeRADIUS.</li>
                    <li>Keep this router's shared secret private.</li>
                    <li>Use FreeRADIUS debug mode for the first test.</li>
                </ul>
            </div>
        </section>

        <section class="rounded-lg border border-zinc-200 bg-white">
            <div class="border-b border-zinc-200 px-5 py-4">
                <h2 class="text-base font-semibold">RouterOS Script</h2>
            </div>
            <pre class="overflow-x-auto p-5 text-sm leading-6 text-zinc-900"><code>{{ $script }}</code></pre>
        </section>
    </div>
</x-layouts.admin>
