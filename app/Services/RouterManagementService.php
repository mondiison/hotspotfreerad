<?php

namespace App\Services;

use App\Models\Router;
use App\Models\User;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouterManagementService
{
    public function __construct(private readonly RadiusProvisioningService $radius) {}

    public function rules(User $user, ?Router $router = null): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'name' => ['required', 'string', 'max:255'],
            'nas_identifier' => ['required', 'string', 'max:255', Rule::unique('routers')->ignore($router)],
            'wireguard_internal_ip' => ['required', 'ip', Rule::unique('routers')->ignore($router)],
            'shared_secret' => [$router ? 'nullable' : 'required', 'string', 'max:255'],
            'provisioning_settings' => ['nullable', 'array'],
            'provisioning_settings.profile' => ['nullable', Rule::in(['starlink_plaza', 'small_hotspot', 'l009_builtin_wifi', 'pppoe_isp'])],
            'provisioning_settings.wan1' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.wan2' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.trunk_port' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.builtin_wifi_interface' => ['nullable', 'string', 'max:40'],
            'provisioning_settings.download_limit' => ['nullable', 'string', 'max:20'],
            'provisioning_settings.upload_limit' => ['nullable', 'string', 'max:20'],
            'provisioning_settings.mgmt_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.hotspot_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.staff_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.pppoe_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provisioning_settings.pos_vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
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
        return $this->normalize(
            $request->validate($this->rules($request->user(), $router)) + ['is_online' => false],
            $router
        );
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
    }

    public function normalize(array $data, ?Router $router = null): array
    {
        $data['is_online'] = (bool) ($data['is_online'] ?? false);
        $data['provisioning_settings'] = $this->normalizeProvisioningSettings($data['provisioning_settings'] ?? []);

        if ($router && blank($data['shared_secret'] ?? null)) {
            unset($data['shared_secret']);
        }

        return $data;
    }

    public function defaultProvisioningSettings(?string $profile = null): array
    {
        $profile = in_array($profile, ['starlink_plaza', 'small_hotspot', 'l009_builtin_wifi', 'pppoe_isp'], true)
            ? $profile
            : 'starlink_plaza';

        return [
            'profile' => $profile,
            'wan1' => 'ether1',
            'wan2' => $profile === 'l009_builtin_wifi' ? 'ether7' : 'ether8',
            'trunk_port' => 'ether2',
            'builtin_wifi_interface' => 'wifi1',
            'download_limit' => match ($profile) {
                'l009_builtin_wifi' => '80M',
                'small_hotspot' => '80M',
                'pppoe_isp' => '150M',
                default => '120M',
            },
            'upload_limit' => match ($profile) {
                'l009_builtin_wifi' => '15M',
                'small_hotspot' => '15M',
                'pppoe_isp' => '25M',
                default => '20M',
            },
            'mgmt_vlan' => 10,
            'hotspot_vlan' => 20,
            'staff_vlan' => 30,
            'pppoe_vlan' => 40,
            'pos_vlan' => 50,
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
            'enable_builtin_wifi' => $profile === 'l009_builtin_wifi',
            'enable_pos' => $profile !== 'l009_builtin_wifi',
            'enable_pppoe' => ! in_array($profile, ['small_hotspot', 'l009_builtin_wifi'], true),
            'enable_realtime_qos' => true,
            'enable_second_wan' => false,
        ];
    }

    private function normalizeProvisioningSettings(array $settings): array
    {
        $settings = array_filter($settings, fn ($value): bool => $value !== null && $value !== '');
        $settings = $settings + ['profile' => 'starlink_plaza'];
        $settings = array_replace($this->defaultProvisioningSettings((string) $settings['profile']), $settings);

        foreach (['enable_builtin_wifi', 'enable_pos', 'enable_pppoe', 'enable_realtime_qos', 'enable_second_wan'] as $field) {
            $settings[$field] = (bool) ($settings[$field] ?? false);
        }

        foreach (['mgmt_vlan', 'hotspot_vlan', 'staff_vlan', 'pppoe_vlan', 'pos_vlan'] as $field) {
            $settings[$field] = (int) $settings[$field];
        }

        return $settings;
    }
}
