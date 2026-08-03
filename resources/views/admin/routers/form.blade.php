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
                    <flux:input name="nas_identifier" value="{{ old('nas_identifier', $router->nas_identifier) }}" icon="finger-print" placeholder="lagos-shop-01" required />
                    <flux:description>Unique RouterOS identity. The generated script sets this with <code>/system identity</code>.</flux:description>
                    <flux:error name="nas_identifier" />
                </flux:field>

                <flux:field>
                    <flux:label>WireGuard internal IP</flux:label>
                    <flux:input name="wireguard_internal_ip" value="{{ old('wireguard_internal_ip', $router->wireguard_internal_ip) }}" icon="globe-alt" placeholder="10.8.0.10" required />
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (['10.8.0.10', '10.8.0.11', '10.8.0.12', '10.8.0.13'] as $ip)
                            <flux:button type="button" size="xs" data-set-field="wireguard_internal_ip" data-set-value="{{ $ip }}">{{ $ip }}</flux:button>
                        @endforeach
                    </div>
                    <flux:description>Private VPN IP for this router. Keep <code>10.8.0.1</code> for the server, then use <code>10.8.0.10</code>, <code>10.8.0.11</code>, and so on.</flux:description>
                    <flux:error name="wireguard_internal_ip" />
                </flux:field>

                <flux:field>
                    <flux:label>RADIUS shared secret</flux:label>
                    <flux:input name="shared_secret" value="{{ old('shared_secret') }}" icon="key" placeholder="{{ $router->exists ? 'Leave blank to keep current value' : 'QF9mX7vC2pL8nR4sT6wY1zA5' }}" viewable @required(! $router->exists) />
                    <flux:description>Random password shared by MikroTik and FreeRADIUS. Use a different secret per router.</flux:description>
                    <flux:error name="shared_secret" />
                </flux:field>

                <flux:checkbox name="is_online" value="1" :checked="(bool) old('is_online', $router->is_online ?? false)" label="Online" />
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
                <h2 class="text-base font-semibold">Field Guide</h2>
                <div class="mt-4 space-y-4 text-sm text-zinc-600">
                    <p><strong class="text-zinc-900">NAS identifier:</strong> the MikroTik system identity. Example: <code>lagos-shop-01</code>.</p>
                    <p><strong class="text-zinc-900">WireGuard IP:</strong> the router's private VPN address. First router can be <code>10.8.0.10</code>.</p>
                    <p><strong class="text-zinc-900">Shared secret:</strong> generate a strong random value and keep it private.</p>
                    <p class="rounded-md bg-zinc-50 p-3 font-mono text-xs text-zinc-800">openssl rand -base64 24</p>
                    <p>After saving, open the router's Script page and paste the generated commands into MikroTik RouterOS terminal.</p>
                </div>
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
    </script>
</x-layouts.admin>
