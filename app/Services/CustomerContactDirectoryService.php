<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PosDevice;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Support\Collection;

/**
 * 2026-11-10, direct request: "i want a place where i can see the unique
 * phone numbers i want to use it and open whatsapp group for complaints
 * and educational purpose" -- a tenant-wide, deduplicated directory of
 * every customer-facing phone number this app has ever collected (hotspot
 * checkout's collect_customer_phone field via Customer, PPPoE subscribers,
 * POS device owners), so an admin can compile a broadcast/WhatsApp-group
 * contact list without hand-copying numbers out of three separate pages.
 *
 * Deliberately merged and deduplicated in PHP rather than a single SQL
 * UNION query: the three source tables have different schemas (no shared
 * "contact_name" column, for one), and a single person can legitimately
 * appear more than once even within ONE source table (the same phone
 * reused across several hotspot devices, or several PPPoE accounts) --
 * collapsing that correctly needs a DISTINCT-per-phone aggregation that
 * SQLite (used by this app's own test suite) can't express the same way
 * MySQL's GROUP_CONCAT(DISTINCT ...) can. A plain Collection groupBy() is
 * portable across both and simple to get right, and this is a reporting
 * list a tenant looks at occasionally, not a hot path needing SQL-side
 * pagination.
 */
class CustomerContactDirectoryService
{
    /**
     * @return Collection<int, array{phone: string, contact_name: ?string, sources: list<string>, shops: list<string>}>
     */
    public function uniqueContacts(User $user, ?int $shopId = null): Collection
    {
        $customers = TenantAccess::scopeCustomers(Customer::query(), $user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->when($shopId, fn ($query) => $query->where('shop_id', $shopId))
            ->with('shop')
            ->get()
            ->map(fn (Customer $customer): array => [
                'phone' => $customer->phone,
                'contact_name' => null,
                'source' => 'Hotspot',
                'shop' => $customer->shop?->name,
            ]);

        $pppoe = TenantAccess::scopePppoeSubscribers(PppoeSubscriber::query(), $user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->when($shopId, fn ($query) => $query->where('shop_id', $shopId))
            ->with('shop')
            ->get()
            ->map(fn (PppoeSubscriber $subscriber): array => [
                'phone' => $subscriber->phone,
                'contact_name' => $subscriber->full_name,
                'source' => 'PPPoE',
                'shop' => $subscriber->shop?->name,
            ]);

        $pos = TenantAccess::scopePosDevices(PosDevice::query(), $user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->when($shopId, fn ($query) => $query->where('shop_id', $shopId))
            ->with('shop')
            ->get()
            ->map(fn (PosDevice $device): array => [
                'phone' => $device->phone,
                'contact_name' => $device->owner_name,
                'source' => 'POS',
                'shop' => $device->shop?->name,
            ]);

        return $customers->concat($pppoe)->concat($pos)
            ->groupBy('phone')
            ->map(fn (Collection $rows, string $phone): array => [
                'phone' => $phone,
                'contact_name' => $rows->pluck('contact_name')->filter()->first(),
                'sources' => $rows->pluck('source')->unique()->sort()->values()->all(),
                'shops' => $rows->pluck('shop')->filter()->unique()->sort()->values()->all(),
            ])
            ->sortBy('phone')
            ->values();
    }

    /**
     * @return Collection<int, Shop>
     */
    public function shopsFor(User $user): Collection
    {
        return TenantAccess::scopeShops(Shop::query(), $user)->orderBy('name')->get();
    }
}
