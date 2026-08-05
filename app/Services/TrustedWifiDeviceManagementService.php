<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\TrustedWifiDevice;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TrustedWifiDeviceManagementService
{
    public function __construct(private readonly RadiusProvisioningService $radius) {}

    public function rules(User $user, ?TrustedWifiDevice $device = null): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'network' => ['required', Rule::in([TrustedWifiDevice::NETWORK_STAFF, TrustedWifiDevice::NETWORK_MGMT])],
            'device_name' => ['required', 'string', 'max:255'],
            'mac_address' => ['required', 'string', 'max:32', 'regex:/^([A-Fa-f0-9]{2}[:-]?){5}[A-Fa-f0-9]{2}$/', Rule::unique('trusted_wifi_devices')->ignore($device)],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function create(array $data, User $user): TrustedWifiDevice
    {
        $device = TrustedWifiDevice::create($this->normalize($data, $user));
        $this->syncSystem($device);

        return $device;
    }

    public function update(TrustedWifiDevice $device, array $data, User $user): TrustedWifiDevice
    {
        TenantAccess::assertTrustedWifiDevice($device, $user);

        $oldMacAddress = $device->mac_address;
        $device->update($this->normalize($data, $user, $device));

        if ($oldMacAddress !== $device->mac_address) {
            $this->radius->revokeMacAccess($oldMacAddress);
        }

        return $this->syncSystem($device);
    }

    public function sync(TrustedWifiDevice $device, User $user): TrustedWifiDevice
    {
        TenantAccess::assertTrustedWifiDevice($device, $user);

        return $this->syncSystem($device);
    }

    public function syncSystem(TrustedWifiDevice $device): TrustedWifiDevice
    {
        if ($device->isCurrentlyActive()) {
            $this->radius->provisionTrustedWifiDevice($device);
        } else {
            $this->radius->revokeTrustedWifiDevice($device);
        }

        return $device->refresh();
    }

    public function delete(TrustedWifiDevice $device, User $user): void
    {
        TenantAccess::assertTrustedWifiDevice($device, $user);
        $this->radius->revokeTrustedWifiDevice($device);
        $device->delete();
    }

    private function normalize(array $data, User $user, ?TrustedWifiDevice $device = null): array
    {
        $shop = TenantAccess::scopeShops(Shop::query(), $user)->whereKey($data['shop_id'])->firstOrFail();

        $data['shop_id'] = $shop->id;
        $data['mac_address'] = $this->normalizeMacAddress((string) $data['mac_address']);
        $data['expires_at'] = filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        foreach (['owner_name', 'notes'] as $field) {
            if (blank($data[$field] ?? null)) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function normalizeMacAddress(string $macAddress): string
    {
        $hex = str($macAddress)
            ->upper()
            ->replaceMatches('/[^A-F0-9]/', '')
            ->toString();

        return collect(str_split($hex, 2))->implode(':');
    }
}
