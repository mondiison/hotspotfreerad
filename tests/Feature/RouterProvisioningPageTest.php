<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterProvisioningPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_show_page_displays_generated_routeros_script(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.routers.show', $router))
            ->assertOk()
            ->assertSee('/system identity set name=&quot;demo-router&quot;', false)
            ->assertSee('endpoint-address=vpn.example.com')
            ->assertSee('/radius add address=10.8.0.1 secret=&quot;radius-secret&quot; service=hotspot,ppp', false)
            ->assertSee('/ip hotspot walled-garden add dst-host=portal.example.com action=allow')
            ->assertSee('Fresh MikroTik Infrastructure Script')
            ->assertSee('Starlink plaza / high concurrency')
            ->assertSee('MMS POS = WPA2/WPA3 SSID tagged VLAN 50')
            ->assertSee('/queue type add name=pcq-hotspot-down kind=pcq')
            ->assertSee('AP / SSID / VLAN Guide')
            ->assertSee('RouterOS PPPoE Script')
            ->assertSee('/ppp aaa set use-radius=yes accounting=yes interim-update=5m')
            ->assertSee('/interface pppoe-server server add interface=bridge1 service-name=mms-radius')
            ->assertSee('Config In Use')
            ->assertSee('MikroTik login.html')
            ->assertSee('https://portal.example.com/hotspot/portal')
            ->assertSee('window.location.replace(portal)')
            ->assertSee('/ip hotspot active remove', false)
            ->assertSee('sudo freeradius -X');
    }

    public function test_router_provisioning_settings_are_saved_and_used_on_script_page(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.public_key' => 'server-public-key',
        ]);

        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.routers.store'), [
                'shop_id' => $shop->id,
                'name' => 'Custom Core',
                'nas_identifier' => 'custom-core',
                'wireguard_internal_ip' => '10.8.0.44',
                'shared_secret' => 'radius-secret',
                'provisioning_settings' => [
                    'profile' => 'small_hotspot',
                    'wan1' => 'ether5',
                    'wan2' => 'ether6',
                    'trunk_port' => 'sfp-sfpplus1',
                    'download_limit' => '70M',
                    'upload_limit' => '12M',
                    'mgmt_vlan' => 11,
                    'hotspot_vlan' => 120,
                    'staff_vlan' => 130,
                    'pppoe_vlan' => 140,
                    'pos_vlan' => 150,
                    'hotspot_gateway' => '10.20.0.1/22',
                    'hotspot_network' => '10.20.0.0/22',
                    'hotspot_pool' => '10.20.0.10-10.20.3.250',
                    'enable_pos' => false,
                    'enable_pppoe' => false,
                    'enable_realtime_qos' => false,
                    'enable_second_wan' => true,
                ],
            ])
            ->assertRedirect(route('admin.routers.index'));

        $router = Router::where('nas_identifier', 'custom-core')->firstOrFail();

        $this->assertSame('small_hotspot', $router->provisioning_settings['profile']);
        $this->assertSame('sfp-sfpplus1', $router->provisioning_settings['trunk_port']);
        $this->assertFalse($router->provisioning_settings['enable_pos']);

        $this->actingAs($user)
            ->get(route('admin.routers.show', $router))
            ->assertOk()
            ->assertSee(':local wan1 &quot;ether5&quot;', false)
            ->assertSee(':local hotspotVlan &quot;120&quot;', false)
            ->assertSee('vlan-ids=11,120,130')
            ->assertSee('POS VLAN is disabled')
            ->assertSee('Realtime QoS and PCQ are disabled');
    }
}
