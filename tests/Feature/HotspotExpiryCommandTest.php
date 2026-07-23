<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotspotExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_hotspot_sync_command_reports_dry_run_without_revoking_radius_rows(): void
    {
        [$shop, $package] = $this->fixture();
        $subscription = $this->subscription($shop, $package, 'AA:BB:CC:DD:EE:01', now()->subMinute());
        $this->grantRadiusRows($subscription, $package);

        $this->artisan('hotspot:sync-expired-hotspot --dry-run')
            ->expectsOutput('1 expired hotspot device(s) would be revoked from RADIUS.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('radcheck', ['username' => 'AA:BB:CC:DD:EE:01']);
        $this->assertDatabaseHas('radusergroup', ['username' => 'AA:BB:CC:DD:EE:01']);
    }

    public function test_expired_hotspot_sync_command_revokes_only_devices_without_active_access(): void
    {
        [$shop, $package] = $this->fixture();
        $expiredOnly = $this->subscription($shop, $package, 'AA:BB:CC:DD:EE:01', now()->subMinute());
        $renewedOld = $this->subscription($shop, $package, 'AA:BB:CC:DD:EE:02', now()->subMinute());
        $renewedActive = $this->subscription($shop, $package, 'AA:BB:CC:DD:EE:02', now()->addHour());
        $activeOnly = $this->subscription($shop, $package, 'AA:BB:CC:DD:EE:03', now()->addHour());
        $this->grantRadiusRows($expiredOnly, $package);
        $this->grantRadiusRows($renewedOld, $package);
        $this->grantRadiusRows($activeOnly, $package);

        $this->artisan('hotspot:sync-expired-hotspot')
            ->expectsOutput('Revoked 1 expired hotspot device(s) from RADIUS.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('radcheck', ['username' => 'AA:BB:CC:DD:EE:01']);
        $this->assertDatabaseMissing('radusergroup', ['username' => 'AA:BB:CC:DD:EE:01']);
        $this->assertDatabaseHas('radcheck', ['username' => $renewedActive->mac_address]);
        $this->assertDatabaseHas('radusergroup', ['username' => $renewedActive->mac_address]);
        $this->assertDatabaseHas('radcheck', ['username' => $activeOnly->mac_address]);
        $this->assertDatabaseHas('radusergroup', ['username' => $activeOnly->mac_address]);
    }

    private function fixture(): array
    {
        $tenant = Tenant::create([
            'company_name' => 'Hotspot Expiry Tenant',
            'owner_email' => 'expiry@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Park',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'service_type' => 'hotspot',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'radius_group_name' => 'daily_5gb',
            'is_active' => true,
        ]);

        return [$shop, $package];
    }

    private function subscription(Shop $shop, Package $package, string $macAddress, $expiresAt): Subscription
    {
        return Subscription::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'mac_address' => $macAddress,
            'starts_at' => now()->subHour(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function grantRadiusRows(Subscription $subscription, Package $package): void
    {
        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $subscription->mac_address,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => 'authenticated_device_pass',
            ]
        );
        DB::table('radusergroup')->updateOrInsert(
            ['username' => $subscription->mac_address],
            [
                'groupname' => $package->radius_group_name,
                'priority' => 1,
            ]
        );
    }
}
