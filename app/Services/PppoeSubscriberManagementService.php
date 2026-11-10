<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Payment;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\User;
use App\Rules\NigerianPhoneNumber;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PppoeSubscriberManagementService
{
    /**
     * 2026-11-10, direct request ("we also need to find a way to track
     * pppoe transaction"): unlike hotspot/POS/vouchers, a PPPoE
     * subscriber's creation and every renewal never wrote a Payment row at
     * all -- it just extended expires_at and re-synced RADIUS, so none of
     * it ever showed up in Sales/Payment reports. Mirrors
     * PosDeviceManagementService::POS_PAYMENT_PROVIDER's exact shape: this
     * is deliberately manual accounting (the tenant collects payment from
     * the subscriber outside the app -- cash, bank transfer, etc., same as
     * POS), not a real gateway charge, so it charges zero platform
     * commission regardless of the tenant's billing_model, the same
     * reasoning POS's own recordPayment() already documents.
     */
    public const PPPOE_PAYMENT_PROVIDER = 'pppoe_manual';

    public function __construct(private readonly RadiusProvisioningService $radius) {}

    public function rules(User $user, ?PppoeSubscriber $subscriber = null): array
    {
        return [
            'shop_id' => ['required', TenantAccess::shopExistsRule($user)],
            'package_id' => [
                'required',
                Rule::exists('packages', 'id')
                    ->whereIn('service_type', ['pppoe', 'both'])
                    ->where('is_active', true),
            ],
            'username' => ['required', 'string', 'max:64', Rule::unique('pppoe_subscribers')->ignore($subscriber)],
            'password' => [$subscriber ? 'nullable' : 'required', 'string', 'min:6', 'max:64'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255', new NigerianPhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function create(array $data, User $user): PppoeSubscriber
    {
        $data = $this->normalize($data, $user);
        $subscriber = PppoeSubscriber::create($data);
        $subscriber->loadMissing('shop', 'package');
        $this->recordPayment($subscriber, $user);

        if ($subscriber->isCurrentlyActive()) {
            $this->radius->provisionPppoeSubscriber($subscriber);
        }

        return $subscriber;
    }

    public function update(PppoeSubscriber $subscriber, array $data, User $user): PppoeSubscriber
    {
        TenantAccess::assertPppoeSubscriber($subscriber, $user);

        $data = $this->normalize($data, $user, $subscriber);
        $subscriber->update($data);

        if ($subscriber->isCurrentlyActive()) {
            $this->radius->provisionPppoeSubscriber($subscriber);
        } else {
            $this->radius->revokePppoeSubscriber($subscriber);
        }

        return $subscriber;
    }

    public function renew(PppoeSubscriber $subscriber, User $user): PppoeSubscriber
    {
        TenantAccess::assertPppoeSubscriber($subscriber, $user);
        $subscriber->loadMissing('shop', 'package');

        $this->extendExpiry($subscriber);
        $this->recordPayment($subscriber, $user);
        $this->radius->provisionPppoeSubscriber($subscriber);

        return $subscriber;
    }

    /**
     * 2026-11-10: the "pay to renew" email link's post-payment action --
     * called from HotspotPaymentConfirmationService::markPaidAndGrantAccess()
     * once a real gateway charge for this subscriber's renewal has already
     * been verified. Deliberately has no TenantAccess check (there's no
     * authenticated admin in this flow, just a customer who followed a
     * link and paid) and deliberately does NOT call recordPayment() --
     * the Payment that triggered this call already IS the transaction
     * record, recording a second one here would double-count the sale.
     */
    public function renewFromOnlinePayment(PppoeSubscriber $subscriber): PppoeSubscriber
    {
        $subscriber->loadMissing('package');

        $this->extendExpiry($subscriber);
        $this->radius->provisionPppoeSubscriber($subscriber);

        return $subscriber;
    }

    /**
     * Shared by renew() (admin-triggered, records its own manual payment)
     * and renewFromOnlinePayment() (customer-triggered via a real gateway
     * charge) -- both just need "push expires_at out by one more package
     * cycle from wherever it currently stands, and reactivate." Also
     * resets the three expiry-reminder dedup flags (2026-11-10) so the
     * next billing cycle's reminders can fire again -- without this, a
     * subscriber who renews after their "expired" email would never get
     * another 3-day/1-day/expired reminder for their next cycle.
     */
    private function extendExpiry(PppoeSubscriber $subscriber): void
    {
        $startsAt = now();
        $baseExpiry = $subscriber->expires_at?->isFuture() ? $subscriber->expires_at : $startsAt;

        $subscriber->forceFill([
            'starts_at' => $subscriber->starts_at ?: $startsAt,
            'expires_at' => $baseExpiry->copy()->addSeconds((int) $subscriber->package->limit_uptime_seconds),
            'is_active' => true,
            'reminder_3day_sent_at' => null,
            'reminder_1day_sent_at' => null,
            'reminder_expired_sent_at' => null,
        ])->save();
    }

    /**
     * One row per creation/renewal cycle (never a single mutable "current
     * payment" field), matching PosDeviceManagementService::recordPayment()'s
     * exact shape and reasoning -- zero platform commission, since this app
     * never actually touches the money (the tenant collects it directly from
     * the subscriber, outside the app).
     */
    private function recordPayment(PppoeSubscriber $subscriber, User $user): Payment
    {
        $price = (float) $subscriber->package->price;

        return Payment::create([
            'shop_id' => $subscriber->shop_id,
            'package_id' => $subscriber->package_id,
            'pppoe_subscriber_id' => $subscriber->id,
            'provider' => self::PPPOE_PAYMENT_PROVIDER,
            'tx_ref' => 'PPPOE-'.$subscriber->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'amount' => $price,
            'currency' => $subscriber->package->currency,
            'status' => 'successful',
            'paid_at' => now(),
            'gross_amount' => round($price, 2),
            'platform_fee_amount' => 0,
            'tenant_net_amount' => round($price, 2),
            'commission_rate' => 0,
            'billing_model' => 'manual',
            'payload' => ['recorded_by_user_id' => $user->id, 'username' => $subscriber->username],
        ]);
    }

    public function sync(PppoeSubscriber $subscriber, User $user): PppoeSubscriber
    {
        TenantAccess::assertPppoeSubscriber($subscriber, $user);

        return $this->syncSystem($subscriber);
    }

    public function syncSystem(PppoeSubscriber $subscriber): PppoeSubscriber
    {
        if ($subscriber->isCurrentlyActive()) {
            $this->radius->provisionPppoeSubscriber($subscriber);
        } else {
            $this->radius->revokePppoeSubscriber($subscriber);
        }

        return $subscriber->refresh();
    }

    public function delete(PppoeSubscriber $subscriber, User $user): void
    {
        TenantAccess::assertPppoeSubscriber($subscriber, $user);
        $this->radius->revokePppoeSubscriber($subscriber);
        $subscriber->delete();
    }

    public function generateUsername(string $shopName): string
    {
        return Str::of($shopName)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->limit(8, '')
            ->append('-', Str::lower(Str::random(6)))
            ->toString();
    }

    public function generatePassword(): string
    {
        return Str::password(10, letters: true, numbers: true, symbols: false, spaces: false);
    }

    private function normalize(array $data, User $user, ?PppoeSubscriber $subscriber = null): array
    {
        $shop = TenantAccess::scopeShops(Shop::query(), $user)->whereKey($data['shop_id'])->firstOrFail();
        $package = TenantAccess::scopePackages(Package::query(), $user)
            ->whereKey($data['package_id'])
            ->where('shop_id', $shop->id)
            ->whereIn('service_type', ['pppoe', 'both'])
            ->where('is_active', true)
            ->firstOrFail();

        $startsAt = filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at']) : now();

        $data['starts_at'] = $startsAt;
        $data['expires_at'] = filled($data['expires_at'] ?? null)
            ? Carbon::parse($data['expires_at'])
            : $startsAt->copy()->addSeconds((int) $package->limit_uptime_seconds);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        foreach (['full_name', 'phone', 'email'] as $field) {
            if (blank($data[$field] ?? null)) {
                $data[$field] = null;
            }
        }

        if ($data['phone'] !== null) {
            $data['phone'] = NigerianPhoneNumber::normalize($data['phone']);
        }

        if (blank($data['password'] ?? null) && $subscriber) {
            unset($data['password']);
        }

        return $data;
    }
}
