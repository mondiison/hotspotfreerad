<?php

namespace App\Livewire\Admin;

use App\Models\Package as InternetPackage;
use App\Models\Shop;
use App\Services\PackageManagementService;
use App\Support\BillingPlanLimits;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class PackagesIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingPackageId = null;

    public ?int $deletingPackageId = null;

    public string $shop_id = '';

    public string $name = '';

    public string $radius_group_name = '';

    public string $price = '';

    public string $currency = 'NGN';

    public string $limit_uptime_seconds = '86400';

    public string $data_limit_bytes = '';

    public string $speed_limit_profile = '';

    public string $fup_data_threshold_bytes = '';

    public string $fup_speed_limit_profile = '';

    public bool $is_active = true;

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

    public function edit(int $packageId): void
    {
        $package = InternetPackage::findOrFail($packageId);
        TenantAccess::assertPackage($package, auth()->user());

        $this->editingPackageId = $package->id;
        $this->shop_id = (string) $package->shop_id;
        $this->name = (string) $package->name;
        $this->radius_group_name = (string) $package->radius_group_name;
        $this->price = (string) $package->price;
        $this->currency = (string) $package->currency;
        $this->limit_uptime_seconds = (string) $package->limit_uptime_seconds;
        $this->data_limit_bytes = (string) $package->data_limit_bytes;
        $this->speed_limit_profile = (string) $package->speed_limit_profile;
        $this->fup_data_threshold_bytes = (string) $package->fup_data_threshold_bytes;
        $this->fup_speed_limit_profile = (string) $package->fup_speed_limit_profile;
        $this->is_active = (bool) $package->is_active;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function setPreset(string $field, string $value): void
    {
        if (! in_array($field, ['limit_uptime_seconds', 'data_limit_bytes', 'speed_limit_profile', 'fup_data_threshold_bytes', 'fup_speed_limit_profile'], true)) {
            return;
        }

        $this->{$field} = $value;
    }

    public function save(PackageManagementService $packages): void
    {
        $package = $this->editingPackageId ? InternetPackage::findOrFail($this->editingPackageId) : null;

        $data = $this->validate($packages->rules(auth()->user(), $package));

        try {
            if ($package) {
                $packages->update($package, $data, auth()->user());
                $this->savedMessage = 'Package updated and synced to RADIUS profile.';
            } else {
                $packages->create($data, auth()->user());
                $this->savedMessage = 'Package created and synced to RADIUS profile.';
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0] ?? 'Unable to save package.');
            }

            return;
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $packageId): void
    {
        $package = InternetPackage::findOrFail($packageId);
        TenantAccess::assertPackage($package, auth()->user());

        $this->deletingPackageId = $package->id;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deletingPackageId) {
            return;
        }

        $package = InternetPackage::findOrFail($this->deletingPackageId);
        TenantAccess::assertPackage($package, auth()->user());
        $package->delete();

        $this->showDeleteModal = false;
        $this->deletingPackageId = null;
        $this->savedMessage = 'Package deleted.';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    public function render()
    {
        $this->validateOnlyFilters();

        $user = auth()->user();

        $packages = TenantAccess::scopePackages(InternetPackage::with('shop.tenant'), $user)
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('radius_group_name', 'like', "%{$this->search}%")
                        ->orWhere('speed_limit_profile', 'like', "%{$this->search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->latest()
            ->paginate(15);

        return view('livewire.admin.packages-index', [
            'packages' => $packages,
            'shops' => $this->shops(),
            'billingUsage' => $this->editingPackageId ? null : BillingPlanLimits::usageSummary($user, 'packages'),
            'deletingPackage' => $this->deletingPackageId ? InternetPackage::find($this->deletingPackageId) : null,
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
            'editingPackageId',
            'shop_id',
            'name',
            'radius_group_name',
            'price',
            'data_limit_bytes',
            'speed_limit_profile',
            'fup_data_threshold_bytes',
            'fup_speed_limit_profile',
        ]);
        $this->currency = 'NGN';
        $this->limit_uptime_seconds = '86400';
        $this->is_active = true;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator(['status' => $this->status ?: null], [
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ])->validate();
    }
}
