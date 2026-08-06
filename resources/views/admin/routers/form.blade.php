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
                <flux:field class="md:col-span-2">
                    <flux:label>Shop</flux:label>
                    <flux:select name="shop_id" required>
                        <option value="">Select shop</option>
                        @foreach ($shops as $shop)
                            <option value="{{ $shop->id }}" @selected(old('shop_id', $router->shop_id) == $shop->id)>{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:description>Create a tenant and shop first, then attach each MikroTik router to its shop.</flux:description>
                    <flux:error name="shop_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Router name</flux:label>
                    <flux:input name="name" value="{{ old('name', $router->name) }}" icon="signal" placeholder="Main Shop Router" required />
                    <flux:description>Dashboard label only. Example: Main Shop Router.</flux:description>
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>NAS identifier</flux:label>
                    <flux:input name="nas_identifier" value="{{ old('nas_identifier', $router->exists ? $router->nas_identifier : '') }}" icon="finger-print" placeholder="lagos-shop-01" required />
                    <flux:description>Unique RouterOS identity. The generated script sets this with <code>/system identity</code>.</flux:description>
                    <flux:error name="nas_identifier" />
                </flux:field>

                <flux:field>
                    <flux:label>WireGuard internal IP</flux:label>
                    <flux:input name="wireguard_internal_ip" value="{{ old('wireguard_internal_ip', $router->exists ? $router->wireguard_internal_ip : ($suggestedWireguardInternalIp ?? '10.8.0.10')) }}" icon="globe-alt" placeholder="10.8.0.10" required />
                    <flux:description>Private VPN IP for this router. Pre-filled with the next unused address after <code>10.8.0.1</code> (the server).</flux:description>
                    <flux:error name="wireguard_internal_ip" />
                </flux:field>

                <flux:field>
                    <flux:label>RADIUS shared secret</flux:label>
                    <flux:input name="shared_secret" value="{{ old('shared_secret', $router->exists ? '' : ($suggestedSharedSecret ?? '')) }}" icon="key" placeholder="{{ $router->exists ? 'Leave blank to keep current value' : 'QF9mX7vC2pL8nR4sT6wY1zA5' }}" viewable @required(! $router->exists) />
                    <flux:description>Random password shared by MikroTik and FreeRADIUS. Pre-filled with a strong random value; use a different secret per router.</flux:description>
                    <flux:error name="shared_secret" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>WireGuard endpoint override (optional)</flux:label>
                    <div class="grid gap-3 sm:grid-cols-[2fr_1fr]">
                        <flux:input name="wireguard_endpoint_override_host" value="{{ old('wireguard_endpoint_override_host', $router->wireguard_endpoint_override_host) }}" icon="map-pin" placeholder="Leave blank to use the default public endpoint" />
                        <flux:input name="wireguard_endpoint_override_port" value="{{ old('wireguard_endpoint_override_port', $router->wireguard_endpoint_override_port) }}" type="number" min="1" max="65535" placeholder="13231" />
                    </div>
                    <flux:description>Only set this if this router is physically on the same local network as the Pi &mdash; use the Pi's LAN IP here instead of the public endpoint. Reaching the public endpoint from inside the same LAN commonly fails silently (NAT hairpin/loopback isn't supported for UDP on many routers). Leave both blank for every other (remote) router.</flux:description>
                    <flux:error name="wireguard_endpoint_override_host" />
                    <flux:error name="wireguard_endpoint_override_port" />
                </flux:field>

                @if ($router->exists && $router->wireguard_public_key)
                    <flux:field class="md:col-span-2">
                        <flux:label>WireGuard public key</flux:label>
                        <flux:input value="{{ $router->wireguard_public_key }}" icon="key" readonly copyable />
                        <flux:description>Generated by MMS Radius and baked into this router's script. The scheduled WireGuard sync registers it as a Pi peer automatically.</flux:description>
                    </flux:field>
                @endif
            </div>

            @php($settings = $provisioningSettings ?? [])

            <section class="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                    <div>
                        <h2 class="text-base font-semibold">Infrastructure Script Profile</h2>
                        <p class="mt-1 text-sm text-zinc-600">These values drive the generated fresh MikroTik script for VLANs, AP trunks, Starlink-friendly PCQ, POS, and PPPoE.</p>
                    </div>
                    <flux:badge color="blue">Flexible</flux:badge>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <flux:field class="md:col-span-3">
                        <flux:label>Profile</flux:label>
                        <flux:select name="provisioning_settings[profile]">
                            @foreach ($infrastructureProfiles as $key => $profile)
                                <option value="{{ $key }}" @selected(($settings['profile'] ?? 'starlink_plaza') === $key)>{{ $profile['name'] }} - {{ $profile['capacity'] }}</option>
                            @endforeach
                        </flux:select>
                        <flux:description>Choose the closest tenant infrastructure, then adjust ports, VLANs, and limits.</flux:description>
                        <flux:error name="provisioning_settings.profile" />
                    </flux:field>

                    <flux:field>
                        <flux:label>WAN 1</flux:label>
                        <flux:input name="provisioning_settings[wan1]" value="{{ $settings['wan1'] ?? 'ether1' }}" placeholder="ether1" />
                        <flux:error name="provisioning_settings.wan1" />
                    </flux:field>

                    <flux:field>
                        <flux:label>WAN 2</flux:label>
                        <flux:input name="provisioning_settings[wan2]" value="{{ $settings['wan2'] ?? 'ether8' }}" placeholder="ether8" />
                        <flux:error name="provisioning_settings.wan2" />
                    </flux:field>

                    <flux:field>
                        <flux:label>AP/switch trunk</flux:label>
                        <flux:input name="provisioning_settings[trunk_port]" value="{{ $settings['trunk_port'] ?? 'ether2' }}" placeholder="ether2" />
                        <flux:error name="provisioning_settings.trunk_port" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Pi/management port</flux:label>
                        <flux:input name="provisioning_settings[pi_port]" value="{{ $settings['pi_port'] ?? 'ether3' }}" placeholder="ether3" />
                        <flux:description>Untagged management VLAN access for the Raspberry Pi.</flux:description>
                        <flux:error name="provisioning_settings.pi_port" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Built-in Wi-Fi</flux:label>
                        <flux:input name="provisioning_settings[builtin_wifi_interface]" value="{{ $settings['builtin_wifi_interface'] ?? 'wifi1' }}" placeholder="wifi1" />
                        <flux:description>For L009UiGS testing, use <code>wifi1</code> as the open hotspot SSID.</flux:description>
                        <flux:error name="provisioning_settings.builtin_wifi_interface" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Staff Wi-Fi password</flux:label>
                        <flux:input name="provisioning_settings[staff_wifi_password]" value="{{ $settings['staff_wifi_password'] ?? 'MmsStaff2026!' }}" placeholder="MmsStaff2026!" viewable />
                        <flux:description>Used only when built-in Wi-Fi SSIDs are generated.</flux:description>
                        <flux:error name="provisioning_settings.staff_wifi_password" />
                    </flux:field>

                    <flux:field>
                        <flux:label>POS Wi-Fi password</flux:label>
                        <flux:input name="provisioning_settings[pos_wifi_password]" value="{{ $settings['pos_wifi_password'] ?? 'MmsPos2026!' }}" placeholder="MmsPos2026!" viewable />
                        <flux:description>Use for POS SSID testing before external AP rollout.</flux:description>
                        <flux:error name="provisioning_settings.pos_wifi_password" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Mgmt Wi-Fi password</flux:label>
                        <flux:input name="provisioning_settings[mgmt_wifi_password]" value="{{ $settings['mgmt_wifi_password'] ?? 'MmsMgmt2026!' }}" placeholder="MmsMgmt2026!" viewable />
                        <flux:description>For lab access to the management VLAN.</flux:description>
                        <flux:error name="provisioning_settings.mgmt_wifi_password" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Download limit</flux:label>
                        <flux:input name="provisioning_settings[download_limit]" value="{{ $settings['download_limit'] ?? '120M' }}" placeholder="120M" />
                        <flux:error name="provisioning_settings.download_limit" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Upload limit</flux:label>
                        <flux:input name="provisioning_settings[upload_limit]" value="{{ $settings['upload_limit'] ?? '20M' }}" placeholder="20M" />
                        <flux:error name="provisioning_settings.upload_limit" />
                    </flux:field>

                    <div class="grid gap-3 rounded-md border border-zinc-200 bg-white p-3 text-sm">
                        <input type="hidden" name="provisioning_settings[enable_builtin_wifi]" value="0">
                        <label class="flex items-center gap-2"><input type="checkbox" name="provisioning_settings[enable_builtin_wifi]" value="1" @checked($settings['enable_builtin_wifi'] ?? false)> L009 built-in Wi-Fi hotspot</label>
                        <input type="hidden" name="provisioning_settings[enable_realtime_qos]" value="0">
                        <label class="flex items-center gap-2"><input type="checkbox" name="provisioning_settings[enable_realtime_qos]" value="1" @checked($settings['enable_realtime_qos'] ?? true)> Realtime voice/video QoS</label>
                        <input type="hidden" name="provisioning_settings[enable_pos]" value="0">
                        <label class="flex items-center gap-2"><input type="checkbox" name="provisioning_settings[enable_pos]" value="1" @checked($settings['enable_pos'] ?? true)> POS VLAN/SSID</label>
                        <input type="hidden" name="provisioning_settings[enable_pppoe]" value="0">
                        <label class="flex items-center gap-2"><input type="checkbox" name="provisioning_settings[enable_pppoe]" value="1" @checked($settings['enable_pppoe'] ?? true)> PPPoE/CPE VLAN</label>
                        <input type="hidden" name="provisioning_settings[enable_second_wan]" value="0">
                        <label class="flex items-center gap-2"><input type="checkbox" name="provisioning_settings[enable_second_wan]" value="1" @checked($settings['enable_second_wan'] ?? false)> Second Starlink/WAN</label>
                    </div>
                </div>

                <div class="mt-4 rounded-md border border-zinc-200 bg-white p-3">
                    <p class="text-sm font-medium text-zinc-900">Quick-fill router layout</p>
                    <p class="mt-1 text-xs leading-5 text-zinc-500">Use a preset, then adjust any value before saving.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <flux:button
                            type="button"
                            size="xs"
                            data-set-fields='{"provisioning_settings[profile]":"l009_builtin_wifi","provisioning_settings[wan1]":"ether1","provisioning_settings[wan2]":"ether7","provisioning_settings[trunk_port]":"ether2","provisioning_settings[pi_port]":"ether3","provisioning_settings[builtin_wifi_interface]":"wifi1","provisioning_settings[staff_wifi_password]":"MmsStaff2026!","provisioning_settings[pos_wifi_password]":"MmsPos2026!","provisioning_settings[mgmt_wifi_password]":"MmsMgmt2026!","provisioning_settings[download_limit]":"80M","provisioning_settings[upload_limit]":"15M","provisioning_settings[hotspot_gateway]":"10.5.50.1/24","provisioning_settings[hotspot_network]":"10.5.50.0/24","provisioning_settings[hotspot_pool]":"10.5.50.10-10.5.50.250","provisioning_settings[enable_builtin_wifi]":"1","provisioning_settings[enable_pos]":"1","provisioning_settings[enable_pppoe]":"0","provisioning_settings[enable_realtime_qos]":"1","provisioning_settings[enable_second_wan]":"0"}'
                        >L009 lab Wi-Fi</flux:button>
                        <flux:button
                            type="button"
                            size="xs"
                            data-set-fields='{"provisioning_settings[profile]":"starlink_plaza","provisioning_settings[wan1]":"ether1","provisioning_settings[wan2]":"ether8","provisioning_settings[trunk_port]":"ether2","provisioning_settings[pi_port]":"ether3","provisioning_settings[builtin_wifi_interface]":"wifi1","provisioning_settings[download_limit]":"120M","provisioning_settings[upload_limit]":"20M","provisioning_settings[hotspot_gateway]":"10.5.50.1/23","provisioning_settings[hotspot_network]":"10.5.50.0/23","provisioning_settings[hotspot_pool]":"10.5.50.10-10.5.51.250","provisioning_settings[enable_builtin_wifi]":"0","provisioning_settings[enable_pos]":"1","provisioning_settings[enable_pppoe]":"1","provisioning_settings[enable_realtime_qos]":"1"}'
                        >Plaza VLAN /23</flux:button>
                        <flux:button
                            type="button"
                            size="xs"
                            data-set-fields='{"provisioning_settings[profile]":"small_hotspot","provisioning_settings[wan1]":"ether1","provisioning_settings[wan2]":"ether8","provisioning_settings[trunk_port]":"ether2","provisioning_settings[pi_port]":"ether3","provisioning_settings[download_limit]":"80M","provisioning_settings[upload_limit]":"15M","provisioning_settings[hotspot_gateway]":"10.5.50.1/24","provisioning_settings[hotspot_network]":"10.5.50.0/24","provisioning_settings[hotspot_pool]":"10.5.50.10-10.5.50.250","provisioning_settings[enable_builtin_wifi]":"0","provisioning_settings[enable_pos]":"1","provisioning_settings[enable_pppoe]":"0","provisioning_settings[enable_realtime_qos]":"1"}'
                        >Small AP /24</flux:button>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-5">
                    @foreach ([
                        'mgmt_vlan' => 'Mgmt VLAN',
                        'hotspot_vlan' => 'Hotspot VLAN',
                        'staff_vlan' => 'Staff VLAN',
                        'pppoe_vlan' => 'PPPoE VLAN',
                        'pos_vlan' => 'POS VLAN',
                    ] as $field => $label)
                        <flux:field>
                            <flux:label>{{ $label }}</flux:label>
                            <flux:input type="number" name="provisioning_settings[{{ $field }}]" value="{{ $settings[$field] ?? '' }}" />
                            <flux:error name="provisioning_settings.{{ $field }}" />
                        </flux:field>
                    @endforeach
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    @foreach ([
                        'mgmt_gateway' => ['Management gateway', '192.168.10.1/24'],
                        'mgmt_network' => ['Management network', '192.168.10.0/24'],
                        'mgmt_pool' => ['Management DHCP pool', '192.168.10.10-192.168.10.250'],
                        'hotspot_gateway' => ['Hotspot gateway', '10.5.50.1/23'],
                        'hotspot_network' => ['Hotspot network', '10.5.50.0/23'],
                        'hotspot_pool' => ['Hotspot DHCP pool', '10.5.50.10-10.5.51.250'],
                    ] as $field => [$label, $placeholder])
                        <flux:field>
                            <flux:label>{{ $label }}</flux:label>
                            <flux:input name="provisioning_settings[{{ $field }}]" value="{{ $settings[$field] ?? $placeholder }}" placeholder="{{ $placeholder }}" />
                            <flux:error name="provisioning_settings.{{ $field }}" />
                        </flux:field>
                    @endforeach

                    <div class="md:col-span-3">
                        <div class="mt-1 flex flex-wrap gap-2">
                            <flux:button
                                type="button"
                                size="xs"
                                data-set-fields='{"provisioning_settings[hotspot_gateway]":"10.5.48.1/22","provisioning_settings[hotspot_network]":"10.5.48.0/22","provisioning_settings[hotspot_pool]":"10.5.48.10-10.5.51.250"}'
                            >/22, about 1,000 clients</flux:button>
                            <flux:button
                                type="button"
                                size="xs"
                                data-set-fields='{"provisioning_settings[hotspot_gateway]":"10.5.50.1/23","provisioning_settings[hotspot_network]":"10.5.50.0/23","provisioning_settings[hotspot_pool]":"10.5.50.10-10.5.51.250"}'
                            >/23, about 500 clients</flux:button>
                            <flux:button
                                type="button"
                                size="xs"
                                data-set-fields='{"provisioning_settings[hotspot_gateway]":"10.5.50.1/24","provisioning_settings[hotspot_network]":"10.5.50.0/24","provisioning_settings[hotspot_pool]":"10.5.50.10-10.5.50.250"}'
                            >/24, about 250 clients</flux:button>
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

            <div class="mt-6 flex gap-3">
                <flux:button type="submit" variant="primary" icon="check">Save Router</flux:button>
            <flux:button href="{{ route('admin.routers.index') }}" wire:navigate variant="outline">Cancel</flux:button>
            </div>
        </form>

        <aside class="space-y-4">
            @include('admin.partials.billing-usage', ['usage' => $billingUsage ?? null])

            <section class="rounded-lg border border-zinc-200 bg-white p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">Field Guide</h2>
                    <flux:dropdown>
                        <flux:button type="button" icon="information-circle" variant="ghost" size="sm" aria-label="Show field guide" />
                        <flux:popover class="max-w-sm space-y-4 text-sm text-zinc-600">
                            <p><strong class="text-zinc-900">NAS identifier:</strong> the MikroTik system identity. Example: <code>lagos-shop-01</code>.</p>
                            <p><strong class="text-zinc-900">WireGuard IP:</strong> the router's private VPN address. First router can be <code>10.8.0.10</code>.</p>
                            <p><strong class="text-zinc-900">Shared secret:</strong> generate a strong random value and keep it private.</p>
                            <p class="rounded-md bg-zinc-50 p-3 font-mono text-xs text-zinc-800">openssl rand -base64 24</p>
                            <p>After saving, open the router's Script page and paste the generated commands into MikroTik RouterOS terminal.</p>
                        </flux:popover>
                    </flux:dropdown>
                </div>
                <p class="mt-2 text-sm text-zinc-500">Tap the info icon for NAS identifier, WireGuard IP, and shared secret guidance.</p>
            </section>
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

        document.querySelectorAll('[data-set-fields]').forEach((button) => {
            button.addEventListener('click', () => {
                Object.entries(JSON.parse(button.dataset.setFields)).forEach(([name, value]) => {
                    const fields = Array.from(document.getElementsByName(name));
                    const hasCheckbox = fields.some((field) => field.type === 'checkbox');

                    fields.forEach((field) => {
                        if (field.type === 'checkbox') {
                            field.checked = value === '1';
                        } else if (field.type === 'hidden' && hasCheckbox) {
                            field.value = '0';
                        } else {
                            field.value = value;
                        }

                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            });
        });
    </script>
</x-layouts.admin>
