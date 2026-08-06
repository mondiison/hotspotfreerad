<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Models\Shop;
use App\Services\MikroTikProvisioningService;
use App\Services\RouterManagementService;
use App\Support\BillingPlanLimits;
use App\Support\RadiusAccountingStats;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class RoutersIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

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
        $this->is_online = (bool) $router->is_online;
        $this->provisioning_settings = $this->routerProvisioningSettings($router);
        $this->shared_secret = '';
        $this->savedMessage = null;
        $this->showFormModal = true;
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
            'l009_lab_wifi' => [
                'profile' => 'l009_builtin_wifi',
                'wan1' => 'ether1',
                'wan2' => 'ether7',
                'trunk_port' => 'ether2',
                'pi_port' => 'ether3',
                'builtin_wifi_interface' => 'wifi1',
                'staff_wifi_password' => 'MmsStaff2026!',
                'pos_wifi_password' => 'MmsPos2026!',
                'mgmt_wifi_password' => 'MmsMgmt2026!',
                'download_limit' => '80M',
                'upload_limit' => '15M',
                'hotspot_gateway' => '10.5.50.1/24',
                'hotspot_network' => '10.5.50.0/24',
                'hotspot_pool' => '10.5.50.10-10.5.50.250',
                'enable_builtin_wifi' => true,
                'enable_pos' => true,
                'enable_pppoe' => false,
                'enable_realtime_qos' => true,
                'enable_second_wan' => false,
            ],
            'small_ap_24' => [
                'profile' => 'small_hotspot',
                'wan1' => 'ether1',
                'wan2' => 'ether8',
                'trunk_port' => 'ether2',
                'pi_port' => 'ether3',
                'staff_wifi_password' => 'MmsStaff2026!',
                'pos_wifi_password' => 'MmsPos2026!',
                'mgmt_wifi_password' => 'MmsMgmt2026!',
                'download_limit' => '80M',
                'upload_limit' => '15M',
                'hotspot_gateway' => '10.5.50.1/24',
                'hotspot_network' => '10.5.50.0/24',
                'hotspot_pool' => '10.5.50.10-10.5.50.250',
                'enable_builtin_wifi' => false,
                'enable_pos' => true,
                'enable_pppoe' => false,
                'enable_realtime_qos' => true,
            ],
            default => [
                'profile' => 'starlink_plaza',
                'wan1' => 'ether1',
                'wan2' => 'ether8',
                'trunk_port' => 'ether2',
                'pi_port' => 'ether3',
                'builtin_wifi_interface' => 'wifi1',
                'staff_wifi_password' => 'MmsStaff2026!',
                'pos_wifi_password' => 'MmsPos2026!',
                'mgmt_wifi_password' => 'MmsMgmt2026!',
                'download_limit' => '120M',
                'upload_limit' => '20M',
                'hotspot_gateway' => '10.5.50.1/23',
                'hotspot_network' => '10.5.50.0/23',
                'hotspot_pool' => '10.5.50.10-10.5.51.250',
                'enable_builtin_wifi' => false,
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

        $data = $this->validate($routers->rules(auth()->user(), $router));

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
            'provisioning_settings',
        ]);
        $this->is_online = false;
        $this->provisioning_settings = app(RouterManagementService::class)->defaultProvisioningSettings();

        $routers = app(RouterManagementService::class);
        $this->wireguard_internal_ip = $routers->suggestedWireguardInternalIp();
        $this->shared_secret = $routers->suggestedSharedSecret();

        $this->resetValidation();
    }

    private function routerProvisioningSettings(Router $router): array
    {
        return array_replace(
            app(RouterManagementService::class)->defaultProvisioningSettings($router->provisioning_settings['profile'] ?? null),
            (array) $router->provisioning_settings
        );
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
