<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Shop;
use App\Models\User;
use App\Support\BillingPlanLimits;
use App\Support\RouterPortLayout;
use App\Support\TenantAccess;
use App\Support\WireGuardKeyPair;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RouterManagementService
{
    public function __construct(private readonly RadiusProvisioningService $radius) {}

    /**
     * @param  array<string, mixed>|null  $provisioningSettings  the live/submitted provisioning_settings
     *     array, used to cross-check port role numbers against each other and against port_count.
     *     Both call sites (RoutersIndex::save() and validated()) already have this available at the
     *     point they call rules(), since it's either bound Livewire component state or raw request input.
     */
    public function rules(User $user, ?Router $router = null, ?array $provisioningSettings = null): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'name' => ['required', 'string', 'max:255'],
            'nas_identifier' => ['required', 'string', 'max:255', Rule::unique('routers')->ignore($router)],
            'wireguard_internal_ip' => ['required', 'ip', Rule::unique('routers')->ignore($router)],
            'shared_secret' => [$router ? 'nullable' : 'required', 'string', 'max:255'],
            'wireguard_endpoint_override_host' => ['nullable', 'string', 'max:255'],
            'wireguard_endpoint_override_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'provisioning_settings' => ['nullable', 'array'],
            'provisioning_settings.profile' => ['nullable', Rule::in(['starlink_plaza', 'small_hotspot', 'pppoe_isp'])],
            'provisioning_settings.port_count' => [
                'nullable', 'integer', 'min:2', 'max:64',
                $this->portConflictRule($provisioningSettings ?? []),
            ],
            'provisioning_settings.ports_advanced_mode' => ['nullable', 'boolean'],
            'provisioning_settings.wan1_port_number' => ['nullable', 'integer', 'min:1'],
            'provisioning_settings.wan2_port_number' => ['nullable', 'integer', 'min:1'],
            'provisioning_settings.trunk_port_number' => ['nullable', 'integer', 'min:1'],
            'provisioning_settings.pi_port_number' => ['nullable', 'integer', 'min:1'],
            'provisioning_settings.wan1' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.wan2' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.trunk_port' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.pi_port' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.builtin_wifi_interface' => ['required_if:provisioning_settings.enable_builtin_wifi,true', 'nullable', 'string', 'max:40'],
            'provisioning_settings.staff_wifi_password' => ['required_if:provisioning_settings.enable_builtin_wifi,true', 'nullable', 'string', 'min:8', 'max:63'],
            'provisioning_settings.pos_wifi_password' => ['nullable', 'string', 'min:8', 'max:63'],
            'provisioning_settings.mgmt_wifi_password' => ['required_if:provisioning_settings.enable_builtin_wifi,true', 'nullable', 'string', 'min:8', 'max:63'],
            'provisioning_settings.download_limit' => ['nullable', 'string', 'max:20'],
            'provisioning_settings.upload_limit' => ['nullable', 'string', 'max:20'],
            'provisioning_settings.mgmt_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.hotspot_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.staff_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.pppoe_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.pos_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.mgmt_gateway' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.mgmt_network' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.mgmt_pool' => ['nullable', 'string', 'max:64'],
            'provisioning_settings.hotspot_gateway' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.hotspot_network' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.hotspot_pool' => ['nullable', 'string', 'max:64'],
            'provisioning_settings.staff_gateway' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.staff_network' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.staff_pool' => ['nullable', 'string', 'max:64'],
            'provisioning_settings.pos_gateway' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.pos_network' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.pos_pool' => ['nullable', 'string', 'max:64'],
            'provisioning_settings.pppoe_gateway' => ['nullable', 'string', 'max:32'],
            'provisioning_settings.enable_builtin_wifi' => ['nullable', 'boolean'],
            'provisioning_settings.enable_pos' => ['nullable', 'boolean'],
            'provisioning_settings.enable_pppoe' => ['nullable', 'boolean'],
            'provisioning_settings.enable_realtime_qos' => ['nullable', 'boolean'],
            'provisioning_settings.enable_second_wan' => ['nullable', 'boolean'],
            'is_online' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request, ?Router $router = null): array
    {
        // Blank optional inputs submit as "" from HTML forms, not absent -- normalize
        // to null first so `nullable` short-circuits `integer`/etc. instead of failing
        // validation on an intentionally-empty field.
        $request->merge([
            'wireguard_endpoint_override_host' => $request->filled('wireguard_endpoint_override_host') ? $request->input('wireguard_endpoint_override_host') : null,
            'wireguard_endpoint_override_port' => $request->filled('wireguard_endpoint_override_port') ? $request->input('wireguard_endpoint_override_port') : null,
        ]);

        $data = $request->validate($this->rules($request->user(), $router, (array) $request->input('provisioning_settings', [])));

        if (! $router) {
            $data += ['is_online' => false];
        }

        // Not normalized here -- create()/update() already normalize whatever they're
        // given (they're also called directly with raw validated data from the Livewire
        // wizard, which doesn't route through this method at all). Normalizing here too
        // would run it twice for the HTTP path, and derivePortInterfaceNames() isn't
        // idempotent against its own already-defaulted output.
        return $data;
    }

    public function create(array $data, User $user): Router
    {
        BillingPlanLimits::assertCanCreateRouter($user);

        $router = Router::create($this->normalize($data));
        $this->radius->syncRouter($router);

        return $router;
    }

    public function update(Router $router, array $data, User $user): Router
    {
        TenantAccess::assertRouter($router, $user);

        $router->update($this->normalize($data, $router));
        $this->radius->syncRouter($router);

        return $router;
    }

    public function delete(Router $router, User $user): void
    {
        TenantAccess::assertRouter($router, $user);

        $router->delete();
        $this->radius->deleteRouter($router);
    }

    public function regenerateWireguardKey(Router $router, User $user): Router
    {
        TenantAccess::assertRouter($router, $user);

        $keyPair = WireGuardKeyPair::generate();

        $router->update([
            'wireguard_private_key' => $keyPair['private'],
            'wireguard_public_key' => $keyPair['public'],
        ]);

        return $router;
    }

    public function regenerateApiCredentials(Router $router, User $user): Router
    {
        TenantAccess::assertRouter($router, $user);

        $router->update([
            'api_username' => Router::API_USERNAME,
            'api_password' => Str::random(32),
            'api_port' => Router::API_PORT,
        ]);

        return $router;
    }

    public function normalize(array $data, ?Router $router = null): array
    {
        if (array_key_exists('is_online', $data)) {
            $data['is_online'] = (bool) $data['is_online'];
        } elseif (! $router) {
            $data['is_online'] = false;
        }

        $data['provisioning_settings'] = $this->normalizeProvisioningSettings($data['provisioning_settings'] ?? []);

        if ($router && blank($data['shared_secret'] ?? null)) {
            unset($data['shared_secret']);
        }

        return $data;
    }

    public function suggestedWireguardInternalIp(): string
    {
        $lastOctet = Router::query()
            ->where('wireguard_internal_ip', 'like', '10.8.0.%')
            ->pluck('wireguard_internal_ip')
            ->map(fn (string $ip): int => (int) str($ip)->afterLast('.')->toString())
            ->max();

        return '10.8.0.'.max(10, ($lastOctet ?? 9) + 1);
    }

    public function suggestedNasIdentifier(Shop $shop): string
    {
        $base = Str::slug($shop->name) ?: 'router';
        $suffix = 1;

        do {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        } while (Router::query()->where('nas_identifier', $candidate)->exists());

        return $candidate;
    }

    public function suggestedSharedSecret(): string
    {
        return Str::random(32);
    }

    public function defaultProvisioningSettings(?string $profile = null): array
    {
        $profile = in_array($profile, ['starlink_plaza', 'small_hotspot', 'pppoe_isp'], true)
            ? $profile
            : 'starlink_plaza';

        return [
            'profile' => $profile,
            'port_count' => 8,
            'ports_advanced_mode' => false,
            'wan1_port_number' => 1,
            'wan2_port_number' => 8,
            'trunk_port_number' => 2,
            'pi_port_number' => 3,
            'wan1' => 'ether1',
            'wan2' => 'ether8',
            'trunk_port' => 'ether2',
            'pi_port' => 'ether3',
            'builtin_wifi_interface' => 'wifi1',
            'staff_wifi_password' => 'MmsStaff2026!',
            'pos_wifi_password' => 'MmsPos2026!',
            'mgmt_wifi_password' => 'MmsMgmt2026!',
            'download_limit' => match ($profile) {
                'small_hotspot' => '80M',
                'pppoe_isp' => '150M',
                default => '120M',
            },
            'upload_limit' => match ($profile) {
                'small_hotspot' => '15M',
                'pppoe_isp' => '25M',
                default => '20M',
            },
            'mgmt_vlan' => 10,
            'hotspot_vlan' => 20,
            'staff_vlan' => 30,
            'pppoe_vlan' => 40,
            'pos_vlan' => 50,
            'mgmt_gateway' => '192.168.10.1/24',
            'mgmt_network' => '192.168.10.0/24',
            'mgmt_pool' => '192.168.10.10-192.168.10.250',
            'hotspot_gateway' => '10.5.50.1/23',
            'hotspot_network' => '10.5.50.0/23',
            'hotspot_pool' => '10.5.50.10-10.5.51.250',
            'staff_gateway' => '192.168.30.1/24',
            'staff_network' => '192.168.30.0/24',
            'staff_pool' => '192.168.30.10-192.168.30.250',
            'pos_gateway' => '192.168.50.1/24',
            'pos_network' => '192.168.50.0/24',
            'pos_pool' => '192.168.50.10-192.168.50.250',
            'pppoe_gateway' => '172.16.40.1/24',
            'enable_builtin_wifi' => false,
            'enable_pos' => true,
            'enable_pppoe' => $profile !== 'small_hotspot',
            'enable_realtime_qos' => true,
            'enable_second_wan' => false,
        ];
    }

    private function normalizeProvisioningSettings(array $settings): array
    {
        $settings = array_filter($settings, fn ($value): bool => $value !== null && $value !== '');
        // Derive interface-name strings from *_port_number BEFORE merging in profile
        // defaults below -- otherwise a defaulted (not actually submitted) port number
        // would overwrite an explicitly-typed raw interface name like "sfp-sfpplus1".
        $settings = $this->derivePortInterfaceNames($settings);
        $settings = $settings + ['profile' => 'starlink_plaza'];
        $settings = array_replace($this->defaultProvisioningSettings((string) $settings['profile']), $settings);

        foreach (['enable_builtin_wifi', 'enable_pos', 'enable_pppoe', 'enable_realtime_qos', 'enable_second_wan', 'ports_advanced_mode'] as $field) {
            $settings[$field] = (bool) ($settings[$field] ?? false);
        }

        foreach (['mgmt_vlan', 'hotspot_vlan', 'staff_vlan', 'pppoe_vlan', 'pos_vlan'] as $field) {
            $settings[$field] = (int) $settings[$field];
        }

        return $settings;
    }

    /**
     * Turns wan1_port_number/wan2_port_number/trunk_port_number/pi_port_number into the
     * "etherN" strings MikroTikProvisioningService actually reads (wan1/wan2/trunk_port/
     * pi_port) -- so that service needs no changes at all to keep consuming the same shape.
     * Left untouched in advanced mode, where the raw strings are trusted as typed.
     */
    private function derivePortInterfaceNames(array $settings): array
    {
        if (($settings['ports_advanced_mode'] ?? false) === true) {
            return $settings;
        }

        foreach ([
            'wan1' => 'wan1_port_number',
            'wan2' => 'wan2_port_number',
            'trunk_port' => 'trunk_port_number',
            'pi_port' => 'pi_port_number',
        ] as $stringKey => $numberKey) {
            if (! empty($settings[$numberKey])) {
                $settings[$stringKey] = RouterPortLayout::interfaceName((int) $settings[$numberKey]);
            }
        }

        return $settings;
    }

    /**
     * Cross-field validator attached to provisioning_settings.port_count: rejects a role
     * port number higher than the total port count, and rejects two roles sharing a port.
     * No-ops in advanced mode (raw interface-name strings aren't port-count-bound) or when
     * port_count itself hasn't been submitted yet.
     */
    private function portConflictRule(array $provisioningSettings): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($provisioningSettings): void {
            if (($provisioningSettings['ports_advanced_mode'] ?? false) || blank($value)) {
                return;
            }

            $portCount = (int) $value;

            $roles = [
                'WAN 1' => $provisioningSettings['wan1_port_number'] ?? null,
                'WAN 2' => ($provisioningSettings['enable_second_wan'] ?? false) ? ($provisioningSettings['wan2_port_number'] ?? null) : null,
                'Trunk port' => $provisioningSettings['trunk_port_number'] ?? null,
                'Pi port' => $provisioningSettings['pi_port_number'] ?? null,
            ];

            foreach ($roles as $role => $portNumber) {
                if ($portNumber !== null && (int) $portNumber > $portCount) {
                    $fail("{$role} is set to port {$portNumber}, which is higher than the total Ethernet port count ({$portCount}).");

                    return;
                }
            }

            $conflicts = RouterPortLayout::conflictingRoles($roles);

            if ($conflicts !== []) {
                $fail(implode(' ', $conflicts));
            }
        };
    }
}
