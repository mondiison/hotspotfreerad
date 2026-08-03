<?php

namespace App\Livewire\Admin;

use App\Models\Package;
use App\Models\PosDevice;
use App\Models\Shop;
use App\Services\PosDeviceManagementService;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class PosDevicesIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingDeviceId = null;

    public ?int $deletingDeviceId = null;

    public string $shop_id = '';

    public string $package_id = '';

    public string $device_name = '';

    public string $mac_address = '';

    public string $owner_name = '';

    public string $phone = '';

    public string $email = '';

    public string $starts_at = '';

    public string $expires_at = '';

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

    public function updatedShopId(): void
    {
        $this->package_id = '';
    }

    public function create(): void
    {
        $this->resetForm();
        $firstShop = $this->shops()->first();
        $this->shop_id = $firstShop ? (string) $firstShop->id : '';
        $this->starts_at = now()->format('Y-m-d\TH:i');
        $this->showFormModal = true;
    }

    public function edit(int $deviceId): void
    {
        $device = PosDevice::with(['shop', 'package'])->findOrFail($deviceId);
        TenantAccess::assertPosDevice($device, auth()->user());

        $this->editingDeviceId = $device->id;
        $this->shop_id = (string) $device->shop_id;
        $this->package_id = (string) $device->package_id;
        $this->device_name = (string) $device->device_name;
        $this->mac_address = (string) $device->mac_address;
        $this->owner_name = (string) $device->owner_name;
        $this->phone = (string) $device->phone;
        $this->email = (string) $device->email;
        $this->starts_at = $device->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->expires_at = $device->expires_at?->format('Y-m-d\TH:i') ?? '';
        $this->is_active = (bool) $device->is_active;
        $this->showFormModal = true;
    }

    public function save(PosDeviceManagementService $devices): void
    {
        $device = $this->editingDeviceId ? PosDevice::findOrFail($this->editingDeviceId) : null;
        $data = $this->validate($devices->rules(auth()->user(), $device));

        if ($device) {
            $devices->update($device, $data, auth()->user());
            $this->savedMessage = 'POS device updated and synced to RADIUS.';
        } else {
            $devices->create($data, auth()->user());
            $this->savedMessage = 'POS device registered and synced to RADIUS.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function renew(int $deviceId, PosDeviceManagementService $devices): void
    {
        $device = PosDevice::with('package')->findOrFail($deviceId);
        $devices->renew($device, auth()->user());

        $this->savedMessage = 'POS access renewed and synced to RADIUS.';
    }

    public function sync(int $deviceId, PosDeviceManagementService $devices): void
    {
        $device = PosDevice::with('package')->findOrFail($deviceId);
        $devices->sync($device, auth()->user());

        $this->savedMessage = 'POS device synced to RADIUS.';
    }

    public function confirmDelete(int $deviceId): void
    {
        $device = PosDevice::findOrFail($deviceId);
        TenantAccess::assertPosDevice($device, auth()->user());

        $this->deletingDeviceId = $device->id;
        $this->showDeleteModal = true;
    }

    public function delete(PosDeviceManagementService $devices): void
    {
        if (! $this->deletingDeviceId) {
            return;
        }

        $devices->delete(PosDevice::findOrFail($this->deletingDeviceId), auth()->user());

        $this->showDeleteModal = false;
        $this->deletingDeviceId = null;
        $this->savedMessage = 'POS device removed from RADIUS.';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    public function render()
    {
        $this->validateOnlyFilters();

        $query = TenantAccess::scopePosDevices(PosDevice::with(['shop.tenant', 'package']), auth()->user())
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('device_name', 'like', "%{$this->search}%")
                        ->orWhere('mac_address', 'like', "%{$this->search}%")
                        ->orWhere('owner_name', 'like', "%{$this->search}%")
                        ->orWhere('phone', 'like', "%{$this->search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$this->search}%"));
                });
            });

        $summary = $this->summary(clone $query);

        $query
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->when($this->status === 'expiring_soon', fn ($query) => $query->where('is_active', true)->whereBetween('expires_at', [now(), now()->addDays(7)]))
            ->when($this->status === 'expired', fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->when($this->status === 'disabled', fn ($query) => $query->where('is_active', false))
            ->when($this->status === 'unsynced', fn ($query) => $query->whereNull('last_provisioned_at'));

        return view('livewire.admin.pos-devices-index', [
            'devices' => $query->latest()->paginate(15),
            'summary' => $summary,
            'shops' => $this->shops(),
            'packages' => $this->packages(),
            'deletingDevice' => $this->deletingDeviceId ? PosDevice::find($this->deletingDeviceId) : null,
        ]);
    }

    private function summary($query): array
    {
        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'expiring_soon' => (clone $query)->where('is_active', true)->whereBetween('expires_at', [now(), now()->addDays(7)])->count(),
            'expired' => (clone $query)->whereNotNull('expires_at')->where('expires_at', '<=', now())->count(),
            'disabled' => (clone $query)->where('is_active', false)->count(),
            'unsynced' => (clone $query)->whereNull('last_provisioned_at')->count(),
        ];
    }

    private function shops(): Collection
    {
        return TenantAccess::scopeShops(Shop::with('tenant'), auth()->user())->orderBy('name')->get();
    }

    private function packages(): Collection
    {
        $query = TenantAccess::scopePackages(Package::with('shop.tenant'), auth()->user())
            ->where('is_active', true)
            ->whereIn('service_type', ['hotspot', 'both'])
            ->orderBy('name');

        if ($this->shop_id) {
            $query->where('shop_id', $this->shop_id);
        }

        return $query->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingDeviceId',
            'shop_id',
            'package_id',
            'device_name',
            'mac_address',
            'owner_name',
            'phone',
            'email',
            'starts_at',
            'expires_at',
            'is_active',
        ]);
        $this->is_active = true;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'status' => $this->status ?: null,
        ], [
            'status' => ['nullable', Rule::in(['active', 'expiring_soon', 'expired', 'disabled', 'unsynced'])],
        ])->validate();
    }
}
