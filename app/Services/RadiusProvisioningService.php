<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PosDevice;
use App\Models\PppoeSubscriber;
use App\Models\Router;
use App\Models\Subscription;
use App\Models\TrustedWifiDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                'description' => $router->name.' (services: hotspot, ppp)',
            ]
        );

        // FreeRADIUS matches an incoming request to a NAS/client definition by the
        // packet's source IP -- a router with a ZeroTier fallback might send RADIUS
        // traffic from either its WireGuard IP or its ZeroTier IP, so it needs a
        // second nas row too, both keyed to the same secret. Harmless to leave the
        // row above in place even for a zerotier-only router (tunnel_mode='zerotier');
        // FreeRADIUS just never sees traffic from an IP that never connects.
        if (in_array($router->tunnel_mode, ['wireguard_zerotier', 'zerotier'], true) && filled($router->zerotier_ip)) {
            DB::table('nas')->updateOrInsert(
                ['nasname' => $router->zerotier_ip],
                [
                    'shortname' => $router->nas_identifier.'-zt',
                    'type' => 'mikrotik',
                    'ports' => null,
                    'secret' => $router->shared_secret,
                    'server' => null,
                    'community' => null,
                    'description' => $router->name.' (ZeroTier fallback)',
                ]
            );
        }

        if ((bool) config('services.radius.manage_clients', false)) {
            $result = app(FreeRadiusClientSyncService::class)->sync(reload: true);

            if (! empty($result['errors'])) {
                Log::warning('FreeRADIUS client file sync failed after router sync', [
                    'router_id' => $router->id,
                    'errors' => $result['errors'],
                ]);
            }
        }
    }

    public function deleteRouter(Router $router): void
    {
        DB::table('nas')
            ->where('nasname', $router->wireguard_internal_ip)
            ->orWhere('shortname', $router->nas_identifier)
            ->orWhere('shortname', $router->nas_identifier.'-zt')
            ->when(filled($router->zerotier_ip), fn ($query) => $query->orWhere('nasname', $router->zerotier_ip))
            ->delete();

        if ((bool) config('services.radius.manage_clients', false)) {
            $result = app(FreeRadiusClientSyncService::class)->sync(reload: true);

            if (! empty($result['errors'])) {
                Log::warning('FreeRADIUS client file sync failed after router deletion', [
                    'router_id' => $router->id,
                    'errors' => $result['errors'],
                ]);
            }
        }
    }

    public function syncPackageProfile(Package $package): string
    {
        $groupName = $package->radius_group_name ?: $this->makeGroupName($package);

        if ($package->radius_group_name !== $groupName) {
            $package->forceFill(['radius_group_name' => $groupName])->save();
        }

        $this->upsertGroupReply($groupName, 'Mikrotik-Rate-Limit', $package->speed_limit_profile);
        $this->upsertGroupReply($groupName, 'Session-Timeout', (string) $package->limit_uptime_seconds);

        if ($package->data_limit_bytes) {
            [$totalLimit, $totalLimitGigawords] = $this->splitBytesForMikroTikLimit((int) $package->data_limit_bytes);

            $this->upsertGroupReply($groupName, 'Mikrotik-Total-Limit', (string) $totalLimit);
            $this->upsertGroupReply($groupName, 'Mikrotik-Total-Limit-Gigawords', (string) $totalLimitGigawords);
        } else {
            DB::table('radgroupreply')
                ->where('groupname', $groupName)
                ->whereIn('attribute', ['Mikrotik-Total-Limit', 'Mikrotik-Total-Limit-Gigawords'])
                ->delete();
        }

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

    public function provisionPosDevice(PosDevice $device): void
    {
        $device->loadMissing('package');

        $groupName = $this->syncPackageProfile($device->package);
        $macAddress = $this->normalizeMacAddress($device->mac_address);

        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $macAddress,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => $macAddress,
            ]
        );

        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $macAddress,
                'attribute' => 'Calling-Station-Id',
            ],
            [
                'op' => ':=',
                'value' => $macAddress,
            ]
        );

        DB::table('radusergroup')->updateOrInsert(
            ['username' => $macAddress],
            [
                'groupname' => $groupName,
                'priority' => 1,
            ]
        );

        $device->forceFill([
            'mac_address' => $macAddress,
            'last_provisioned_at' => now(),
        ])->save();
    }

    public function revokePosDevice(PosDevice $device): void
    {
        $this->revokeMacAccess($this->normalizeMacAddress($device->mac_address));
    }

    public function provisionTrustedWifiDevice(TrustedWifiDevice $device): void
    {
        $macAddress = $this->normalizeMacAddress($device->mac_address);

        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $macAddress,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => $macAddress,
            ]
        );

        $device->forceFill([
            'mac_address' => $macAddress,
            'last_provisioned_at' => now(),
        ])->save();
    }

    public function revokeTrustedWifiDevice(TrustedWifiDevice $device): void
    {
        $this->revokeMacAccess($this->normalizeMacAddress($device->mac_address));
    }

    public function provisionPppoeSubscriber(PppoeSubscriber $subscriber): void
    {
        $subscriber->loadMissing('package');

        $groupName = $this->syncPackageProfile($subscriber->package);

        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $subscriber->username,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => $subscriber->password,
            ]
        );

        DB::table('radusergroup')->updateOrInsert(
            ['username' => $subscriber->username],
            [
                'groupname' => $groupName,
                'priority' => 1,
            ]
        );

        $subscriber->forceFill(['last_provisioned_at' => now()])->save();
    }

    public function revokePppoeSubscriber(PppoeSubscriber $subscriber): void
    {
        DB::table('radcheck')->where('username', $subscriber->username)->delete();
        DB::table('radreply')->where('username', $subscriber->username)->delete();
        DB::table('radusergroup')->where('username', $subscriber->username)->delete();
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

    private function splitBytesForMikroTikLimit(int $bytes): array
    {
        $gigaword = 4294967296;

        return [
            $bytes % $gigaword,
            intdiv($bytes, $gigaword),
        ];
    }

    private function normalizeMacAddress(string $macAddress): string
    {
        $hex = Str::of($macAddress)
            ->upper()
            ->replaceMatches('/[^A-F0-9]/', '')
            ->toString();

        return collect(str_split($hex, 2))->take(6)->implode(':');
    }
}
