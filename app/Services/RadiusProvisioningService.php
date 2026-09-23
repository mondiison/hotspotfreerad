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
    /**
     * Confirmed live 2026-09-23: provisionPosDevice() used to store each
     * device's own MAC as its Cleartext-Password too (matching the
     * username), relying on RouterOS's `mac-auth-password=""` default to
     * send that same MAC back as the CHAP password. It doesn't -- RouterOS
     * hashes whatever it actually sends with the CHAP-Challenge, and
     * FreeRADIUS's re-computed hash from the stored Cleartext-Password never
     * matched, rejecting every MAC-auth attempt with "password is
     * incorrect" even though the username/group lookup succeeded correctly.
     * grantSubscriptionAccess() below already uses this exact same fixed,
     * non-MAC password for the customer hotspot's own MAC-cookie RADIUS
     * flow (confirmed working) -- POS now matches it, with
     * MikroTikProvisioningService/RouterOsConnectionService setting this
     * same value as mms-pos-profile's explicit mac-auth-password instead of
     * leaving it blank, so RouterOS is never guessing what to send. Not a
     * meaningful secret -- the MAC-as-username is what actually identifies
     * the device; RADIUS just requires some password value for CHAP/PAP.
     */
    public const POS_MAC_AUTH_PASSWORD = 'MmsPosMacAuth2026!';

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

    /**
     * Confirmed live 2026-09-23: radcheck/radreply/radusergroup are keyed
     * purely by username (a MAC address string), with no feature/type
     * discriminator -- a single MAC can simultaneously back a hotspot
     * Subscription, a PosDevice, and/or a TrustedWifiDevice. The scheduled
     * hotspot:sync-expired-hotspot command revoked every expired
     * subscription's MAC unconditionally, which silently deleted a
     * still-valid PosDevice's radcheck rows too whenever the same phone had
     * both an expired hotspot subscription and an active POS registration --
     * reported live as a POS device's radcheck row "disappearing" every few
     * minutes despite the device itself showing a future expiry, requiring a
     * manual Renew/Sync click to restore it (which just re-ran
     * provisionPosDevice()). Fixed by refusing to actually delete a MAC's
     * RADIUS rows while any other feature still currently claims that same
     * MAC -- $exceptPosDeviceId/$exceptTrustedWifiDeviceId let
     * revokePosDevice()/revokeTrustedWifiDevice() exclude the very device
     * they're revoking access for (otherwise a device that's still active
     * right up until its own delete() call would block its own revoke).
     */
    public function revokeMacAccess(string $macAddress, ?int $exceptPosDeviceId = null, ?int $exceptTrustedWifiDeviceId = null): void
    {
        if ($this->macStillClaimedElsewhere($macAddress, $exceptPosDeviceId, $exceptTrustedWifiDeviceId)) {
            return;
        }

        DB::table('radcheck')->where('username', $macAddress)->delete();
        DB::table('radreply')->where('username', $macAddress)->delete();
        DB::table('radusergroup')->where('username', $macAddress)->delete();
    }

    private function macStillClaimedElsewhere(string $macAddress, ?int $exceptPosDeviceId, ?int $exceptTrustedWifiDeviceId): bool
    {
        $hasActiveSubscription = Subscription::query()
            ->where('mac_address', $macAddress)
            ->where('expires_at', '>', now())
            ->exists();

        if ($hasActiveSubscription) {
            return true;
        }

        $hasActivePosDevice = PosDevice::query()
            ->where('mac_address', $macAddress)
            ->when($exceptPosDeviceId, fn ($query) => $query->whereKeyNot($exceptPosDeviceId))
            ->get()
            ->contains(fn (PosDevice $device) => $device->isCurrentlyActive());

        if ($hasActivePosDevice) {
            return true;
        }

        return TrustedWifiDevice::query()
            ->where('mac_address', $macAddress)
            ->when($exceptTrustedWifiDeviceId, fn ($query) => $query->whereKeyNot($exceptTrustedWifiDeviceId))
            ->get()
            ->contains(fn (TrustedWifiDevice $device) => $device->isCurrentlyActive());
    }

    /**
     * Confirmed live 2026-09-23: this used to also write a second radcheck
     * row with attribute=Calling-Station-Id, on top of Cleartext-Password.
     * FreeRADIUS treats every radcheck row for a username as a check-item
     * the Access-Request must satisfy -- so that row wasn't just metadata,
     * it required the request's own Calling-Station-Id attribute (as
     * RouterOS actually sends it) to exactly match $macAddress too, a
     * format never verified against real hardware (unlike username, which
     * RouterOS's own radius-mac-format setting explicitly governs).
     * Confirmed live via RouterOS's hotspot log ("trying to log in by mac" /
     * "login failed: invalid username or password") and a direct radcheck
     * query showing the row was present with the expected value -- the
     * reject was real, not a missing-data issue, and removing this second
     * check-item is what fixed it. provisionTrustedWifiDevice() below
     * already uses the simpler, proven-working shape (Cleartext-Password
     * only) for the exact same MAC-as-username-and-password RADIUS MAC-auth
     * pattern, just for a different network -- this now matches it.
     */
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
                'value' => self::POS_MAC_AUTH_PASSWORD,
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
        $this->revokeMacAccess($this->normalizeMacAddress($device->mac_address), exceptPosDeviceId: $device->id);
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
        $this->revokeMacAccess($this->normalizeMacAddress($device->mac_address), exceptTrustedWifiDeviceId: $device->id);
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
