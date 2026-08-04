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

    <section class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
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

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-[780px] w-full text-left text-sm">
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
                    <tr wire:key="router-{{ $router->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $router->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Last seen {{ $router->last_seen_at?->diffForHumans() ?? 'never' }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $router->shop->name }} / {{ $router->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $router->nas_identifier }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $router->wireguard_internal_ip }}</td>
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
                            <p class="mt-1 text-sm text-zinc-500">Register the MikroTik router that will redirect customers to the captive portal.</p>
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

            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field class="md:col-span-2">
                        <flux:label>Shop</flux:label>
                        <flux:select wire:model="shop_id" required>
                            <option value="">Select shop</option>
                            @foreach ($shops as $shop)
                                <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:description>Create a tenant and shop first, then attach each MikroTik router to its shop.</flux:description>
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
                        <flux:description>Unique RouterOS identity. The generated script sets this with <code>/system identity</code>.</flux:description>
                        <flux:error name="nas_identifier" />
                    </flux:field>

                    <flux:field>
                        <flux:label>WireGuard internal IP</flux:label>
                        <flux:input wire:model.blur="wireguard_internal_ip" icon="globe-alt" placeholder="10.8.0.10" required />
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['10.8.0.10', '10.8.0.11', '10.8.0.12', '10.8.0.13'] as $ip)
                                <flux:button type="button" size="xs" wire:click="setPreset('wireguard_internal_ip', '{{ $ip }}')">{{ $ip }}</flux:button>
                            @endforeach
                        </div>
                        <flux:description>Private VPN IP for this router. Keep <code>10.8.0.1</code> for the server.</flux:description>
                        <flux:error name="wireguard_internal_ip" />
                    </flux:field>

                    <flux:field>
                        <flux:label>RADIUS shared secret</flux:label>
                        <flux:input wire:model.blur="shared_secret" icon="key" placeholder="{{ $editingRouterId ? 'Leave blank to keep current value' : 'QF9mX7vC2pL8nR4sT6wY1zA5' }}" viewable />
                        <flux:description>Random password shared by MikroTik and FreeRADIUS. Use a different secret per router.</flux:description>
                        <flux:error name="shared_secret" />
                    </flux:field>
                </div>

                <section class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                        <div>
                            <p class="text-sm font-semibold text-zinc-950">Infrastructure script profile</p>
                            <p class="mt-1 text-sm text-zinc-600">These values drive the fresh MikroTik script for VLANs, AP trunks, POS, PPPoE, and Starlink-friendly PCQ.</p>
                        </div>
                        <flux:badge color="blue">Flexible</flux:badge>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <flux:field class="md:col-span-3">
                            <flux:label>Profile</flux:label>
                            <flux:select wire:model.live="provisioning_settings.profile">
                                @foreach ($infrastructureProfiles as $key => $profile)
                                    <flux:select.option value="{{ $key }}">{{ $profile['name'] }} - {{ $profile['capacity'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>Changing the profile loads sensible defaults. You can still edit the details below.</flux:description>
                            <flux:error name="provisioning_settings.profile" />
                        </flux:field>

                        <flux:field>
                            <flux:label>WAN 1</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.wan1" placeholder="ether1" />
                            <flux:description>Main Starlink or internet uplink.</flux:description>
                            <flux:error name="provisioning_settings.wan1" />
                        </flux:field>

                        <flux:field>
                            <flux:label>WAN 2</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.wan2" placeholder="ether8" />
                            <flux:description>Second Starlink/failover port.</flux:description>
                            <flux:error name="provisioning_settings.wan2" />
                        </flux:field>

                        <flux:field>
                            <flux:label>AP/switch trunk</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.trunk_port" placeholder="ether2" />
                            <flux:description>Port going to APs or managed PoE switch.</flux:description>
                            <flux:error name="provisioning_settings.trunk_port" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Pi/management port</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.pi_port" placeholder="ether3" />
                            <flux:description>Untagged management VLAN access for the Raspberry Pi.</flux:description>
                            <flux:error name="provisioning_settings.pi_port" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Built-in Wi-Fi</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.builtin_wifi_interface" placeholder="wifi1" />
                            <flux:description>For L009UiGS testing, use <code>wifi1</code> as the open hotspot SSID.</flux:description>
                            <flux:error name="provisioning_settings.builtin_wifi_interface" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Staff Wi-Fi password</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.staff_wifi_password" placeholder="MmsStaff2026!" viewable />
                            <flux:description>Used only when built-in Wi-Fi SSIDs are generated.</flux:description>
                            <flux:error name="provisioning_settings.staff_wifi_password" />
                        </flux:field>

                        <flux:field>
                            <flux:label>POS Wi-Fi password</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.pos_wifi_password" placeholder="MmsPos2026!" viewable />
                            <flux:description>Use for POS SSID testing before external AP rollout.</flux:description>
                            <flux:error name="provisioning_settings.pos_wifi_password" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Mgmt Wi-Fi password</flux:label>
                            <flux:input wire:model.blur="provisioning_settings.mgmt_wifi_password" placeholder="MmsMgmt2026!" viewable />
                            <flux:description>For lab access to the management VLAN.</flux:description>
                            <flux:error name="provisioning_settings.mgmt_wifi_password" />
                        </flux:field>

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

                        <div class="grid gap-3 rounded-md border border-zinc-200 bg-white p-3 text-sm">
                            <flux:checkbox wire:model.live="provisioning_settings.enable_builtin_wifi" label="L009 built-in Wi-Fi hotspot" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_realtime_qos" label="Realtime voice/video QoS" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_pos" label="POS VLAN/SSID" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_pppoe" label="PPPoE/CPE VLAN" />
                            <flux:checkbox wire:model.live="provisioning_settings.enable_second_wan" label="Second Starlink/WAN" />
                        </div>
                    </div>

                    <div class="mt-4 rounded-md border border-zinc-200 bg-white p-3">
                        <p class="text-sm font-medium text-zinc-900">Quick-fill router layout</p>
                        <p class="mt-1 text-xs leading-5 text-zinc-500">Use a preset, then adjust any value before saving.</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <flux:button type="button" size="xs" wire:click="setRouterLayoutPreset('l009_lab_wifi')">L009 lab Wi-Fi</flux:button>
                            <flux:button type="button" size="xs" wire:click="setRouterLayoutPreset('plaza_23')">Plaza VLAN /23</flux:button>
                            <flux:button type="button" size="xs" wire:click="setRouterLayoutPreset('small_ap_24')">Small AP /24</flux:button>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-5">
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

                        <flux:field>
                            <flux:label>Staff VLAN</flux:label>
                            <flux:input type="number" wire:model.blur="provisioning_settings.staff_vlan" placeholder="30" />
                            <flux:error name="provisioning_settings.staff_vlan" />
                        </flux:field>

                        <flux:field>
                            <flux:label>PPPoE VLAN</flux:label>
                            <flux:input type="number" wire:model.blur="provisioning_settings.pppoe_vlan" placeholder="40" />
                            <flux:error name="provisioning_settings.pppoe_vlan" />
                        </flux:field>

                        <flux:field>
                            <flux:label>POS VLAN</flux:label>
                            <flux:input type="number" wire:model.blur="provisioning_settings.pos_vlan" placeholder="50" />
                            <flux:error name="provisioning_settings.pos_vlan" />
                        </flux:field>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-3">
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

                        <div class="md:col-span-3">
                            <div class="mt-1 flex flex-wrap gap-2">
                                <flux:button type="button" size="xs" wire:click="setHotspotIpSize('22')">/22, about 1,000 clients</flux:button>
                                <flux:button type="button" size="xs" wire:click="setHotspotIpSize('23')">/23, about 500 clients</flux:button>
                                <flux:button type="button" size="xs" wire:click="setHotspotIpSize('24')">/24, about 250 clients</flux:button>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-zinc-500">Quick-fill the customer hotspot gateway, network, and DHCP pool.</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-md border border-zinc-200 bg-white p-3">
                        <p class="text-sm font-medium text-zinc-900">Hotspot IP examples</p>
                        <p class="mt-1 text-xs leading-5 text-zinc-500">Choose based on expected concurrent users. You can still edit the values manually after clicking.</p>
                        <div class="mt-3 grid gap-2 text-xs text-zinc-600 sm:grid-cols-3">
                            <div><span class="font-medium text-zinc-900">/24:</span> one small zone, about 250 usable addresses.</div>
                            <div><span class="font-medium text-zinc-900">/23:</span> plaza starter, about 500 usable addresses.</div>
                            <div><span class="font-medium text-zinc-900">/22:</span> larger rollout, about 1,000 usable addresses.</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                    <p><strong class="text-zinc-900">NAS identifier:</strong> the MikroTik system identity. Example: <code>lagos-shop-01</code>.</p>
                    <p class="mt-2"><strong class="text-zinc-900">Shared secret:</strong> generate a strong value with <code>openssl rand -base64 24</code>.</p>
                    <p class="mt-2">After saving, open the Script page and paste the generated commands into MikroTik RouterOS terminal.</p>
                </section>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Router</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
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
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingRouter->name }}</p>
                    <p class="mt-1 font-mono text-xs text-zinc-500">{{ $deletingRouter->nas_identifier }} / {{ $deletingRouter->wireguard_internal_ip }}</p>
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
