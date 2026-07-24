<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VoucherManagementService
{
    private const ACCESS_PASSWORD = 'authenticated_device_pass';

    public function __construct(private readonly RadiusProvisioningService $radius) {}

    public function rules(User $user): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'package_id' => [
                'required',
                Rule::exists('packages', 'id')
                    ->whereIn('service_type', ['hotspot', 'both'])
                    ->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'code_length' => ['required', 'integer', 'min:6', 'max:16'],
            'prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function createBatch(array $data, User $user): VoucherBatch
    {
        $shop = TenantAccess::scopeShops(\App\Models\Shop::query(), $user)->findOrFail($data['shop_id']);
        $package = TenantAccess::scopePackages(Package::query(), $user)
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->whereIn('service_type', ['hotspot', 'both'])
            ->findOrFail($data['package_id']);

        return DB::transaction(function () use ($data, $shop, $package): VoucherBatch {
            $batch = VoucherBatch::create([
                'shop_id' => $shop->id,
                'package_id' => $package->id,
                'name' => $data['name'],
                'quantity' => (int) $data['quantity'],
                'code_length' => (int) $data['code_length'],
                'prefix' => filled($data['prefix'] ?? null) ? Str::upper((string) $data['prefix']) : null,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->generateCodes($batch)->each(fn (string $code) => Voucher::create([
                'voucher_batch_id' => $batch->id,
                'shop_id' => $shop->id,
                'package_id' => $package->id,
                'code' => $code,
                'status' => 'unused',
            ]));

            return $batch;
        });
    }

    public function redeem(Router $router, string $macAddress, string $code): Subscription
    {
        return DB::transaction(function () use ($router, $macAddress, $code): Subscription {
            $voucher = Voucher::query()
                ->with(['package', 'shop.tenant'])
                ->where('code', $this->normalizeCode($code))
                ->lockForUpdate()
                ->first();

            if (! $voucher || (int) $voucher->shop_id !== (int) $router->shop_id) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'Voucher code was not found for this hotspot.',
                ]);
            }

            if ($voucher->status !== 'unused') {
                throw ValidationException::withMessages([
                    'voucher_code' => 'This voucher has already been used.',
                ]);
            }

            if (! $voucher->package?->is_active || ! $voucher->package->supportsHotspot()) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'This voucher package is no longer available.',
                ]);
            }

            Customer::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $macAddress,
                ],
                []
            );

            $subscription = Subscription::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $macAddress,
                ],
                [
                    'package_id' => $voucher->package_id,
                    'starts_at' => now(),
                    'expires_at' => now()->addSeconds((int) $voucher->package->limit_uptime_seconds),
                    'is_throttled' => false,
                ]
            );

            $voucher->update([
                'status' => 'used',
                'used_mac_address' => $macAddress,
                'used_at' => now(),
                'subscription_id' => $subscription->id,
            ]);

            $this->radius->grantSubscriptionAccess($subscription, self::ACCESS_PASSWORD);

            return $subscription;
        });
    }

    public function normalizeCode(string $code): string
    {
        return Str::upper(preg_replace('/\s+/', '', trim($code)) ?: '');
    }

    private function generateCodes(VoucherBatch $batch): Collection
    {
        $codes = collect();
        $prefix = $batch->prefix ? $batch->prefix.'-' : '';

        while ($codes->count() < $batch->quantity) {
            $code = $prefix.Str::upper(Str::random($batch->code_length));

            if ($codes->contains($code) || Voucher::where('code', $code)->exists()) {
                continue;
            }

            $codes->push($code);
        }

        return $codes;
    }
}
