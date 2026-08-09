<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterConnectedDevices;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TrustedWifiDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouterConnectedDevicesTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Devices Router',
            'nas_identifier' => 'devices-router',
            // Reserved documentation-only address, guaranteed unreachable.
            'wireguard_internal_ip' => '192.0.2.11',
            'shared_secret' => 'radius-secret',
        ]);
    }

    public function test_mount_defaults_to_the_management_network(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterConnectedDevices::class, ['router' => $router])
            ->assertSet('network', 'mgmt')
            ->assertSet('leases', [])
            ->assertSet('hasLoaded', false);
    }

    public function test_refresh_surfaces_a_connection_error_instead_of_crashing(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterConnectedDevices::class, ['router' => $router])
            ->call('refresh')
            ->assertSet('leases', [])
            ->assertSet('hasLoaded', true)
            ->assertSet('error', fn (?string $error) => filled($error));
    }

    public function test_switching_network_clears_stale_leases(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterConnectedDevices::class, ['router' => $router])
            ->set('leases', [['mac_address' => 'AA:BB:CC:DD:EE:FF']])
            ->set('hasLoaded', true)
            ->set('network', 'staff')
            ->assertSet('leases', [])
            ->assertSet('hasLoaded', false);
    }

    public function test_register_as_trusted_creates_a_trusted_wifi_device(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterConnectedDevices::class, ['router' => $router])
            ->set('network', 'mgmt')
            ->call('registerAsTrusted', 'AA:BB:CC:DD:EE:FF', 'staff-laptop')
            ->assertSet('statusMessage', fn (?string $message) => filled($message));

        $this->assertDatabaseHas('trusted_wifi_devices', [
            'shop_id' => $router->shop_id,
            'network' => TrustedWifiDevice::NETWORK_MGMT,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_name' => 'staff-laptop',
        ]);
    }

    public function test_register_as_trusted_is_a_no_op_for_the_pos_network(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterConnectedDevices::class, ['router' => $router])
            ->set('network', 'pos')
            ->call('registerAsTrusted', 'AA:BB:CC:DD:EE:FF', 'pos-terminal')
            ->assertSet('statusMessage', null);

        $this->assertDatabaseCount('trusted_wifi_devices', 0);
    }
}
