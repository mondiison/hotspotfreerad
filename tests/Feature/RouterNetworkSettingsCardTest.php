<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterNetworkSettingsCard;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouterNetworkSettingsCardTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(array $provisioningSettings = []): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner-'.uniqid().'@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Settings Card Router',
            'nas_identifier' => 'settings-card-router-'.uniqid(),
            'wireguard_internal_ip' => '10.8.0.'.random_int(20, 250),
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => $provisioningSettings,
        ]);
    }

    public function test_it_loads_existing_settings_for_the_pos_network(): void
    {
        $router = $this->makeRouter([
            'pos_ssid' => 'Cafe Till',
            'pos_vlan' => 55,
            'pos_gateway' => '192.168.55.1/24',
            'pos_network' => '192.168.55.0/24',
            'pos_pool' => '192.168.55.10-192.168.55.250',
            'extra_pos_port_numbers' => '6,7',
        ]);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->assertSet('ssid', 'Cafe Till')
            ->assertSet('vlan', 55)
            ->assertSet('gateway', '192.168.55.1/24')
            ->assertSet('networkCidr', '192.168.55.0/24')
            ->assertSet('pool', '192.168.55.10-192.168.55.250')
            ->assertSet('extraPorts', '6,7')
            ->assertSet('wifiPassword', '');
    }

    public function test_it_loads_existing_settings_for_the_hotspot_network(): void
    {
        $router = $this->makeRouter([
            'hotspot_ssid' => 'Cafe Free WiFi',
            'hotspot_vlan' => 21,
        ]);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'hotspot'])
            ->assertSet('ssid', 'Cafe Free WiFi')
            ->assertSet('vlan', 21);
    }

    public function test_saving_pos_settings_updates_only_pos_keys(): void
    {
        $router = $this->makeRouter([
            'hotspot_ssid' => 'MMS Hotspot',
            'hotspot_vlan' => 20,
            'pos_ssid' => 'MMS POS',
            'pos_vlan' => 50,
        ]);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->set('ssid', 'Cafe Till')
            ->set('vlan', 55)
            ->set('gateway', '192.168.55.1/24')
            ->set('networkCidr', '192.168.55.0/24')
            ->set('pool', '192.168.55.10-192.168.55.250')
            ->set('extraPorts', '6,7')
            ->call('save')
            ->assertHasNoErrors();

        $settings = $router->fresh()->provisioning_settings;

        $this->assertSame('Cafe Till', $settings['pos_ssid']);
        $this->assertSame(55, $settings['pos_vlan']);
        $this->assertSame('192.168.55.1/24', $settings['pos_gateway']);
        $this->assertSame('192.168.55.0/24', $settings['pos_network']);
        $this->assertSame('192.168.55.10-192.168.55.250', $settings['pos_pool']);
        $this->assertSame('6,7', $settings['extra_pos_port_numbers']);
        // Hotspot's own settings are untouched by a POS-scoped save.
        $this->assertSame('MMS Hotspot', $settings['hotspot_ssid']);
        $this->assertSame(20, $settings['hotspot_vlan']);
    }

    public function test_saving_hotspot_settings_does_not_touch_pos_settings(): void
    {
        $router = $this->makeRouter([
            'hotspot_ssid' => 'MMS Hotspot',
            'hotspot_vlan' => 20,
            'pos_ssid' => 'MMS POS',
            'pos_vlan' => 50,
            'pos_wifi_password' => 'OriginalPosPass1',
        ]);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'hotspot'])
            ->set('ssid', 'Cafe Free WiFi')
            ->set('vlan', 21)
            ->call('save')
            ->assertHasNoErrors();

        $settings = $router->fresh()->provisioning_settings;

        $this->assertSame('Cafe Free WiFi', $settings['hotspot_ssid']);
        $this->assertSame(21, $settings['hotspot_vlan']);
        $this->assertSame('MMS POS', $settings['pos_ssid']);
        $this->assertSame(50, $settings['pos_vlan']);
        $this->assertSame('OriginalPosPass1', $settings['pos_wifi_password']);
    }

    public function test_blank_wifi_password_keeps_the_previously_saved_password(): void
    {
        $router = $this->makeRouter(['pos_wifi_password' => 'KeepMePlease1']);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->set('vlan', 50)
            ->set('wifiPassword', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('KeepMePlease1', $router->fresh()->provisioning_settings['pos_wifi_password']);
    }

    public function test_a_new_wifi_password_replaces_the_saved_one(): void
    {
        $router = $this->makeRouter(['pos_wifi_password' => 'OldPassword1']);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->set('vlan', 50)
            ->set('wifiPassword', 'BrandNewPass2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('BrandNewPass2', $router->fresh()->provisioning_settings['pos_wifi_password']);
    }

    public function test_an_invalid_vlan_is_rejected(): void
    {
        $router = $this->makeRouter();

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->set('vlan', 5000)
            ->call('save')
            ->assertHasErrors(['vlan']);
    }

    public function test_a_malformed_extra_port_list_is_rejected_in_simple_mode(): void
    {
        $router = $this->makeRouter(['ports_advanced_mode' => false]);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->set('vlan', 50)
            ->set('extraPorts', 'ether5,ether6')
            ->call('save')
            ->assertHasErrors(['extraPorts']);
    }

    public function test_an_extra_port_colliding_with_another_role_is_rejected(): void
    {
        $router = $this->makeRouter([
            'port_count' => 8,
            'wan1_port_number' => 1,
            'trunk_port_number' => 2,
            'pi_port_number' => 3,
            'ports_advanced_mode' => false,
            'enable_pos' => false,
        ]);

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->set('vlan', 50)
            ->set('extraPorts', '3')
            ->call('save')
            ->assertHasErrors(['extraPorts']);

        $this->assertArrayNotHasKey('extra_pos_port_numbers', $router->fresh()->provisioning_settings ?? []);
    }

    public function test_saving_never_makes_a_live_routeros_call(): void
    {
        $router = $this->makeRouter();

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldNotReceive('provisionHotspot');
            $mock->shouldNotReceive('provisionPos');
            $mock->shouldNotReceive('provisionPppoe');
        });

        Livewire::test(RouterNetworkSettingsCard::class, ['router' => $router, 'network' => 'pos'])
            ->set('vlan', 51)
            ->call('save')
            ->assertHasNoErrors();
    }
}
