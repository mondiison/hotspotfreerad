<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Router;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RadiusProvisioningService
{
    public function syncRouter(Router $router): void
    {
        DB::table('nas')->updateOrInsert(
            ['nasname' => $router->wireguard_internal_ip],
            [
                'shortname' => $router->nas_identifier,
                'type' => 'mikrotik',
                'ports' => null,
                'secret' => $router->shared_secret,
                'server' => null,
                'community' => null,
                'description' => $router->name,
            ]
        );
    }

    public function syncPackageProfile(Package $package): string
    {
        $groupName = $package->radius_group_name ?: $this->makeGroupName($package);

        if ($package->radius_group_name !== $groupName) {
            $package->forceFill(['radius_group_name' => $groupName])->save();
        }

        $this->upsertGroupReply($groupName, 'Mikrotik-Rate-Limit', $package->speed_limit_profile);
        $this->upsertGroupReply($groupName, 'Session-Timeout', (string) $package->limit_uptime_seconds);

        return $groupName;
    }

    public function grantSubscriptionAccess(Subscription $subscription, string $password = 'authenticated_device_pass'): void
    {
        $subscription->loadMissing('package');

        $groupName = $this->syncPackageProfile($subscription->package);

        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $subscription->mac_address,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => $password,
            ]
        );

        DB::table('radusergroup')->updateOrInsert(
            ['username' => $subscription->mac_address],
            [
                'groupname' => $groupName,
                'priority' => 1,
            ]
        );
    }

    public function revokeMacAccess(string $macAddress): void
    {
        DB::table('radcheck')->where('username', $macAddress)->delete();
        DB::table('radreply')->where('username', $macAddress)->delete();
        DB::table('radusergroup')->where('username', $macAddress)->delete();
    }

    private function upsertGroupReply(string $groupName, string $attribute, string $value): void
    {
        DB::table('radgroupreply')->updateOrInsert(
            [
                'groupname' => $groupName,
                'attribute' => $attribute,
            ],
            [
                'op' => ':=',
                'value' => $value,
            ]
        );
    }

    private function makeGroupName(Package $package): string
    {
        $package->loadMissing('shop');

        return Str::of("tenant_{$package->shop->tenant_id}_shop_{$package->shop_id}_{$package->name}")
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(64, '')
            ->toString();
    }
}
