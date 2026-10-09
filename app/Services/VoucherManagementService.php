<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\PaymentCommission;
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
        $shop = TenantAccess::scopeShops(Shop::query(), $user)->findOrFail($data['shop_id']);
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

    /**
     * 2026-10-09, direct request after a live report: a customer's payment
     * shows successful and the Subscription looks "Provisioned" with a
     * future expires_at, but the device is still stuck on the login
     * screen -- almost always because the MAC that actually paid is no
     * longer the MAC the device is presenting (the same iOS/Android
     * randomization story as changeSubscriptionMacAddress() above), except
     * here nobody knows the device's new MAC to retarget it directly, since
     * this is a remote-support call, not an in-person fix. Rather than
     * create a second payment/sale record (which would double-count the
     * sale in every report), this generates a single-use voucher LINKED
     * BACK to the exact same Payment row (payments.voucher_id is unique, so
     * a payment can only ever get one of these -- enforced below, not just
     * by the DB constraint, so the failure is a clear validation message
     * instead of a raw SQL error) that the customer can redeem themselves
     * from the portal's own "Have a voucher?" box under whatever MAC their
     * device actually presents, via the exact same redeem() path below --
     * no new redemption mechanism. The stuck subscription's own MAC is
     * expired and its RADIUS rows revoked via the existing
     * macStillClaimedElsewhere()-guarded revokeMacAccess(), so that MAC is
     * never left half-provisioned and limbo, but a MAC still legitimately
     * shared with another active subscription/PosDevice/TrustedWifiDevice
     * is left alone rather than wiped as a side effect. Scoped to a
     * subscription that hasn't already expired on its own -- an already
     * naturally-expired subscription has nothing left to "recover", and
     * generating a fresh full-duration voucher for one would hand out a
     * second paid-for period for free.
     */
    public function generateRecoveryVoucher(Payment $payment, User $user): Voucher
    {
        $payment->loadMissing(['shop', 'subscription']);

        if ($payment->status !== 'successful') {
            throw ValidationException::withMessages([
                'recovery_voucher' => 'Only a successful payment can get a recovery voucher.',
            ]);
        }

        if ($payment->voucher_id) {
            throw ValidationException::withMessages([
                'recovery_voucher' => 'This payment is already linked to a voucher.',
            ]);
        }

        $subscription = $payment->subscription;

        if (! $subscription) {
            throw ValidationException::withMessages([
                'recovery_voucher' => 'This payment has no hotspot subscription to recover.',
            ]);
        }

        if ($subscription->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'recovery_voucher' => 'This subscription has already expired naturally -- there is nothing to recover.',
            ]);
        }

        return DB::transaction(function () use ($payment, $subscription, $user): Voucher {
            $batch = VoucherBatch::create([
                'shop_id' => $payment->shop_id,
                'package_id' => $payment->package_id,
                'name' => 'MAC recovery -- '.$payment->tx_ref,
                'quantity' => 1,
                'code_length' => 10,
                'prefix' => 'RCV',
                'status' => 'active',
                'notes' => "Generated by {$user->name} to salvage payment {$payment->tx_ref} after the customer's device could no longer be reached under the MAC that originally paid. Linked back to the original payment -- not a new sale.",
            ]);

            $voucher = Voucher::create([
                'voucher_batch_id' => $batch->id,
                'shop_id' => $payment->shop_id,
                'package_id' => $payment->package_id,
                'code' => $this->generateCodes($batch)->first(),
                'status' => 'unused',
            ]);

            $payment->update(['voucher_id' => $voucher->id]);

            $subscription->forceFill(['expires_at' => now()->subMinute()])->save();
            $this->radius->revokeMacAccess($subscription->mac_address);

            return $voucher;
        });
    }

    public function redeem(Router $router, string $macAddress, string $code): Subscription
    {
        return DB::transaction(function () use ($router, $macAddress, $code): Subscription {
            $voucher = Voucher::query()
                ->with(['batch', 'package', 'payment', 'shop.tenant'])
                ->where('code', $this->normalizeCode($code))
                ->lockForUpdate()
                ->first();

            if (! $voucher || (int) $voucher->shop_id !== (int) $router->shop_id) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'Voucher code was not found for this hotspot.',
                ]);
            }

            if ($voucher->status === 'void') {
                throw ValidationException::withMessages([
                    'voucher_code' => 'This voucher has been voided. Please contact the hotspot operator.',
                ]);
            }

            if (! in_array($voucher->status, ['unused', 'sold'], true)) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'This voucher has already been used.',
                ]);
            }

            if (! $voucher->package?->is_active || ! $voucher->package->supportsHotspot()) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'This voucher package is no longer available.',
                ]);
            }

            $customer = Customer::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $macAddress,
                ],
                []
            );

            $paymentId = $voucher->payment?->id;

            $subscription = Subscription::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $macAddress,
                ],
                [
                    'package_id' => $voucher->package_id,
                    'payment_id' => $paymentId,
                    'starts_at' => now(),
                    'expires_at' => now()->addSeconds((int) $voucher->package->limit_uptime_seconds),
                    'is_throttled' => false,
                ]
            );

            if ($voucher->payment) {
                $voucher->payment->update([
                    'customer_id' => $customer->id,
                    'payload' => array_merge($voucher->payment->payload ?? [], [
                        'mac' => $macAddress,
                        'voucher_code' => $voucher->code,
                        'subscription_id' => $subscription->id,
                    ]),
                ]);
            }

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

    public function markSold(Voucher $voucher, array $data, User $user): Voucher
    {
        TenantAccess::assertVoucher($voucher, $user);

        if ($voucher->status !== 'unused') {
            throw ValidationException::withMessages([
                'sale_voucher_id' => 'Only unused vouchers can be marked as sold.',
            ]);
        }

        return DB::transaction(function () use ($voucher, $data, $user): Voucher {
            $voucher->loadMissing(['package', 'shop.tenant']);
            $soldAt = now();
            $saleAmount = round((float) ($data['sale_amount'] ?? $voucher->package?->price ?? 0), 2);
            $saleReference = filled($data['sale_reference'] ?? null) ? (string) $data['sale_reference'] : null;

            $voucher->forceFill([
                'status' => 'sold',
                'sold_by_user_id' => $user->id,
                'sold_at' => $soldAt,
                'sale_amount' => $saleAmount,
                'sale_reference' => $saleReference,
                'sale_notes' => filled($data['sale_notes'] ?? null) ? (string) $data['sale_notes'] : null,
            ])->save();

            $this->recordVoucherPayment($voucher, $voucher->shop, $saleAmount, $saleReference, $user);

            return $voucher;
        });
    }

    public function reverseSale(Voucher $voucher, User $user): Voucher
    {
        TenantAccess::assertVoucher($voucher, $user);

        if ($voucher->status !== 'sold') {
            throw ValidationException::withMessages([
                'reverse_sale_voucher_id' => 'Only sold and unredeemed vouchers can have their sale reversed.',
            ]);
        }

        DB::transaction(function () use ($voucher): void {
            $voucher->loadMissing('payment');

            $voucher->forceFill([
                'status' => 'unused',
                'sold_by_user_id' => null,
                'sold_at' => null,
                'sale_amount' => null,
                'sale_reference' => null,
                'sale_notes' => null,
            ])->save();

            if ($voucher->payment) {
                $voucher->payment->update([
                    'status' => 'failed',
                    'payload' => array_merge($voucher->payment->payload ?? [], [
                        'voucher_sale_reversed_at' => now()->toDateTimeString(),
                    ]),
                ]);
            }

            $voucher->batch?->forceFill(['status' => 'active'])->save();
        });

        return $voucher;
    }

    public function voidUnusedBatchVouchers(VoucherBatch $batch, User $user): int
    {
        TenantAccess::assertVoucherBatch($batch, $user);

        return DB::transaction(function () use ($batch): int {
            $voided = $batch->vouchers()
                ->where('status', 'unused')
                ->update(['status' => 'void', 'updated_at' => now()]);

            $hasRedeemableVouchers = $batch->vouchers()
                ->whereIn('status', ['unused', 'sold'])
                ->exists();

            $batch->forceFill(['status' => $hasRedeemableVouchers ? 'active' : 'void'])->save();

            return $voided;
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

    public function recordVoucherPayment(Voucher $voucher, Shop $shop, float $amount, ?string $saleReference, ?User $user = null): Payment
    {
        $paymentData = array_merge([
            'shop_id' => $voucher->shop_id,
            'package_id' => $voucher->package_id,
            'provider' => 'voucher_cash',
            'tx_ref' => 'VCH-'.str_pad((string) $voucher->id, 8, '0', STR_PAD_LEFT),
            'provider_reference' => $saleReference,
            'amount' => $amount,
            'currency' => $voucher->package?->currency ?? 'NGN',
            'status' => 'successful',
            'paid_at' => $voucher->sold_at,
            'payload' => [
                'voucher_id' => $voucher->id,
                'voucher_code' => $voucher->code,
                'voucher_batch_id' => $voucher->voucher_batch_id,
                'sold_by_user_id' => $user?->id ?? $voucher->sold_by_user_id,
                'sale_reference' => $saleReference,
                'sale_notes' => $voucher->sale_notes,
                'payment_channel' => 'voucher_cash',
            ],
        ], PaymentCommission::forShop($shop, $amount));

        return Payment::updateOrCreate(
            ['voucher_id' => $voucher->id],
            $paymentData
        );
    }
}
