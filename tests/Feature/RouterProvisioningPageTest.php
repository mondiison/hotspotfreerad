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
}
