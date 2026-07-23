<x-layouts.admin
    :title="$router->name"
    :heading="$router->name"
    subheading="Guided MikroTik RouterOS onboarding for this NAS."
>
    <x-slot:action>
        <a href="{{ route('admin.routers.edit', $router) }}" wire:navigate class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Edit Router</a>
    </x-slot:action>

    <div class="grid gap-6 xl:grid-cols-[340px_1fr]">
        <aside class="space-y-4">
            <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
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
                        <dd class="font-mono">{{ $router->nas_identifier }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">WireGuard IP</dt>
                        <dd class="font-mono">{{ $router->wireguard_internal_ip }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">RADIUS status</dt>
                        <dd class="font-medium">Synced to nas on save</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold">Config In Use</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Portal URL</dt>
                        <dd class="break-all font-medium">{{ $provisioningConfig['portal_url'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">RADIUS server</dt>
                        <dd class="font-mono">{{ $provisioningConfig['radius_server_ip'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">WireGuard endpoint</dt>
                        <dd class="font-mono">{{ $provisioningConfig['wireguard_endpoint_host'] }}:{{ $provisioningConfig['wireguard_endpoint_port'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Hotspot DNS name</dt>
                        <dd class="font-mono">{{ $provisioningConfig['hotspot_dns_name'] }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold">Test Order</h2>
                <ol class="mt-4 space-y-3 text-sm text-zinc-600">
                    <li><span class="font-medium text-zinc-950">1.</span> Paste the RouterOS script.</li>
                    <li><span class="font-medium text-zinc-950">2.</span> Upload the generated <code>login.html</code> to the MikroTik hotspot files.</li>
                    <li><span class="font-medium text-zinc-950">3.</span> On the Pi, run <code>sudo freeradius -X</code> while testing.</li>
                    <li><span class="font-medium text-zinc-950">4.</span> Clear the phone from MikroTik active/host lists before retesting.</li>
                </ol>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold">PPPoE Notes</h2>
                <ul class="mt-4 space-y-3 text-sm text-zinc-600">
                    <li>Use PPPoE for fixed subscribers with username/password credentials.</li>
                    <li>Change <code>interface=bridge1</code> in the script to the subscriber VLAN or LAN bridge.</li>
                    <li>Customer CPE WAN mode should be PPPoE client.</li>
                </ul>
            </section>
        </aside>

        <div class="space-y-6">
            <section class="rounded-lg border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-5 py-4">
                    <h2 class="text-base font-semibold">RouterOS Script</h2>
                    <p class="mt-1 text-sm text-zinc-500">Paste this into MikroTik RouterOS terminal after confirming the config values.</p>
                </div>
                <pre class="overflow-x-auto p-5 text-sm leading-6 text-zinc-900"><code>{{ $script }}</code></pre>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-5 py-4">
                    <h2 class="text-base font-semibold">RouterOS PPPoE Script</h2>
                    <p class="mt-1 text-sm text-zinc-500">Use this when this router will serve PPPoE subscribers instead of, or alongside, hotspot users.</p>
                </div>
                <pre class="overflow-x-auto p-5 text-sm leading-6 text-zinc-900"><code>{{ $pppoeScript }}</code></pre>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-5 py-4">
                    <h2 class="text-base font-semibold">MikroTik login.html</h2>
                    <p class="mt-1 text-sm text-zinc-500">Upload this file to the MikroTik hotspot folder so phones redirect to the package picker.</p>
                </div>
                <pre class="overflow-x-auto p-5 text-sm leading-6 text-zinc-900"><code>{{ $loginTemplate }}</code></pre>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold">Retest Commands</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="rounded-md bg-zinc-50 p-4">
                        <p class="text-sm font-medium">MikroTik</p>
                        <pre class="mt-2 overflow-x-auto text-xs leading-5 text-zinc-800"><code>/ip hotspot active remove [find mac-address="AA:BB:CC:DD:EE:FF"]
/ip hotspot host remove [find mac-address="AA:BB:CC:DD:EE:FF"]</code></pre>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-4">
                        <p class="text-sm font-medium">Pi</p>
                        <pre class="mt-2 overflow-x-auto text-xs leading-5 text-zinc-800"><code>sudo systemctl stop freeradius
sudo freeradius -X</code></pre>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts.admin>
