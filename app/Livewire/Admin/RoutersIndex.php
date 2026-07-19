<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Models\Shop;
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

    public bool $is_online = false;

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
        $this->is_online = (bool) $router->is_online;
        $this->shared_secret = '';
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function setPreset(string $field, string $value): void
    {
        if ($field !== 'wireguard_internal_ip') {
            return;
        }

        $this->wireguard_internal_ip = $value;
    }

    public function save(RouterManagementService $routers): void
    {
        $router = $this->editingRouterId
            ? Router::findOrFail($this->editingRouterId)
            : null;

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

    public function render(RadiusAccountingStats $radiusStats)
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
        ]);
        $this->is_online = false;
        $this->resetValidation();
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
