<?php

namespace Tests\Feature;

use App\Livewire\Admin\PosDevicesIndex;
use App\Models\Package;
use App\Models\PosDevice;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPosDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_devices_page_renders(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.pos-devices.index'))
            ->assertOk()
            ->assertSee('POS Devices')
            ->assertSee('Add POS Device');
    }

    public function test_tenant_admin_can_register_pos_device_and_sync_radius(): void
    {
        [$user, $shop, $package] = $this->tenantSetup();

        Livewire::actingAs($user)
            ->test(PosDevicesIndex::class)
            ->set('shop_id', (string) $shop->id)
            ->set('package_id', (string) $package->id)
            ->set('device_name', 'Shop 12 POS')
            ->set('mac_address', 'aa-bb-cc-dd-ee-ff')
            ->set('owner_name', 'Shop 12')
            ->set('phone', '07063218823')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pos_devices', [
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'device_name' => 'Shop 12 POS',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'attribute' => 'Cleartext-Password',
            'value' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $this->assertDatabaseHas('radusergroup', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'priority' => 1,
        ]);
    }

    public function test_renew_extends_pos_device_and_keeps_radius_synced(): void
    {
        [$user, $shop, $package] = $this->tenantSetup();
        $device = PosDevice::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'device_name' => 'Counter POS',
            'mac_address' => 'AA:BB:CC:DD:EE:11',
            'starts_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PosDevicesIndex::class)
            ->call('renew', $device->id)
            ->assertHasNoErrors();

        $device->refresh();

        $this->assertTrue($device->expires_at->isFuture());
        $this->assertNotNull($device->last_provisioned_at);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:11',
            'attribute' => 'Cleartext-Password',
        ]);
    }

    public function test_tenant_admin_cannot_manage_another_tenants_pos_device(): void
    {
        [$user] = $this->tenantSetup();
        [, $otherShop, $otherPackage] = $this->tenantSetup('Other Tenant', 'other@example.com');

        $device = PosDevice::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'device_name' => 'Other POS',
            'mac_address' => 'AA:BB:CC:DD:EE:22',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PosDevicesIndex::class)
            ->call('sync', $device->id)
            ->assertForbidden();
    }

    public function test_expired_pos_sync_command_revokes_only_inactive_or_expired_devices(): void
    {
        [, $shop, $package] = $this->tenantSetup();

        $expired = PosDevice::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'device_name' => 'Expired POS',
            'mac_address' => 'AA:BB:CC:DD:EE:33',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subMinute(),
            'is_active' => true,
        ]);
        $active = PosDevice::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'device_name' => 'Active POS',
            'mac_address' => 'AA:BB:CC:DD:EE:44',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ]);
        $disabled = PosDevice::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'device_name' => 'Disabled POS',
            'mac_address' => 'AA:BB:CC:DD:EE:55',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'is_active' => false,
        ]);

        foreach ([$expired, $active, $disabled] as $device) {
            DB::table('radcheck')->insert([
                'username' => $device->mac_address,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $device->mac_address,
            ]);
            DB::table('radusergroup')->insert([
                'username' => $device->mac_address,
                'groupname' => $package->radius_group_name ?: 'pos-test',
                'priority' => 1,
            ]);
        }

        $this->artisan('hotspot:sync-expired-pos')
            ->expectsOutput('Revoked 2 inactive or expired POS device(s) from RADIUS.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('radcheck', ['username' => 'AA:BB:CC:DD:EE:33']);
        $this->assertDatabaseMissing('radcheck', ['username' => 'AA:BB:CC:DD:EE:55']);
        $this->assertDatabaseHas('radcheck', ['username' => 'AA:BB:CC:DD:EE:44']);
        $this->assertDatabaseHas('radusergroup', ['username' => 'AA:BB:CC:DD:EE:44']);
    }

    private function tenantSetup(string $company = 'Demo ISP', string $email = 'owner@example.com'): array
    {
        $tenant = Tenant::create([
            'company_name' => $company,
            'owner_email' => $email,
        ]);

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $company.' Shop',
        ]);

        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'POS Monthly',
            'price' => 1500,
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '1M/1M',
            'service_type' => 'hotspot',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        DB::table('radcheck')->delete();
        DB::table('radusergroup')->delete();

        return [$user, $shop, $package];
    }
}
