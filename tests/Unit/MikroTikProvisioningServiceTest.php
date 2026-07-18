<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\MikroTikProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroTikProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_routeros_hotspot_script(): void
    {
        config([
            'app.url' => 'https://portal.example.com',
            'services.radius.server_ip' => '10.8.0.1',
            'services.radius.auth_port' => 1812,
            'services.radius.acct_port' => 1813,
            'services.wireguard.endpoint_host' => 'vpn.example.com',
            'services.wireguard.endpoint_port' => 13231,
            'services.wireguard.public_key' => 'server-public-key',
            'services.mikrotik.hotspot_dns_name' => 'hotspot.local',
        ]);

        $router = new Router([
            'nas_identifier' => 'shop-main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $script = app(MikroTikProvisioningService::class)->generateScript($router);

        $this->assertStringContainsString('/system identity set name="shop-main-router"', $script);
        $this->assertStringContainsString('endpoint-address=vpn.example.com', $script);
        $this->assertStringContainsString('/ip address add address=10.8.0.10/24 interface=wg-saas', $script);
        $this->assertStringContainsString('/radius add address=10.8.0.1 secret="radius-secret"', $script);
        $this->assertStringContainsString('authentication-port=1812 accounting-port=1813', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=portal.example.com action=allow', $script);
    }
}
