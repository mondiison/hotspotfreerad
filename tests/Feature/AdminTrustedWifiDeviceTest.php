<?php

namespace Tests\Feature;

use App\Livewire\Admin\TrustedWifiDevicesIndex;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TrustedWifiDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTrustedWifiDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trusted_wifi_devices_page_renders(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.trusted-wifi-devices.index'))
            ->assertOk()
            ->assertSee('Trusted Wi-Fi Devices')
            ->assertSee('Add Device');
    }

    public function test_tenant_admin_can_register_trusted_device_and_sync_radius(): void
    {
        [$user, $shop] = $this->tenantSetup();

        Livewire::actingAs($user)
            ->test(TrustedWifiDevicesIndex::class)
            ->set('shop_id', (string) $shop->id)
            ->set('network', TrustedWifiDevice::NETWORK_STAFF)
            ->set('device_name', "Tolu's Laptop")
            ->set('mac_address', 'aa-bb-cc-dd-ee-ff')
            ->set('owner_name', 'Tolu Adeyemi')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('trusted_wifi_devices', [
            'shop_id' => $shop->id,
            'network' => 'staff',
            'device_name' => "Tolu's Laptop",
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'attribute' => 'Cleartext-Password',
            'value' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    public function test_disabling_a_device_revokes_radius_access(): void
    {
        [$user, $shop] = $this->tenantSetup();

        $device = TrustedWifiDevice::create([
            'shop_id' => $shop->id,
            'network' => TrustedWifiDevice::NETWORK_MGMT,
            'device_name' => 'Admin Phone',
            'mac_address' => 'AA:BB:CC:DD:EE:22',
            'is_active' => true,
        ]);
        app(\App\Services\TrustedWifiDeviceManagementService::class)->syncSystem($device);

        Livewire::actingAs($user)
            ->test(TrustedWifiDevicesIndex::class)
            ->call('edit', $device->id)
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:22',
        ]);
    }

    public function test_tenant_admin_cannot_edit_another_tenants_device(): void
    {
        [, $ownShop] = $this->tenantSetup('Own ISP', 'own@example.com');
        [, $otherShop] = $this->tenantSetup('Other ISP', 'other@example.com');

        $otherDevice = TrustedWifiDevice::create([
            'shop_id' => $otherShop->id,
            'network' => TrustedWifiDevice::NETWORK_STAFF,
            'device_name' => 'Other Tenant Device',
            'mac_address' => 'AA:BB:CC:DD:EE:33',
            'is_active' => true,
        ]);

        $ownUser = User::factory()->create([
            'tenant_id' => Shop::find($ownShop->id)->tenant_id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($ownUser)
            ->test(TrustedWifiDevicesIndex::class)
            ->call('edit', $otherDevice->id)
            ->assertForbidden();
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

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        return [$user, $shop];
    }
}
