<?php

namespace App\Support;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-02: backs the "check your balance" view on the customer
 * self-service connect link (and the normal access-granted screen) --
 * time remaining is a plain subtraction, but data remaining needs this
 * subscription's own actual usage from FreeRADIUS accounting.
 */
class SubscriptionBalance
{
    /**
     * @return array{seconds_remaining: int, data_limit_bytes: ?int, data_used_bytes: int, data_remaining_bytes: ?int}
     */
    public static function compute(Subscription $subscription): array
    {
        $package = $subscription->package;
        $dataLimitBytes = $package?->data_limit_bytes;

        return [
            'seconds_remaining' => max(0, now()->diffInSeconds($subscription->expires_at, false)),
            'data_limit_bytes' => $dataLimitBytes,
            'data_used_bytes' => $dataUsedBytes = self::dataUsedBytes($subscription),
            'data_remaining_bytes' => $dataLimitBytes ? max(0, $dataLimitBytes - $dataUsedBytes) : null,
        ];
    }

    /**
     * Total bytes (both directions, the same `acctinputoctets +
     * acctoutputoctets` convention RadiusAccountingStats already uses)
     * this subscription's own mac has used since it started -- scoped to
     * `acctstarttime >= starts_at` so a mac's *previous*, already-expired
     * subscription's usage never bleeds into the current one's balance.
     */
    private static function dataUsedBytes(Subscription $subscription): int
    {
        if (! Schema::hasTable('radacct')) {
            return 0;
        }

        return (int) DB::table('radacct')
            ->where('username', $subscription->mac_address)
            ->where('acctstarttime', '>=', $subscription->starts_at)
            ->sum(DB::raw('COALESCE(acctinputoctets, 0) + COALESCE(acctoutputoctets, 0)'));
    }
}
