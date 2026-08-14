<x-layouts.admin
    :title="$router->name"
    :heading="$router->name"
    subheading="Guided MikroTik RouterOS onboarding for this NAS."
>
    <x-slot:action>
        <a href="{{ route('admin.routers.edit', $router) }}" wire:navigate class="rounded-md border border-zinc-200 dark:border-zinc-700 px-4 py-2 text-sm">Edit Router</a>
    </x-slot:action>

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,340px)_minmax(0,1fr)]">
        <aside class="min-w-0 space-y-4">
            <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                <h2 class="text-base font-semibold">Router Details</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Shop</dt>
                        <dd class="font-medium">{{ $router->shop->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Tenant</dt>
                        <dd class="font-medium">{{ $router->shop->tenant->company_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">NAS identifier</dt>
                        <dd class="font-mono">{{ $router->nas_identifier }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">WireGuard IP</dt>
                        <dd class="font-mono">{{ $router->wireguard_internal_ip }}</dd>
                    </div>
                    <livewire:admin.router-credentials-card :router="$router" :key="'credentials-'.$router->id" />
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">RADIUS status</dt>
                        <dd class="font-medium">Synced to nas on save</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                <h2 class="text-base font-semibold">Config In Use</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Portal URL</dt>
                        <dd class="break-all font-medium">{{ $provisioningConfig['portal_url'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">RADIUS server</dt>
                        <dd class="font-mono">{{ $provisioningConfig['radius_server_ip'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">WireGuard endpoint</dt>
                        <dd class="font-mono">{{ $provisioningConfig['wireguard_endpoint_host'] }}:{{ $provisioningConfig['wireguard_endpoint_port'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Hotspot DNS name</dt>
                        <dd class="font-mono">{{ $provisioningConfig['hotspot_dns_name'] }}</dd>
                    </div>
                </dl>
            </section>

        </aside>

        <div class="min-w-0">
            <flux:tab.group>
                <flux:tabs variant="segmented" scrollable>
                    <flux:tab name="overview" icon="chart-bar">Overview</flux:tab>
                    <flux:tab name="fresh-infra" icon="sparkles">Fresh Infrastructure Script</flux:tab>
                    <flux:tab name="hotspot" icon="wifi">Hotspot Script</flux:tab>
                    <flux:tab name="pppoe" icon="signal">PPPoE Script</flux:tab>
                    <flux:tab name="ap-guide" icon="book-open">AP / SSID Guide</flux:tab>
                    <flux:tab name="live" icon="bolt">Live</flux:tab>
                    <flux:tab name="insight" icon="cpu-chip">Insight</flux:tab>
                </flux:tabs>

                <flux:tab.panel name="overview" class="space-y-6">
                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                <div>
                                    <h2 class="text-base font-semibold">Bootstrap Script</h2>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">The one script to paste by hand on a brand-new router: identity, WireGuard, and the RouterOS API user. Once it's applied and the router is reachable, push the rest of the hotspot or PPPoE config from the Hotspot Script / PPPoE Script tabs with one click &mdash; no more pasting required.</p>
                                </div>
                                <flux:badge color="blue">Paste this first</flux:badge>
                            </div>
                        </div>
                        <x-script-block>{{ $bootstrapScript }}</x-script-block>
                    </section>

                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                            <h2 class="text-base font-semibold">Usage History</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Daily download/upload from FreeRADIUS accounting, last 30 days. A session's data is counted on the day it started.</p>
                        </div>
                        <div class="p-5">
                            @if ($hasUsageHistory)
                                <flux:chart :value="$dailyUsage" class="aspect-[3/1]">
                                    <flux:chart.svg>
                                        <flux:chart.line field="download_gb" class="text-blue-500" />
                                        <flux:chart.line field="upload_gb" class="text-violet-500" />

                                        <flux:chart.axis axis="x" field="date">
                                            <flux:chart.axis.tick />
                                            <flux:chart.axis.line />
                                        </flux:chart.axis>
                                        <flux:chart.axis axis="y">
                                            <flux:chart.axis.grid />
                                            <flux:chart.axis.tick />
                                        </flux:chart.axis>
                                    </flux:chart.svg>

                                    <flux:chart.tooltip>
                                        <flux:chart.tooltip.heading field="date" />
                                        <flux:chart.tooltip.value field="download_gb" label="Download" suffix=" GB" />
                                        <flux:chart.tooltip.value field="upload_gb" label="Upload" suffix=" GB" />
                                    </flux:chart.tooltip>
                                </flux:chart>

                                <div class="mt-3 flex justify-center gap-4">
                                    <flux:chart.legend label="Download">
                                        <flux:chart.legend.indicator class="bg-blue-500" />
                                    </flux:chart.legend>
                                    <flux:chart.legend label="Upload">
                                        <flux:chart.legend.indicator class="bg-violet-500" />
                                    </flux:chart.legend>
                                </div>
                            @else
                                <p class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No accounting data yet for this router in the last 30 days.</p>
                            @endif
                        </div>
                    </section>

                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                        <h2 class="text-base font-semibold">First-Setup Test Order</h2>
                        <ol class="mt-4 space-y-3 text-sm text-zinc-600 dark:text-zinc-400">
                            <li><span class="font-medium text-zinc-950 dark:text-zinc-100">1.</span> Paste the RouterOS script.</li>
                            <li><span class="font-medium text-zinc-950 dark:text-zinc-100">2.</span> Upload the generated <code>login.html</code> to the MikroTik hotspot files.</li>
                            <li><span class="font-medium text-zinc-950 dark:text-zinc-100">3.</span> On the Pi, run <code>sudo freeradius -X</code> while testing.</li>
                            <li><span class="font-medium text-zinc-950 dark:text-zinc-100">4.</span> Clear the phone from MikroTik active/host lists before retesting.</li>
                        </ol>
                    </section>

                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                        <h2 class="text-base font-semibold">Retest Commands</h2>
                        <div class="mt-4 grid min-w-0 gap-4 md:grid-cols-2">
                            <div class="min-w-0 rounded-md bg-zinc-50 dark:bg-zinc-800 p-4">
                                <p class="text-sm font-medium">MikroTik</p>
                                <pre class="mt-2 block max-w-full overflow-x-auto text-xs leading-5 text-zinc-800 dark:text-zinc-200"><code class="block min-w-max">/ip hotspot active remove [find mac-address="AA:BB:CC:DD:EE:FF"]
/ip hotspot host remove [find mac-address="AA:BB:CC:DD:EE:FF"]</code></pre>
                            </div>
                            <div class="min-w-0 rounded-md bg-zinc-50 dark:bg-zinc-800 p-4">
                                <p class="text-sm font-medium">Pi</p>
                                <pre class="mt-2 block max-w-full overflow-x-auto text-xs leading-5 text-zinc-800 dark:text-zinc-200"><code class="block min-w-max">sudo systemctl stop freeradius
sudo freeradius -X</code></pre>
                            </div>
                        </div>
                    </section>
                </flux:tab.panel>

                <flux:tab.panel name="fresh-infra" class="space-y-6">
                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                <div>
                                    <h2 class="text-base font-semibold">Fresh MikroTik Infrastructure Script</h2>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Use this for new/no-default-config routers. It prepares VLANs, Starlink-friendly PCQ queues, POS access, PPPoE, hotspot, WireGuard, and RADIUS.</p>
                                </div>
                                <flux:badge color="amber">Review variables first</flux:badge>
                            </div>
                        </div>
                        <x-script-block>{{ $freshInfrastructureScript }}</x-script-block>
                    </section>

                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                        <h2 class="text-base font-semibold">Infrastructure Profiles</h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($infrastructureProfiles as $key => $profile)
                                <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="text-sm font-medium">{{ $profile['name'] }}</p>
                                        @if ($key === 'starlink_plaza')
                                            <flux:badge color="blue">Default</flux:badge>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">{{ $profile['summary'] }}</p>
                                    <p class="mt-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ $profile['capacity'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                </flux:tab.panel>

                <flux:tab.panel name="hotspot" class="space-y-6">
                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                <div>
                                    <h2 class="text-base font-semibold">RouterOS Script</h2>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Paste this into MikroTik RouterOS terminal after confirming the config values, or apply it live over the API once the Bootstrap Script (Overview tab) has run.</p>
                                </div>
                                @if ($router->api_username)
                                    <form method="POST" action="{{ route('admin.routers.provision-hotspot', $router) }}" onsubmit="return confirm('This pushes the RADIUS client, hotspot profile, walled-garden entries, and the hotspot login page to the router live over the API. Continue?');">
                                        @csrf
                                        <button type="submit" class="rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium text-blue-600 hover:bg-zinc-50 dark:hover:bg-zinc-800">Provision via API</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <x-script-block>{{ $script }}</x-script-block>
                    </section>

                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                            <h2 class="text-base font-semibold">MikroTik login.html</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Upload this file to the MikroTik hotspot folder so phones redirect to the package picker.</p>
                        </div>
                        <x-script-block>{{ $loginTemplate }}</x-script-block>
                    </section>
                </flux:tab.panel>

                <flux:tab.panel name="pppoe" class="space-y-6">
                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                <div>
                                    <h2 class="text-base font-semibold">RouterOS PPPoE Script</h2>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Use this when this router will serve PPPoE subscribers instead of, or alongside, hotspot users. Package bandwidth is applied by RADIUS, so the router profile should remain generic. Or apply it live over the API once the Bootstrap Script (Overview tab) has run.</p>
                                </div>
                                @if ($router->api_username)
                                    <form method="POST" action="{{ route('admin.routers.provision-pppoe', $router) }}" onsubmit="return confirm('This pushes the RADIUS client, PPP/RADIUS settings, profile, and PPPoE server to the router live over the API. Continue?');">
                                        @csrf
                                        <button type="submit" class="rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium text-blue-600 hover:bg-zinc-50 dark:hover:bg-zinc-800">Provision via API</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <x-script-block>{{ $pppoeScript }}</x-script-block>
                    </section>

                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                        <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                            <div>
                                <h2 class="text-base font-semibold">PPPoE Bandwidth Check</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">After a customer connects, confirm MikroTik received the RADIUS package speed and accounting is updating.</p>
                            </div>
                            <flux:badge color="blue">Mikrotik-Rate-Limit</flux:badge>
                        </div>

                        <div class="mt-4 grid min-w-0 gap-4 md:grid-cols-2">
                            <div class="min-w-0 rounded-md bg-zinc-50 dark:bg-zinc-800 p-4">
                                <p class="text-sm font-medium">Active PPPoE user</p>
                                <pre class="mt-2 block max-w-full overflow-x-auto text-xs leading-5 text-zinc-800 dark:text-zinc-200"><code class="block min-w-max">/ppp active print detail where name="customer001"</code></pre>
                                <p class="mt-2 text-xs leading-5 text-zinc-500 dark:text-zinc-400">Look for the customer username, caller ID, uptime, and assigned address.</p>
                            </div>
                            <div class="min-w-0 rounded-md bg-zinc-50 dark:bg-zinc-800 p-4">
                                <p class="text-sm font-medium">Dynamic bandwidth queue</p>
                                <pre class="mt-2 block max-w-full overflow-x-auto text-xs leading-5 text-zinc-800 dark:text-zinc-200"><code class="block min-w-max">/queue simple print where dynamic=yes</code></pre>
                                <p class="mt-2 text-xs leading-5 text-zinc-500 dark:text-zinc-400">The dynamic queue should reflect the selected package bandwidth, for example 5M/10M.</p>
                            </div>
                        </div>
                    </section>

                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                        <h2 class="text-base font-semibold">PPPoE Notes</h2>
                        <ul class="mt-4 space-y-3 text-sm text-zinc-600 dark:text-zinc-400">
                            <li>Use PPPoE for fixed subscribers with username/password credentials.</li>
                            <li>Change <code>interface=bridge1</code> in the script to the subscriber VLAN or LAN bridge.</li>
                            <li>Set bandwidth on the package in MMS Radius. FreeRADIUS sends it to MikroTik as <code>Mikrotik-Rate-Limit</code>.</li>
                            <li>Customer CPE WAN mode should be PPPoE client.</li>
                        </ul>
                    </section>
                </flux:tab.panel>

                <flux:tab.panel name="ap-guide">
                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                            <h2 class="text-base font-semibold">AP / SSID / VLAN Guide</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Use this when configuring Ruijie, Omada, Wavlink, or any tenant access point that supports multiple SSIDs.</p>
                        </div>
                        <x-script-block>{{ $accessPointGuide }}</x-script-block>
                    </section>
                </flux:tab.panel>

                <flux:tab.panel name="live" class="space-y-6">
                    @if ($router->api_username)
                        <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                            <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                                <h2 class="text-base font-semibold">Live Bandwidth</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Polls the RouterOS API every 5 seconds. Requires the script's RouterOS API section to be applied on the physical router.</p>
                            </div>
                            <div class="p-5">
                                <livewire:admin.router-live-monitor :router="$router" :key="'live-'.$router->id" />
                            </div>
                        </section>

                        <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                            <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                                <h2 class="text-base font-semibold">Wi-Fi Scan</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">On-demand scan of nearby Wi-Fi networks from this router's radio.</p>
                            </div>
                            <div class="p-5">
                                <livewire:admin.router-wifi-scan :router="$router" :key="'wifi-scan-'.$router->id" />
                            </div>
                        </section>

                        <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                            <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                                <h2 class="text-base font-semibold">Connected Devices</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Live DHCP leases per network -- works regardless of which access point (built-in Wi-Fi or an external AP like Ruijie) the client associated to.</p>
                            </div>
                            <div class="p-5">
                                <livewire:admin.router-connected-devices :router="$router" :key="'connected-devices-'.$router->id" />
                            </div>
                        </section>

                        <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                            <div class="border-b border-zinc-200 dark:border-zinc-700 px-5 py-4">
                                <h2 class="text-base font-semibold">Console</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Read-only RouterOS terminal &mdash; run any <code>print</code> command against this router over the API.</p>
                            </div>
                            <div class="p-5">
                                <livewire:admin.router-console :router="$router" :key="'console-'.$router->id" />
                            </div>
                        </section>
                    @else
                        <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Live bandwidth and Wi-Fi scanning need RouterOS API credentials first. Generate them from the Router Details card, then re-run the script on this router.</p>
                        </section>
                    @endif
                </flux:tab.panel>

                <flux:tab.panel name="insight" class="space-y-6">
                    <section class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                        <livewire:admin.router-insight :router="$router" :key="'insight-'.$router->id" />
                    </section>
                </flux:tab.panel>
            </flux:tab.group>
        </div>
    </div>
</x-layouts.admin>
