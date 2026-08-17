<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            Add Router
        </flux:button>
    </div>

    <section class="mb-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4 shadow-sm">
        <div class="grid min-w-0 gap-3 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))] [&>*]:min-w-0">
            <div class="sm:col-span-2 xl:col-span-3">
                <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search router, NAS ID, WireGuard IP, or shop" />
            </div>
            <flux:select wire:model.live="status">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="online">Active/recent</flux:select.option>
                <flux:select.option value="offline">No recent accounting</flux:select.option>
            </flux:select>
            <flux:button type="button" variant="outline" icon="x-mark" class="w-full" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status">
                Reset
            </flux:button>
        </div>
    </section>

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
        <table class="min-w-[780px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Router</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">NAS ID</th>
                    <th class="px-4 py-3 font-medium">WireGuard IP</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($routers as $router)
                    <tr wire:key="router-{{ $router->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $router->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Last seen {{ $router->last_seen_at?->diffForHumans() ?? 'never' }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $router->shop->name }} / {{ $router->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $router->nas_identifier }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $router->wireguard_internal_ip }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$router->is_online ? 'green' : 'zinc'">{{ $router->is_online ? 'Active/recent' : 'No recent accounting' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('admin.routers.show', $router) }}" wire:navigate variant="outline" size="sm" icon="command-line">Script</flux:button>
                                <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $router->id }})" wire:loading.attr="disabled" wire:target="edit({{ $router->id }})">Edit</flux:button>
                                <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $router->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $router->id }})">Delete</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center">
                            <p class="font-medium">No routers match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Register the MikroTik router that will redirect customers to the captive portal.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add Router</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $routers->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingRouterId ? 'Edit Router' : 'Add Router' }}</flux:heading>
                <flux:text class="mt-2">Router saves are mirrored into the FreeRADIUS nas table.</flux:text>
            </div>

            @include('admin.partials.billing-usage', ['usage' => $billingUsage])

            <div class="flex items-center gap-2 text-sm">
                @foreach (['Identity', 'Hardware', 'Features', 'Network plan'] as $index => $label)
                    @php($stepNumber = $index + 1)
                    <div class="flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold {{ $step >= $stepNumber ? 'bg-zinc-950 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400' }}">{{ $stepNumber }}</span>
                        <span class="hidden text-xs font-medium {{ $step === $stepNumber ? 'text-zinc-950 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400' }} md:inline">{{ $label }}</span>
                    </div>
                    @if (! $loop->last)
                        <span class="h-px w-6 bg-zinc-200 dark:bg-zinc-700"></span>
                    @endif
                @endforeach
            </div>

            <form wire:submit.prevent="save" class="space-y-5">
                @if ($step === 1)
                    <div class="grid gap-5 md:grid-cols-2">
                        <flux:field class="md:col-span-2">
                            <flux:label>Shop</flux:label>
                            <flux:select wire:model.live="shop_id" required>
                                <option value="">Select shop</option>
                                @foreach ($shops as $shop)
                                    <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:description>Create a tenant and shop first, then attach each MikroTik router to its shop. Selecting a shop fills in a suggested NAS identifier below.</flux:description>
                            <flux:error name="shop_id" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Router name</flux:label>
                            <flux:input wire:model.blur="name" icon="signal" placeholder="Main Shop Router" required />
                            <flux:description>Dashboard label only. Example: Main Shop Router.</flux:description>
                            <flux:error name="name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>NAS identifier</flux:label>
                            <flux:input wire:model.blur="nas_identifier" icon="finger-print" placeholder="lagos-shop-01" required />
                            <flux:description>Unique RouterOS identity. The generated script sets this with <code>/system identity</code>. Auto-filled from the shop name; edit if you want something else.</flux:description>
                            <flux:error name="nas_identifier" />
                        </flux:field>

                        <flux:field>
                            <flux:label>WireGuard internal IP</flux:label>
                            <flux:input wire:model.blur="wireguard_internal_ip" icon="globe-alt" placeholder="10.8.0.10" required />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <flux:button type="button" size="xs" wire:click="suggestWireguardIp">Suggest next available IP</flux:button>
                            </div>
                            <flux:description>Private VPN IP for this router. Pre-filled with the next unused address after <code>10.8.0.1</code> (the server).</flux:description>
                            <flux:error name="wireguard_internal_ip" />
                        </flux:field>

                        <flux:field class="md:col-span-2">
                            <flux:label>WireGuard endpoint override (optional)</flux:label>
                            <div class="grid gap-3 sm:grid-cols-[2fr_1fr]">
                                <flux:input wire:model.blur="wireguard_endpoint_override_host" icon="map-pin" placeholder="Leave blank to use the default public endpoint" />
                                <flux:input wire:model.blur="wireguard_endpoint_override_port" type="number" min="1" max="65535" placeholder="13231" />
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <flux:button type="button" size="xs" wire:click="useLocalWireguardEndpoint">
                                    Use Pi LAN endpoint{{ $localWireguardEndpointHost ? ': '.$localWireguardEndpointHost : '' }}
                                </flux:button>
                                <flux:button type="button" size="xs" variant="ghost" wire:click="clearWireguardEndpointOverride">
                                    Use public endpoint
                                </flux:button>
                            </div>
                            <flux:description>For a router on the same LAN as the Pi, click <strong>Use Pi LAN endpoint</strong>. For a remote router, leave this blank so the script uses <code>{{ $defaultWireguardEndpointHost }}:{{ $defaultWireguardEndpointPort }}</code>. If the Pi LAN IP changes, update <code>WIREGUARD_LOCAL_ENDPOINT_HOST</code> in the Pi <code>.env</code>, clear config cache, then regenerate the script.</flux:description>
                            <flux:error name="wireguard_endpoint_override_host" />
                            <flux:error name="wireguard_endpoint_override_port" />
                        </flux:field>

                        <flux:field class="md:col-span-2">
                            <flux:label>Tunnel mode</flux:label>
                            <flux:select wire:model.live="tunnel_mode">
                                <flux:select.option value="wireguard">WireGuard only</flux:select.option>
                                <flux:select.option value="wireguard_zerotier">WireGuard + ZeroTier fallback</flux:select.option>
                                <flux:select.option value="zerotier">ZeroTier only</flux:select.option>
                            </flux:select>
                            <flux:description>Use ZeroTier when this router's site is behind carrier-grade NAT (common on Starlink/mobile), where WireGuard can never form a tunnel no matter how port forwarding is configured. See <code>docs/zerotier-fallback-setup.md</code>.</flux:description>
                            <flux:error name="tunnel_mode" />
                        </flux:field>

                        @if ($tunnel_mode !== 'wireguard')
                            <flux:field>
                                <flux:label>ZeroTier IP</flux:label>
                                <flux:input wire:model.blur="zerotier_ip" icon="globe-alt" placeholder="{{ $zerotierIpPrefix }}.10" />
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <flux:button type="button" size="xs" wire:click="suggestZeroTierIp">Suggest next available IP</flux:button>
                                </div>
                                <flux:description>This router's fixed address on the ZeroTier network, pushed as its authorized IP assignment.</flux:description>
                                <flux:error name="zerotier_ip" />
                            </flux:field>

                            <flux:field>
                                <flux:label>ZeroTier node ID</flux:label>
                                <flux:input wire:model.blur="zerotier_node_id" icon="finger-print" placeholder="e.g. abcd123456" maxlength="16" />
                                @if ($tunnel_mode === 'wireguard_zerotier')
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <flux:button type="button" size="xs" wire:click="fetchZeroTierNodeId">Fetch over WireGuard</flux:button>
                                    </div>
                                    <flux:description>RouterOS generates this itself, the first time <code>/zerotier enable</code> runs on the router -- MMS Radius can't predict it in advance the way it does WireGuard keys. Since this router still has WireGuard, click <strong>Fetch over WireGuard</strong> to read it automatically, or run <code>/zerotier print</code> on the router and paste it here.</flux:description>
                                @else
                                    <flux:description>This router has no working WireGuard link, so its ZeroTier node ID can't be fetched automatically -- run <code>/zerotier print</code> on the router console after it joins the network, then paste the result here.</flux:description>
                                @endif
                                <flux:error name="zerotier_node_id" />
                            </flux:field>
                        @endif

                        <flux:field>
                            <flux:label>RADIUS shared secret</flux:label>
                            <flux:input wire:model.blur="shared_secret" icon="key" placeholder="{{ $editingRouterId ? 'Leave blank to keep current value' : 'QF9mX7vC2pL8nR4sT6wY1zA5' }}" viewable />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <flux:button type="button" size="xs" wire:click="regenerateSharedSecret">Generate new</flux:button>
                            </div>
                            <flux:description>Random password shared by MikroTik and FreeRADIUS. Pre-filled with a strong random value; use a different secret per router.</flux:description>
                            <flux:error name="shared_secret" />
                        </flux:field>

                        @if ($editingRouterId && $wireguard_public_key)
                            <flux:field class="md:col-span-2">
                                <flux:label>WireGuard public key</flux:label>
                                <flux:input value="{{ $wireguard_public_key }}" icon="key" readonly copyable />
                                <flux:description>Generated by MMS Radius and baked into this router's script. The scheduled WireGuard sync uses this to register the router as a peer on the Pi automatically &mdash; no manual copying needed.</flux:description>
                            </flux:field>
                        @endif
                    </div>

                    <div class="flex justify-end">
                        <flux:button type="button" variant="primary" wire:click="nextStep">Next: Hardware</flux:button>
                    </div>
                @endif

                @if ($step === 2)
                    <div class="space-y-5">
                        <flux:field>
                            <flux:checkbox wire:model.live="provisioning_settings.enable_builtin_wifi" label="This router has built-in Wi-Fi" />
                            <flux:description>Turn on to generate an open hotspot SSID (plus optional staff/POS/management SSIDs) directly from the router's own radio. Leave off for wired-only routers relying on external access points.</flux:description>
                        </flux:field>

                        <div class="grid gap-5 md:grid-cols-2">
                            <flux:field>
                                <flux:label>Total Ethernet ports</flux:label>
                                <flux:input type="number" min="2" wire:model.live="provisioning_settings.port_count" placeholder="8" :disabled="$provisioning_settings['ports_advanced_mode'] ?? false" />
                                <flux:description>e.g. 5, 8, or more &mdash; used to build the port pickers below.</flux:description>
                                <flux:error name="provisioning_settings.port_count" />
                            </flux:field>

                            <flux:field>
                                <flux:checkbox wire:model.live="provisioning_settings.enable_second_wan" label="Second WAN/Starlink uplink" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:checkbox wire:model.live="provisioning_settings.ports_advanced_mode" label="Advanced: type interface names manually" />
                            <flux:description>Turn on for non-standard interfaces (e.g. <code>sfp-sfpplus1</code>) instead of picking a port number.</flux:description>
                        </flux:field>

                        @if ($provisioning_settings['ports_advanced_mode'] ?? false)
                            <div class="grid gap-4 md:grid-cols-2">
                                <flux:field>
                                    <flux:label>WAN 1</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.wan1" placeholder="ether1" />
                                    <flux:error name="provisioning_settings.wan1" />
                                </flux:field>

                                @if ($provisioning_settings['enable_second_wan'] ?? false)
                                    <flux:field>
                                        <flux:label>WAN 2</flux:label>
                                        <flux:input wire:model.blur="provisioning_settings.wan2" placeholder="ether8" />
                                        <flux:error name="provisioning_settings.wan2" />
                                    </flux:field>
                                @endif

                                <flux:field>
                                    <flux:label>AP/switch trunk</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.trunk_port" placeholder="ether2" />
                                    <flux:error name="provisioning_settings.trunk_port" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Pi/management port</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.pi_port" placeholder="ether3" />
                                    <flux:error name="provisioning_settings.pi_port" />
                                </flux:field>
                            </div>
                        @else
                            @php($portOptions = \App\Support\RouterPortLayout::portOptions((int) ($provisioning_settings['port_count'] ?? 8)))
                            @php($portConflicts = \App\Support\RouterPortLayout::conflictingRoles([
                                'WAN 1' => $provisioning_settings['wan1_port_number'] ?? null,
                                'WAN 2' => ($provisioning_settings['enable_second_wan'] ?? false) ? ($provisioning_settings['wan2_port_number'] ?? null) : null,
                                'Trunk port' => $provisioning_settings['trunk_port_number'] ?? null,
                                'Pi port' => $provisioning_settings['pi_port_number'] ?? null,
                            ]))

                            @if ($portConflicts !== [])
                                <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                    @foreach ($portConflicts as $conflict)
                                        <p>{{ $conflict }}</p>
                                    @endforeach
                                </div>
                            @endif

                            <div class="grid gap-4 md:grid-cols-2">
                                <flux:field>
                                    <flux:label>WAN 1 port</flux:label>
                                    <flux:select wire:model.live="provisioning_settings.wan1_port_number">
                                        @foreach ($portOptions as $number => $label)
                                            <flux:select.option value="{{ $number }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="provisioning_settings.wan1_port_number" />
                                </flux:field>

                                @if ($provisioning_settings['enable_second_wan'] ?? false)
                                    <flux:field>
                                        <flux:label>WAN 2 port</flux:label>
                                        <flux:select wire:model.live="provisioning_settings.wan2_port_number">
                                            @foreach ($portOptions as $number => $label)
                                                <flux:select.option value="{{ $number }}">{{ $label }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:error name="provisioning_settings.wan2_port_number" />
                                    </flux:field>
                                @endif

                                <flux:field>
                                    <flux:label>AP/switch trunk port</flux:label>
                                    <flux:select wire:model.live="provisioning_settings.trunk_port_number">
                                        @foreach ($portOptions as $number => $label)
                                            <flux:select.option value="{{ $number }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="provisioning_settings.trunk_port_number" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Pi/management port</flux:label>
                                    <flux:select wire:model.live="provisioning_settings.pi_port_number">
                                        @foreach ($portOptions as $number => $label)
                                            <flux:select.option value="{{ $number }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="provisioning_settings.pi_port_number" />
                                </flux:field>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-between">
                        <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                        <flux:button type="button" variant="primary" wire:click="nextStep">Next: Features</flux:button>
                    </div>
                @endif

                @if ($step === 3)
                    <div class="space-y-5">
                        <div class="grid gap-3 rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-3 text-sm md:grid-cols-3">
                            <flux:checkbox wire:model.live="provisioning_settings.enable_staff" label="Staff VLAN/SSID" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_pos" label="POS VLAN/SSID" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_mgmt_wifi" label="Management Wi-Fi" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_pppoe" label="PPPoE/CPE VLAN" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_realtime_qos" label="Realtime voice/video QoS" />
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <flux:field>
                                <flux:label>Download limit</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.download_limit" placeholder="120M" />
                                <flux:description>Parent PCQ limit. Example: <code>120M</code>.</flux:description>
                                <flux:error name="provisioning_settings.download_limit" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Upload limit</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.upload_limit" placeholder="20M" />
                                <flux:description>Parent upload limit. Example: <code>20M</code>.</flux:description>
                                <flux:error name="provisioning_settings.upload_limit" />
                            </flux:field>
                        </div>

                        @if ($provisioning_settings['enable_builtin_wifi'] ?? false)
                            <div class="space-y-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4">
                                <p class="text-sm font-semibold text-zinc-950 dark:text-zinc-100">Built-in Wi-Fi credentials</p>

                                <flux:field>
                                    <flux:label>Wireless interface</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.builtin_wifi_interface" placeholder="wifi1" />
                                    <flux:description>Usually <code>wifi1</code> on RouterOS 7 wifiwave2 boards.</flux:description>
                                    <flux:error name="provisioning_settings.builtin_wifi_interface" />
                                </flux:field>

                                @if ($provisioning_settings['enable_mgmt_wifi'] ?? false)
                                    <flux:field>
                                        <flux:label>Management Wi-Fi password</flux:label>
                                        <flux:input wire:model.blur="provisioning_settings.mgmt_wifi_password" viewable />
                                        <flux:description>Optional lab/admin SSID on the management VLAN. The wired Pi/management port is still generated even when this is off.</flux:description>
                                        <flux:error name="provisioning_settings.mgmt_wifi_password" />
                                    </flux:field>
                                @endif

                                @if ($provisioning_settings['enable_staff'] ?? false)
                                    <flux:field>
                                        <flux:label>Staff Wi-Fi password</flux:label>
                                        <flux:input wire:model.blur="provisioning_settings.staff_wifi_password" viewable />
                                        <flux:error name="provisioning_settings.staff_wifi_password" />
                                    </flux:field>
                                @endif

                                @if ($provisioning_settings['enable_pos'] ?? false)
                                    <flux:field>
                                        <flux:label>POS Wi-Fi password</flux:label>
                                        <flux:input wire:model.blur="provisioning_settings.pos_wifi_password" viewable />
                                        <flux:error name="provisioning_settings.pos_wifi_password" />
                                    </flux:field>
                                @endif
                            </div>
                        @else
                            <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-3 text-sm text-zinc-600 dark:text-zinc-400">
                                No built-in Wi-Fi selected. Staff, POS, and management access will need an external access point on the AP/switch trunk port.
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-between">
                        <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                        <flux:button type="button" variant="primary" wire:click="nextStep">Next: Network plan</flux:button>
                    </div>
                @endif

                @if ($step === 4)
                    <div class="space-y-5">
                        <flux:field>
                            <flux:label>Bandwidth &amp; VLAN template</flux:label>
                            <flux:select wire:model.live="provisioning_settings.profile">
                                @foreach ($infrastructureProfiles as $key => $profile)
                                    <flux:select.option value="{{ $key }}">{{ $profile['name'] }} - {{ $profile['capacity'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>Pre-fills bandwidth and VLAN defaults below &mdash; it no longer implies any specific hardware or Wi-Fi capability (set that on the Hardware step). Still editable after picking.</flux:description>
                            <flux:error name="provisioning_settings.profile" />
                        </flux:field>

                        <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-3">
                            <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Quick-fill bandwidth &amp; VLAN template</p>
                            <p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">Use a preset, then adjust any value before saving.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:button type="button" size="xs" wire:click="setRouterLayoutPreset('starlink_plaza')">Starlink plaza /23</flux:button>
                                <flux:button type="button" size="xs" wire:click="setRouterLayoutPreset('small_ap_24')">Small hotspot /24</flux:button>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-5">
                            <flux:field>
                                <flux:label>Mgmt VLAN</flux:label>
                                <flux:input type="number" wire:model.blur="provisioning_settings.mgmt_vlan" placeholder="10" />
                                <flux:error name="provisioning_settings.mgmt_vlan" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Hotspot VLAN</flux:label>
                                <flux:input type="number" wire:model.blur="provisioning_settings.hotspot_vlan" placeholder="20" />
                                <flux:error name="provisioning_settings.hotspot_vlan" />
                            </flux:field>

                            @if ($provisioning_settings['enable_staff'] ?? false)
                                <flux:field>
                                    <flux:label>Staff VLAN</flux:label>
                                    <flux:input type="number" wire:model.blur="provisioning_settings.staff_vlan" placeholder="30" />
                                    <flux:error name="provisioning_settings.staff_vlan" />
                                </flux:field>
                            @endif

                            @if ($provisioning_settings['enable_pppoe'] ?? false)
                                <flux:field>
                                    <flux:label>PPPoE VLAN</flux:label>
                                    <flux:input type="number" wire:model.blur="provisioning_settings.pppoe_vlan" placeholder="40" />
                                    <flux:error name="provisioning_settings.pppoe_vlan" />
                                </flux:field>
                            @endif

                            @if ($provisioning_settings['enable_pos'] ?? false)
                                <flux:field>
                                    <flux:label>POS VLAN</flux:label>
                                    <flux:input type="number" wire:model.blur="provisioning_settings.pos_vlan" placeholder="50" />
                                    <flux:error name="provisioning_settings.pos_vlan" />
                                </flux:field>
                            @endif
                        </div>

                        <div class="grid gap-4 md:grid-cols-3">
                            <flux:field>
                                <flux:label>Management gateway</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.mgmt_gateway" placeholder="192.168.10.1/24" />
                                <flux:error name="provisioning_settings.mgmt_gateway" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Management network</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.mgmt_network" placeholder="192.168.10.0/24" />
                                <flux:error name="provisioning_settings.mgmt_network" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Management DHCP pool</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.mgmt_pool" placeholder="192.168.10.10-192.168.10.250" />
                                <flux:error name="provisioning_settings.mgmt_pool" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Hotspot gateway</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.hotspot_gateway" placeholder="10.5.50.1/23" />
                                <flux:error name="provisioning_settings.hotspot_gateway" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Hotspot network</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.hotspot_network" placeholder="10.5.50.0/23" />
                                <flux:error name="provisioning_settings.hotspot_network" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Hotspot DHCP pool</flux:label>
                                <flux:input wire:model.blur="provisioning_settings.hotspot_pool" placeholder="10.5.50.10-10.5.51.250" />
                                <flux:error name="provisioning_settings.hotspot_pool" />
                            </flux:field>

                            @if ($provisioning_settings['enable_staff'] ?? false)
                                <flux:field>
                                    <flux:label>Staff gateway</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.staff_gateway" placeholder="192.168.30.1/24" />
                                    <flux:error name="provisioning_settings.staff_gateway" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Staff network</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.staff_network" placeholder="192.168.30.0/24" />
                                    <flux:error name="provisioning_settings.staff_network" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Staff DHCP pool</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.staff_pool" placeholder="192.168.30.10-192.168.30.250" />
                                    <flux:error name="provisioning_settings.staff_pool" />
                                </flux:field>
                            @endif

                            @if ($provisioning_settings['enable_pos'] ?? false)
                                <flux:field>
                                    <flux:label>POS gateway</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.pos_gateway" placeholder="192.168.50.1/24" />
                                    <flux:error name="provisioning_settings.pos_gateway" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>POS network</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.pos_network" placeholder="192.168.50.0/24" />
                                    <flux:error name="provisioning_settings.pos_network" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>POS DHCP pool</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.pos_pool" placeholder="192.168.50.10-192.168.50.250" />
                                    <flux:error name="provisioning_settings.pos_pool" />
                                </flux:field>
                            @endif

                            @if ($provisioning_settings['enable_pppoe'] ?? false)
                                <flux:field>
                                    <flux:label>PPPoE gateway</flux:label>
                                    <flux:input wire:model.blur="provisioning_settings.pppoe_gateway" placeholder="172.16.40.1/24" />
                                    <flux:error name="provisioning_settings.pppoe_gateway" />
                                </flux:field>
                            @endif

                            <div class="md:col-span-3">
                                <div class="mt-1 flex flex-wrap gap-2">
                                    <flux:button type="button" size="xs" wire:click="setHotspotIpSize('22')">/22, about 1,000 clients</flux:button>
                                    <flux:button type="button" size="xs" wire:click="setHotspotIpSize('23')">/23, about 500 clients</flux:button>
                                    <flux:button type="button" size="xs" wire:click="setHotspotIpSize('24')">/24, about 250 clients</flux:button>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-zinc-500 dark:text-zinc-400">Quick-fill the customer hotspot gateway, network, and DHCP pool.</p>
                            </div>
                        </div>

                        <flux:field>
                            <flux:checkbox wire:model.blur="provisioning_settings.route_lan_through_tunnel" label="Route this router's management/staff networks through the WireGuard tunnel" />
                            <flux:description>Lets an external AP on the mgmt/staff VLAN (e.g. Ruijie) reach the Pi's RADIUS server for MAC authentication &mdash; without this, its requests never reach the Pi. Requires a one-time setup on the Pi first; see <code>docs/staff-wifi-access.md</code> and <code>docs/wireguard-server-setup.md</code>. Only enable this if the Mgmt/Staff networks above are unique to this router &mdash; two routers routing overlapping subnets through the tunnel will fail to save.</flux:description>
                            <flux:error name="provisioning_settings.route_lan_through_tunnel" />
                        </flux:field>
                    </div>

                    <div class="flex justify-between">
                        <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                        <div class="flex gap-3">
                            <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                            <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">Save Router</span>
                                <span wire:loading wire:target="save">Saving...</span>
                            </flux:button>
                        </div>
                    </div>
                @endif
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Router</flux:heading>
                <flux:text class="mt-2">This removes the router record from the admin. FreeRADIUS accounting history is left untouched.</flux:text>
            </div>

            @if ($deletingRouter)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4">
                    <p class="font-medium">{{ $deletingRouter->name }}</p>
                    <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $deletingRouter->nas_identifier }} / {{ $deletingRouter->wireguard_internal_ip }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Router</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
