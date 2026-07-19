<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;

class PlatformSecuritySettingsService
{
    public const REQUIRE_SUPER_ADMIN_TWO_FACTOR = 'security.require_super_admin_two_factor';

    public function requireSuperAdminTwoFactor(): bool
    {
        return (bool) $this->get(self::REQUIRE_SUPER_ADMIN_TWO_FACTOR, false);
    }

    public function update(array $data, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $this->set(self::REQUIRE_SUPER_ADMIN_TWO_FACTOR, (bool) ($data['require_super_admin_two_factor'] ?? false));
    }

    public function snapshot(): array
    {
        return [
            'require_super_admin_two_factor' => $this->requireSuperAdminTwoFactor(),
        ];
    }

    private function get(string $key, mixed $default = null): mixed
    {
        $setting = PlatformSetting::query()->where('key', $key)->first();

        return $setting?->value['value'] ?? $default;
    }

    private function set(string $key, mixed $value): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]]
        );
    }
}
