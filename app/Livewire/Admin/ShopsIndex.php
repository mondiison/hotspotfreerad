<?php

namespace App\Livewire\Admin;

use App\Models\Shop;
use App\Models\Tenant;
use App\Services\ShopManagementService;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class ShopsIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $payments = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingShopId = null;

    public ?int $deletingShopId = null;

    public string $tenant_id = '';

    public string $name = '';

    public string $location_city = '';

    public string $flutterwave_client_id = '';

    public string $flutterwave_client_secret = '';

    public string $flutterwave_webhook_secret = '';

    public bool $is_active = true;

    public ?string $savedMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'payments' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->payments = (string) ($filters['payments'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'payments'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->tenant_id = (string) (auth()->user()->isSuperAdmin() ? '' : auth()->user()->tenant_id);
        $this->showFormModal = true;
    }

    public function edit(int $shopId): void
    {
        $shop = Shop::findOrFail($shopId);
        TenantAccess::assertShop($shop, auth()->user());

        $this->editingShopId = $shop->id;
        $this->tenant_id = (string) $shop->tenant_id;
        $this->name = (string) $shop->name;
        $this->location_city = (string) $shop->location_city;
        $this->is_active = (bool) $shop->is_active;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function save(ShopManagementService $shops): void
    {
        $data = $this->validate($shops->rules());

        if ($this->editingShopId) {
            $shops->update(Shop::findOrFail($this->editingShopId), $data, auth()->user());
            $this->savedMessage = 'Shop updated.';
        } else {
            $shops->create($data, auth()->user());
            $this->savedMessage = 'Shop created.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $shopId): void
    {
        $shop = Shop::findOrFail($shopId);
        TenantAccess::assertShop($shop, auth()->user());

        $this->deletingShopId = $shop->id;
        $this->showDeleteModal = true;
    }

    public function delete(ShopManagementService $shops): void
    {
        if (! $this->deletingShopId) {
            return;
        }

        $shops->delete(Shop::findOrFail($this->deletingShopId), auth()->user());

        $this->showDeleteModal = false;
        $this->deletingShopId = null;
        $this->savedMessage = 'Shop deleted.';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'payments']);
        $this->resetPage();
    }

    public function render()
    {
        $this->validateOnlyFilters();

        $user = auth()->user();

        $shops = TenantAccess::scopeShops(Shop::with('tenant')->withCount(['routers', 'packages']), $user)
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('location_city', 'like', "%{$this->search}%")
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('company_name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($this->payments === 'configured', fn ($query) => $query
                ->whereNotNull('flutterwave_client_id')
                ->whereNotNull('flutterwave_client_secret'))
            ->when($this->payments === 'unconfigured', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->whereNull('flutterwave_client_id')
                    ->orWhereNull('flutterwave_client_secret')))
            ->latest()
            ->paginate(15);

        return view('livewire.admin.shops-index', [
            'shops' => $shops,
            'tenants' => $this->tenants(),
            'billingUsage' => $this->editingShopId ? null : BillingPlanLimits::usageSummary($user, 'shops'),
            'deletingShop' => $this->deletingShopId ? Shop::find($this->deletingShopId) : null,
        ]);
    }

    private function tenants(): Collection
    {
        $user = auth()->user();

        return $user->isSuperAdmin()
            ? Tenant::orderBy('company_name')->get()
            : Tenant::whereKey($user->tenant_id)->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingShopId',
            'tenant_id',
            'name',
            'location_city',
            'flutterwave_client_id',
            'flutterwave_client_secret',
            'flutterwave_webhook_secret',
        ]);
        $this->is_active = true;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'status' => $this->status ?: null,
            'payments' => $this->payments ?: null,
        ], [
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'payments' => ['nullable', Rule::in(['configured', 'unconfigured'])],
        ])->validate();
    }
}
