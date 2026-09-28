<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The super-admin-configured withdrawal fee charged on every tenant wallet
 * withdrawal -- a platform-wide setting (not per-tenant), mirroring
 * PlatformPaymentSettingsService's own PlatformSetting-backed,
 * cached-read/cache-busted-write shape for exactly this kind of small
 * rarely-changed platform configuration.
 */
class WalletWithdrawalFeeSettingsService
{
    private const KEY = 'wallet.withdrawal_fee';

    /**
     * @return array{type: string, value: float}
     */
    public function settings(): array
    {
        $stored = Cache::remember($this->cacheKey(), now()->addMinutes(10), function (): array {
            $setting = PlatformSetting::query()->where('key', self::KEY)->first();

            return is_array($setting?->value) ? $setting->value : [];
        });

        return [
            'type' => $stored['type'] ?? 'fixed',
            'value' => (float) ($stored['value'] ?? 0),
        ];
    }

    public function update(array $data, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], [
            'value' => [
                'type' => $data['type'],
                'value' => (float) $data['value'],
            ],
        ]);

        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'platform-payment-settings:'.self::KEY;
    }
}
