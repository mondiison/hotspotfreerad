<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PppoeExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_pppoe_sync_command_reports_dry_run_without_revoking_radius_rows(): void
    {
        [$shop, $package] = $this->fixture();
        $subscriber = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'expired-user',
            'password' => 'secret-pass',
            'expires_at' => now()->subMinute(),
            'last_provisioned_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $this->grantRadiusRows($subscriber, $package);

        $this->artisan('hotspot:sync-expired-pppoe --dry-run')
            ->expectsOutput('1 inactive or expired PPPoE subscriber(s) would be revoked from RADIUS.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('radcheck', ['username' => 'expired-user']);
        $this->assertDatabaseHas('radusergroup', ['username' => 'expired-user']);
    }

    public function test_expired_pppoe_sync_command_revokes_only_inactive_or_expired_radius_rows(): void
    {
        [$shop, $package] = $this->fixture();
        $expired = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'expired-user',
            'password' => 'secret-pass',
            'expires_at' => now()->subMinute(),
            'last_provisioned_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $disabled = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'disabled-user',
            'password' => 'secret-pass',
            'expires_at' => now()->addMonth(),
            'last_provisioned_at' => now()->subDay(),
            'is_active' => false,
        ]);
        $active = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'active-user',
            'password' => 'secret-pass',
            'expires_at' => now()->addMonth(),
            'last_provisioned_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $this->grantRadiusRows($expired, $package);
        $this->grantRadiusRows($disabled, $package);
        $this->grantRadiusRows($active, $package);

        $this->artisan('hotspot:sync-expired-pppoe')
            ->expectsOutput('Revoked 2 inactive or expired PPPoE subscriber(s) from RADIUS.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('radcheck', ['username' => 'expired-user']);
        $this->assertDatabaseMissing('radusergroup', ['username' => 'expired-user']);
        $this->assertDatabaseMissing('radcheck', ['username' => 'disabled-user']);
        $this->assertDatabaseMissing('radusergroup', ['username' => 'disabled-user']);
        $this->assertDatabaseHas('radcheck', ['username' => 'active-user']);
        $this->assertDatabaseHas('radusergroup', ['username' => 'active-user']);
    }

    private function fixture(): array
    {
        $tenant = Tenant::create([
            'company_name' => 'PPPoE Expiry Tenant',
            'owner_email' => 'expiry@example.com',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Fiber',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Home 10M',
            'service_type' => 'pppoe',
            'price' => 12000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '10M/10M',
            'radius_group_name' => 'home_10m',
            'is_active' => true,
        ]);

        return [$shop, $package];
    }

    private function grantRadiusRows(PppoeSubscriber $subscriber, Package $package): void
    {
        DB::table('radcheck')->insert([
            'username' => $subscriber->username,
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => $subscriber->password,
        ]);
        DB::table('radusergroup')->insert([
            'username' => $subscriber->username,
            'groupname' => $package->radius_group_name,
            'priority' => 1,
        ]);
    }
}
