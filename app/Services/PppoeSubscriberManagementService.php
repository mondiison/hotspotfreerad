<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PppoeSubscriberManagementService
{
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
            'phone' => ['nullable', 'string', 'max:255'],
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
        $subscriber->loadMissing('package');

        $startsAt = now();
        $baseExpiry = $subscriber->expires_at?->isFuture() ? $subscriber->expires_at : $startsAt;

        $subscriber->forceFill([
            'starts_at' => $subscriber->starts_at ?: $startsAt,
            'expires_at' => $baseExpiry->copy()->addSeconds((int) $subscriber->package->limit_uptime_seconds),
            'is_active' => true,
        ])->save();

        $this->radius->provisionPppoeSubscriber($subscriber);

        return $subscriber;
    }

    public function sync(PppoeSubscriber $subscriber, User $user): PppoeSubscriber
    {
        TenantAccess::assertPppoeSubscriber($subscriber, $user);

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

        if (blank($data['password'] ?? null) && $subscriber) {
            unset($data['password']);
        }

        return $data;
    }
}
