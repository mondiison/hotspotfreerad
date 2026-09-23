<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Payment;
use App\Models\PosDevice;
use App\Models\Shop;
use App\Models\User;
use App\Support\PaymentCommission;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PosDeviceManagementService
{
    /**
     * Provider tag for a POS payment recorded here -- distinct from
     * PaymentGatewayCatalog::MANUAL_BANK ('manual_bank'), which is a
     * customer-facing hosted-checkout gateway a hotspot customer pays
     * through directly. This one is never charged online at all: POS device
     * registration/renewal is an admin-only action (docs/current-project-status.md
     * already listed "Add POS payment tracking when tenant sells/renews POS
     * access" as planned, unbuilt work) where the tenant collects payment
     * from the terminal owner outside the app (cash, bank transfer, etc.)
     * and just needs an accounting record -- so this Payment row is created
     * already `status=successful`/`paid_at=now()`, never routed through
     * verifyAndGrant()'s gateway-verification flow the way a real online
     * payment is. Not registered in PaymentGatewayCatalog (that catalog is
     * specifically for online checkout gateways) -- PaymentReportService::
     * providerLabel()'s fallback already renders an unregistered provider
     * as a readable "Pos Manual" without needing an entry there.
     */
    public const POS_PAYMENT_PROVIDER = 'pos_manual';

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
        $device->loadMissing('shop', 'package');
        $this->recordPayment($device, $user);
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
        $device->loadMissing('package', 'shop');

        $startsAt = now();
        $baseExpiry = $device->expires_at?->isFuture() ? $device->expires_at : $startsAt;

        $device->forceFill([
            'starts_at' => $device->starts_at ?: $startsAt,
            'expires_at' => $baseExpiry->copy()->addSeconds((int) $device->package->limit_uptime_seconds),
            'is_active' => true,
        ])->save();

        $this->recordPayment($device, $user);

        return $this->syncSystem($device);
    }

    /**
     * Records the payment collected for one registration/renewal cycle --
     * the amount is always the selected package's own price, never a
     * freely-typed figure, so it can't drift from what the package actually
     * lists. Reuses PaymentCommission::forShop() so a POS payment folds into
     * the same commission/wallet math and Sales/Payment reports every other
     * Payment row already does (SalesReportService::query() only filters on
     * status=successful, with no provider restriction, so this shows up
     * there with zero report-side changes needed).
     */
    private function recordPayment(PosDevice $device, User $user): Payment
    {
        $price = (float) $device->package->price;
        $commission = PaymentCommission::forShop($device->shop, $price);

        return Payment::create([
            'shop_id' => $device->shop_id,
            'package_id' => $device->package_id,
            'pos_device_id' => $device->id,
            'provider' => self::POS_PAYMENT_PROVIDER,
            'tx_ref' => 'POS-'.$device->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'amount' => $price,
            'currency' => $device->package->currency,
            'status' => 'successful',
            'paid_at' => now(),
            'gross_amount' => $commission['gross_amount'],
            'platform_fee_amount' => $commission['platform_fee_amount'],
            'tenant_net_amount' => $commission['tenant_net_amount'],
            'commission_rate' => $commission['commission_rate'],
            'billing_model' => $commission['billing_model'],
            'payload' => ['recorded_by_user_id' => $user->id, 'device_name' => $device->device_name],
        ]);
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
