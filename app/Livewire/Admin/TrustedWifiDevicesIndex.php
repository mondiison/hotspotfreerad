<?php

namespace App\Livewire\Admin;

use App\Models\Shop;
use App\Models\TrustedWifiDevice;
use App\Services\TrustedWifiDeviceManagementService;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class TrustedWifiDevicesIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $networkFilter = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingDeviceId = null;

    public ?int $deletingDeviceId = null;

    public string $shop_id = '';

    public string $network = TrustedWifiDevice::NETWORK_STAFF;

    public string $device_name = '';

    public string $mac_address = '';

    public string $owner_name = '';

    public string $notes = '';

    public string $expires_at = '';

    public bool $is_active = true;

    public ?string $savedMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'networkFilter' => ['as' => 'network', 'except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->search = (string) ($filters['search'] ?? '');
        $this->networkFilter = (string) ($filters['network'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'networkFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $firstShop = $this->shops()->first();
        $this->shop_id = $firstShop ? (string) $firstShop->id : '';
        $this->showFormModal = true;
    }

    public function edit(int $deviceId): void
    {
        $device = TrustedWifiDevice::with('shop')->findOrFail($deviceId);
        TenantAccess::assertTrustedWifiDevice($device, auth()->user());

        $this->editingDeviceId = $device->id;
        $this->shop_id = (string) $device->shop_id;
        $this->network = (string) $device->network;
        $this->device_name = (string) $device->device_name;
        $this->mac_address = (string) $device->mac_address;
        $this->owner_name = (string) $device->owner_name;
        $this->notes = (string) $device->notes;
        $this->expires_at = $device->expires_at?->format('Y-m-d\TH:i') ?? '';
        $this->is_active = (bool) $device->is_active;
        $this->showFormModal = true;
    }

    public function save(TrustedWifiDeviceManagementService $devices): void
    {
        $device = $this->editingDeviceId ? TrustedWifiDevice::findOrFail($this->editingDeviceId) : null;

        $data = $this->validate($devices->rules(auth()->user(), $device));

        if ($device) {
            $devices->update($device, $data, auth()->user());
            $this->savedMessage = 'Trusted device updated and synced to RADIUS.';
        } else {
            $devices->create($data, auth()->user());
            $this->savedMessage = 'Trusted device registered and synced to RADIUS.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage();
    }

    public function sync(int $deviceId, TrustedWifiDeviceManagementService $devices): void
    {
        $device = TrustedWifiDevice::findOrFail($deviceId);
        $devices->sync($device, auth()->user());

        $this->savedMessage = 'Trusted device synced to RADIUS.';
    }

    public function confirmDelete(int $deviceId): void
    {
        $device = TrustedWifiDevice::findOrFail($deviceId);
        TenantAccess::assertTrustedWifiDevice($device, auth()->user());

        $this->deletingDeviceId = $device->id;
        $this->showDeleteModal = true;
    }

    public function delete(TrustedWifiDeviceManagementService $devices): void
    {
        if (! $this->deletingDeviceId) {
            return;
        }

        $devices->delete(TrustedWifiDevice::findOrFail($this->deletingDeviceId), auth()->user());

        $this->showDeleteModal = false;
        $this->deletingDeviceId = null;
        $this->savedMessage = 'Trusted device removed.';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'networkFilter']);
        $this->resetPage();
    }

    public function render()
    {
        $this->validateOnlyFilters();

        $query = TenantAccess::scopeTrustedWifiDevices(TrustedWifiDevice::with('shop.tenant'), auth()->user())
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query
                        ->where('device_name', 'like', "%{$this->search}%")
                        ->orWhere('mac_address', 'like', "%{$this->search}%")
                        ->orWhere('owner_name', 'like', "%{$this->search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->networkFilter, fn ($query) => $query->where('network', $this->networkFilter));

        return view('livewire.admin.trusted-wifi-devices-index', [
            'devices' => $query->latest()->paginate(15),
            'shops' => $this->shops(),
            'deletingDevice' => $this->deletingDeviceId ? TrustedWifiDevice::find($this->deletingDeviceId) : null,
        ]);
    }

    private function shops(): Collection
    {
        return TenantAccess::scopeShops(Shop::with('tenant'), auth()->user())->orderBy('name')->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingDeviceId',
            'shop_id',
            'device_name',
            'mac_address',
            'owner_name',
            'notes',
            'expires_at',
            'is_active',
        ]);
        $this->network = TrustedWifiDevice::NETWORK_STAFF;
        $this->is_active = true;
        $this->resetValidation();
    }

    private function validateOnlyFilters(): void
    {
        validator([
            'networkFilter' => $this->networkFilter ?: null,
        ], [
            'networkFilter' => ['nullable', Rule::in([TrustedWifiDevice::NETWORK_STAFF, TrustedWifiDevice::NETWORK_MGMT])],
        ])->validate();
    }
}
