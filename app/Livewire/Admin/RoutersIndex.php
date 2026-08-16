<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Models\Shop;
use App\Services\MikroTikProvisioningService;
use App\Services\RouterManagementService;
use App\Services\RouterOsConnectionService;
use App\Support\BillingPlanLimits;
use App\Support\RadiusAccountingStats;
use App\Support\RouterPortLayout;
use App\Support\TenantAccess;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class RoutersIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public int $step = 1;

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingRouterId = null;

    public ?int $deletingRouterId = null;

    public string $shop_id = '';

    public string $name = '';

    public string $nas_identifier = '';

    public string $wireguard_internal_ip = '';

    public string $shared_secret = '';

    public ?string $wireguard_public_key = null;

    public ?string $wireguard_endpoint_override_host = null;

    public ?string $wireguard_endpoint_override_port = null;

    public string $tunnel_mode = 'wireguard';

    public ?string $zerotier_node_id = null;

    public ?string $zerotier_ip = null;

    public bool $is_online = false;

    public array $provisioning_settings = [];

    public ?string $savedMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');

        if (request()->query('open') === 'create') {
            $this->create();
        } elseif (request()->query('open_edit')) {
            $this->edit((int) request()->query('open_edit'));
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function updatedProvisioningSettingsProfile(string $profile): void
    {
        $this->provisioning_settings = array_replace(
            $this->provisioning_settings,
            app(RouterManagementService::class)->defaultProvisioningSettings($profile)
        );
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $routerId): void
    {
        $router = Router::findOrFail($routerId);
        TenantAccess::assertRouter($router, auth()->user());

        $this->editingRouterId = $router->id;
        $this->shop_id = (string) $router->shop_id;
        $this->name = (string) $router->name;
        $this->nas_identifier = (string) $router->nas_identifier;
        $this->wireguard_internal_ip = (string) $router->wireguard_internal_ip;
        $this->wireguard_public_key = $router->wireguard_public_key;
        $this->wireguard_endpoint_override_host = $router->wireguard_endpoint_override_host;
        $this->wireguard_endpoint_override_port = $router->wireguard_endpoint_override_port !== null ? (string) $router->wireguard_endpoint_override_port : null;
        $this->tunnel_mode = (string) $router->tunnel_mode;
        $this->zerotier_node_id = $router->zerotier_node_id;
        $this->zerotier_ip = $router->zerotier_ip;
        $this->is_online = (bool) $router->is_online;
        $this->provisioning_settings = $this->routerProvisioningSettings($router);
        $this->shared_secret = '';
        $this->savedMessage = null;
        $this->step = 1;
        $this->showFormModal = true;
    }

    public function nextStep(RouterManagementService $routers): void
    {
        $router = $this->editingRouterId ? Router::find($this->editingRouterId) : null;
        $rules = $routers->rules(auth()->user(), $router, $this->provisioning_settings);

        $this->validate(Arr::only($rules, $this->stepFields($this->step)));

        $this->step = min(4, $this->step + 1);
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function updatedShopId(string $value): void
    {
        if ($this->editingRouterId || $this->nas_identifier !== '' || $value === '') {
            return;
        }

        $shop = Shop::find($value);

        if ($shop) {
            $this->nas_identifier = app(RouterManagementService::class)->suggestedNasIdentifier($shop);
        }
    }

    public function suggestWireguardIp(RouterManagementService $routers): void
    {
        $this->wireguard_internal_ip = $routers->suggestedWireguardInternalIp();
    }

    public function regenerateSharedSecret(RouterManagementService $routers): void
    {
        $this->shared_secret = $routers->suggestedSharedSecret();
    }

    public function useLocalWireguardEndpoint(): void
    {
        $host = config('services.wireguard.local_endpoint_host');

        if (blank($host)) {
            $this->addError('wireguard_endpoint_override_host', 'Set WIREGUARD_LOCAL_ENDPOINT_HOST on the Pi first, for example 192.168.188.159.');

            return;
        }

        $this->wireguard_endpoint_override_host = (string) $host;
        $this->wireguard_endpoint_override_port = (string) config('services.wireguard.endpoint_port', 13231);
    }

    public function clearWireguardEndpointOverride(): void
    {
        $this->wireguard_endpoint_override_host = null;
        $this->wireguard_endpoint_override_port = null;
    }

    public function suggestZeroTierIp(RouterManagementService $routers): void
    {
        $this->zerotier_ip = $routers->suggestedZeroTierIp();
    }

    /**
     * Convenience only, for a router whose WireGuard link is still working
     * (tunnel_mode=wireguard_zerotier) -- reads the node ID RouterOS
     * generated for itself the first time "/zerotier enable" ran, over the
     * still-functional WireGuard connection. A router with no working
     * WireGuard at all (tunnel_mode=zerotier) has no path for this and
     * needs the node ID entered manually instead -- see the field's
     * description in the wizard.
     */
    public function fetchZeroTierNodeId(RouterOsConnectionService $routerOs): void
    {
        if (! $this->editingRouterId) {
            $this->addError('zerotier_node_id', 'Save the router once with working WireGuard connectivity first, then fetch its ZeroTier node ID.');

            return;
        }

        $router = Router::find($this->editingRouterId);

        if (! $router || ! $routerOs->isConfigured($router)) {
            $this->addError('zerotier_node_id', 'RouterOS API credentials are not generated for this router yet.');

            return;
        }

        $result = $routerOs->runReadOnlyCommand($router, '/zerotier print');

        if (! $result['success'] || empty($result['rows'])) {
            $this->addError('zerotier_node_id', 'Could not read "/zerotier print" over WireGuard: '.($result['error'] ?? 'no rows returned -- has "/zerotier enable zt1" been run on the router yet?'));

            return;
        }

        $nodeId = $result['rows'][0]['address'] ?? null;

        if (blank($nodeId)) {
            $this->addError('zerotier_node_id', 'Router responded but no node-ID field was found in the output -- enter it manually from "/zerotier print" on the router console instead.');

            return;
        }

        $this->zerotier_node_id = (string) $nodeId;
    }

    public function setPreset(string $field, string $value): void
    {
        if ($field !== 'wireguard_internal_ip') {
            return;
        }

        $this->wireguard_internal_ip = $value;
    }

    public function setRouterLayoutPreset(string $preset): void
    {
        $settings = match ($preset) {
            'small_ap_24' => [
                'profile' => 'small_hotspot',
                'download_limit' => '80M',
                'upload_limit' => '15M',
                'hotspot_gateway' => '10.5.50.1/24',
                'hotspot_network' => '10.5.50.0/24',
                'hotspot_pool' => '10.5.50.10-10.5.50.250',
                'enable_pos' => true,
                'enable_pppoe' => false,
                'enable_realtime_qos' => true,
            ],
            default => [
                'profile' => 'starlink_plaza',
                'download_limit' => '120M',
                'upload_limit' => '20M',
                'hotspot_gateway' => '10.5.50.1/23',
                'hotspot_network' => '10.5.50.0/23',
                'hotspot_pool' => '10.5.50.10-10.5.51.250',
                'enable_pos' => true,
                'enable_pppoe' => true,
                'enable_realtime_qos' => true,
            ],
        };

        $this->provisioning_settings = array_replace($this->provisioning_settings, $settings);
    }

    public function setHotspotIpSize(string $size): void
    {
        $settings = match ($size) {
            '22' => [
                'hotspot_gateway' => '10.5.48.1/22',
                'hotspot_network' => '10.5.48.0/22',
                'hotspot_pool' => '10.5.48.10-10.5.51.250',
            ],
            '24' => [
                'hotspot_gateway' => '10.5.50.1/24',
                'hotspot_network' => '10.5.50.0/24',
                'hotspot_pool' => '10.5.50.10-10.5.50.250',
            ],
            default => [
                'hotspot_gateway' => '10.5.50.1/23',
                'hotspot_network' => '10.5.50.0/23',
                'hotspot_pool' => '10.5.50.10-10.5.51.250',
            ],
        };

        $this->provisioning_settings = array_replace($this->provisioning_settings, $settings);
    }

    public function save(RouterManagementService $routers): void
    {
        $router = $this->editingRouterId
            ? Router::findOrFail($this->editingRouterId)
            : null;

        // Blank optional inputs bind as "" from HTML, not null -- normalize before
        // validating so `nullable` short-circuits `integer`/etc. on an intentionally
        // empty field instead of failing validation.
        $this->wireguard_endpoint_override_host = $this->wireguard_endpoint_override_host !== '' ? $this->wireguard_endpoint_override_host : null;
        $this->wireguard_endpoint_override_port = $this->wireguard_endpoint_override_port !== '' ? $this->wireguard_endpoint_override_port : null;

        $data = $this->validate($routers->rules(auth()->user(), $router, $this->provisioning_settings));

        if ($router) {
            $routers->update($router, $data, auth()->user());
            $this->savedMessage = 'Router updated and synced to RADIUS nas.';
        } else {
            $routers->create($data, auth()->user());
            $this->savedMessage = 'Router created and synced to RADIUS nas.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $routerId): void
    {
        $router = Router::findOrFail($routerId);
        TenantAccess::assertRouter($router, auth()->user());

        $this->deletingRouterId = $router->id;
        $this->showDeleteModal = true;
    }

    public function delete(RouterManagementService $routers): void
    {
        if (! $this->deletingRouterId) {
            return;
        }

        $routers->delete(Router::findOrFail($this->deletingRouterId), auth()->user());

        $this->showDeleteModal = false;
        $this->deletingRouterId = null;
        $this->savedMessage = 'Router deleted.';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    public function render(RadiusAccountingStats $radiusStats, MikroTikProvisioningService $mikroTik)
    {
        $this->validateOnlyFilters();

        $user = auth()->user();
        $radiusStats->refreshRouterHealth(TenantAccess::scopeRouters(Router::with('shop.tenant'), $user)->get());

        $routers = TenantAccess::scopeRouters(Router::with('shop.tenant'), $user)
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('nas_identifier', 'like', "%{$this->search}%")
                        ->orWhere('wireguard_internal_ip', 'like', "%{$this->search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->status === 'online', fn ($query) => $query->where('is_online', true))
            ->when($this->status === 'offline', fn ($query) => $query->where('is_online', false))
            ->latest()
            ->paginate(15);

        return view('livewire.admin.routers-index', [
            'routers' => $routers,
            'shops' => $this->shops(),
            'infrastructureProfiles' => $mikroTik->infrastructureProfiles(),
            'billingUsage' => $this->editingRouterId ? null : BillingPlanLimits::usageSummary($user, 'routers'),
            'deletingRouter' => $this->deletingRouterId ? Router::find($this->deletingRouterId) : null,
            'localWireguardEndpointHost' => config('services.wireguard.local_endpoint_host'),
            'defaultWireguardEndpointHost' => config('services.wireguard.endpoint_host'),
            'defaultWireguardEndpointPort' => config('services.wireguard.endpoint_port'),
            'zerotierIpPrefix' => config('services.zerotier.ip_prefix'),
        ]);
    }

    private function shops(): Collection
    {
        return TenantAccess::scopeShops(Shop::with('tenant'), auth()->user())
            ->orderBy('name')
            ->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingRouterId',
            'shop_id',
            'name',
            'nas_identifier',
            'wireguard_internal_ip',
            'shared_secret',
            'wireguard_public_key',
            'wireguard_endpoint_override_host',
            'wireguard_endpoint_override_port',
            'zerotier_node_id',
            'zerotier_ip',
            'provisioning_settings',
        ]);
        $this->tunnel_mode = 'wireguard';
        $this->is_online = false;
        $this->step = 1;
        $this->provisioning_settings = app(RouterManagementService::class)->defaultProvisioningSettings();

        $routers = app(RouterManagementService::class);
        $this->wireguard_internal_ip = $routers->suggestedWireguardInternalIp();
        $this->shared_secret = $routers->suggestedSharedSecret();

        $this->resetValidation();
    }

    /**
     * Loads an existing router's saved settings and, when possible, reverse-derives
     * wan1_port_number/wan2_port_number/trunk_port_number/pi_port_number from its
     * saved "etherN" interface-name strings so the port-picker dropdowns open
     * pre-selected. Every currently-seeded default is "etherN", so this succeeds
     * for the overwhelming majority of existing routers; if any of the four
     * doesn't cleanly parse (e.g. a manually-typed "sfp-sfpplus1"), the router
     * opens in advanced/raw-text mode instead of guessing wrong.
     */
    private function routerProvisioningSettings(Router $router): array
    {
        $settings = array_replace(
            app(RouterManagementService::class)->defaultProvisioningSettings($router->provisioning_settings['profile'] ?? null),
            (array) $router->provisioning_settings
        );

        if (($settings['ports_advanced_mode'] ?? false) === true) {
            return $settings;
        }

        $roleStringKeys = [
            'wan1_port_number' => 'wan1',
            'wan2_port_number' => 'wan2',
            'trunk_port_number' => 'trunk_port',
            'pi_port_number' => 'pi_port',
        ];

        $parsedPortNumbers = [];

        foreach ($roleStringKeys as $numberKey => $stringKey) {
            $portNumber = RouterPortLayout::portNumberFromInterfaceName($settings[$stringKey] ?? null);

            if ($portNumber === null) {
                $settings['ports_advanced_mode'] = true;

                return $settings;
            }

            $parsedPortNumbers[$numberKey] = $portNumber;
        }

        $settings = array_merge($settings, $parsedPortNumbers);
        $settings['port_count'] = max($parsedPortNumbers) + 2;

        return $settings;
    }

    private function stepFields(int $step): array
    {
        return match ($step) {
            1 => [
                'shop_id', 'name', 'nas_identifier', 'wireguard_internal_ip',
                'shared_secret', 'wireguard_endpoint_override_host', 'wireguard_endpoint_override_port',
                'tunnel_mode', 'zerotier_node_id', 'zerotier_ip',
            ],
            2 => [
                'provisioning_settings.enable_builtin_wifi', 'provisioning_settings.enable_second_wan',
                'provisioning_settings.port_count', 'provisioning_settings.ports_advanced_mode',
                'provisioning_settings.wan1_port_number', 'provisioning_settings.wan2_port_number',
                'provisioning_settings.trunk_port_number', 'provisioning_settings.pi_port_number',
                'provisioning_settings.wan1', 'provisioning_settings.wan2',
                'provisioning_settings.trunk_port', 'provisioning_settings.pi_port',
            ],
            3 => [
                'provisioning_settings.enable_staff', 'provisioning_settings.enable_pos',
                'provisioning_settings.enable_mgmt_wifi', 'provisioning_settings.enable_pppoe',
                'provisioning_settings.enable_realtime_qos', 'provisioning_settings.download_limit',
                'provisioning_settings.upload_limit', 'provisioning_settings.builtin_wifi_interface',
                'provisioning_settings.staff_wifi_password', 'provisioning_settings.pos_wifi_password',
                'provisioning_settings.mgmt_wifi_password',
            ],
            4 => [
                'provisioning_settings.profile',
                'provisioning_settings.mgmt_vlan', 'provisioning_settings.hotspot_vlan',
                'provisioning_settings.staff_vlan', 'provisioning_settings.pppoe_vlan', 'provisioning_settings.pos_vlan',
                'provisioning_settings.mgmt_gateway', 'provisioning_settings.mgmt_network', 'provisioning_settings.mgmt_pool',
                'provisioning_settings.hotspot_gateway', 'provisioning_settings.hotspot_network', 'provisioning_settings.hotspot_pool',
                'provisioning_settings.staff_gateway', 'provisioning_settings.staff_network', 'provisioning_settings.staff_pool',
                'provisioning_settings.pos_gateway', 'provisioning_settings.pos_network', 'provisioning_settings.pos_pool',
                'provisioning_settings.pppoe_gateway',
            ],
            default => [],
        };
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'status' => $this->status ?: null,
        ], [
            'status' => ['nullable', Rule::in(['online', 'offline'])],
        ])->validate();
    }
}
