<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\FreeRadiusClientSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeRadiusClientSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_content_contains_router_clients(): void
    {
        $shop = $this->shop();

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Bebeji Router',
            'nas_identifier' => 'bebeji-router01',
            'wireguard_internal_ip' => '10.8.0.11',
            'shared_secret' => 'bOkHIAsjGfLyVK9NSHBRJsqog09',
        ]);

        $content = app(FreeRadiusClientSyncService::class)->managedContent();

        $this->assertStringContainsString('client bebeji-router01 {', $content);
        $this->assertStringContainsString('ipaddr = 10.8.0.11', $content);
        $this->assertStringContainsString('secret = "bOkHIAsjGfLyVK9NSHBRJsqog09"', $content);
        $this->assertStringContainsString('shortname = "bebeji-router01"', $content);
        $this->assertStringContainsString('nastype = mikrotik', $content);
    }

    public function test_sync_writes_configured_clients_file_when_enabled(): void
    {
        $path = storage_path('framework/testing/hotspotfreerad-radius-clients.conf');
        @unlink($path);
        config([
            'services.radius.manage_clients' => true,
            'services.radius.clients_file' => $path,
        ]);
        $shop = $this->shop();

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $result = app(FreeRadiusClientSyncService::class)->sync();

        $this->assertTrue($result['enabled']);
        $this->assertTrue($result['written']);
        $this->assertSame([], $result['errors']);
        $this->assertFileExists($path);
        $this->assertStringContainsString('client main-router {', file_get_contents($path));
        $this->assertStringContainsString('nastype = mikrotik', file_get_contents($path));
    }

    public function test_sync_does_not_write_when_disabled(): void
    {
        $path = storage_path('framework/testing/disabled-radius-clients.conf');
        @unlink($path);
        config([
            'services.radius.manage_clients' => false,
            'services.radius.clients_file' => $path,
        ]);

        $result = app(FreeRadiusClientSyncService::class)->sync();

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['written']);
        $this->assertFileDoesNotExist($path);
    }

    private function shop(): Shop
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);

        return Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);
    }
}
