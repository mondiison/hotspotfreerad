<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PosDevice;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PosDeviceManagementService
{
    public function __construct(private readonly RadiusProvisioningService $radius) {}

    public function rules(User $user, ?PosDevice $device = null): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'package_id' => [
                'required',
                Rule::exists('packages', 'id')
                    ->whereIn('service_type', ['hotspot', 'both'])
                    ->where('is_active', true),
            ],
            'device_name' => ['required', 'string', 'max:255'],
            'mac_address' => ['required', 'string', 'max:32', 'regex:/^([A-Fa-f0-9]{2}[:-]?){5}[A-Fa-f0-9]{2}$/', Rule::unique('pos_devices')->ignore($device)],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function create(array $data, User $user): PosDevice
    {
        $device = PosDevice::create($this->normalize($data, $user));
        $this->syncSystem($device);

        return $device;
    }

    public function update(PosDevice $device, array $data, User $user): PosDevice
    {
        TenantAccess::assertPosDevice($device, $user);

        $oldMacAddress = $device->mac_address;
        $device->update($this->normalize($data, $user, $device));

        if ($oldMacAddress !== $device->mac_address) {
            $this->radius->revokeMacAccess($oldMacAddress);
        }

        return $this->syncSystem($device);
    }

    public function renew(PosDevice $device, User $user): PosDevice
    {
        TenantAccess::assertPosDevice($device, $user);
        $device->loadMissing('package');

        $startsAt = now();
        $baseExpiry = $device->expires_at?->isFuture() ? $device->expires_at : $startsAt;

        $device->forceFill([
            'starts_at' => $device->starts_at ?: $startsAt,
            'expires_at' => $baseExpiry->copy()->addSeconds((int) $device->package->limit_uptime_seconds),
            'is_active' => true,
        ])->save();

        return $this->syncSystem($device);
    }

    public function sync(PosDevice $device, User $user): PosDevice
    {
        TenantAccess::assertPosDevice($device, $user);

        return $this->syncSystem($device);
    }

    public function syncSystem(PosDevice $device): PosDevice
    {
        if ($device->isCurrentlyActive()) {
            $this->radius->provisionPosDevice($device);
        } else {
            $this->radius->revokePosDevice($device);
        }

        return $device->refresh();
    }

    public function delete(PosDevice $device, User $user): void
    {
        TenantAccess::assertPosDevice($device, $user);
        $this->radius->revokePosDevice($device);
        $device->delete();
    }

    private function normalize(array $data, User $user, ?PosDevice $device = null): array
    {
        $shop = TenantAccess::scopeShops(Shop::query(), $user)->whereKey($data['shop_id'])->firstOrFail();
        $package = TenantAccess::scopePackages(Package::query(), $user)
            ->whereKey($data['package_id'])
            ->where('shop_id', $shop->id)
            ->whereIn('service_type', ['hotspot', 'both'])
            ->where('is_active', true)
            ->firstOrFail();

        $startsAt = filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at']) : now();

        $data['shop_id'] = $shop->id;
        $data['package_id'] = $package->id;
        $data['mac_address'] = $this->normalizeMacAddress((string) $data['mac_address']);
        $data['starts_at'] = $startsAt;
        $data['expires_at'] = filled($data['expires_at'] ?? null)
            ? Carbon::parse($data['expires_at'])
            : $startsAt->copy()->addSeconds((int) $package->limit_uptime_seconds);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        foreach (['owner_name', 'phone', 'email'] as $field) {
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
