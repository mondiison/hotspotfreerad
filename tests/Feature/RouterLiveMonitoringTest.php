<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterConsole;
use App\Livewire\Admin\RouterLiveMonitor;
use App\Livewire\Admin\RouterWifiScan;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouterLiveMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Monitor Router',
            'nas_identifier' => 'monitor-router',
            // Reserved documentation-only address, guaranteed unreachable.
            'wireguard_internal_ip' => '192.0.2.10',
            'shared_secret' => 'radius-secret',
        ]);
    }

    public function test_live_monitor_surfaces_a_connection_error_instead_of_crashing(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterLiveMonitor::class, ['router' => $router])
            ->call('poll')
            ->assertSet('samples', [])
            ->assertSee('Could not reach this router');
    }

    public function test_live_monitor_surfaces_an_interface_list_error_instead_of_crashing(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterLiveMonitor::class, ['router' => $router])
            ->assertSet('interfaces', [])
            ->assertSet('interface', 'wg-saas')
            ->assertSet('interfacesError', fn (?string $error) => filled($error));
    }

    public function test_changing_the_interface_clears_stale_samples(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterLiveMonitor::class, ['router' => $router])
            ->set('samples', [['time' => '10:00:00', 'download_mbps' => 5, 'upload_mbps' => 1]])
            ->set('error', 'stale error')
            ->set('interface', 'ether1')
            ->assertSet('samples', [])
            ->assertSet('error', null);
    }

    public function test_list_interfaces_reports_a_clear_error_when_router_is_unreachable(): void
    {
        $router = $this->makeRouter();

        $result = app(RouterOsConnectionService::class)->listInterfaces($router);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_wifi_scan_surfaces_a_connection_error_instead_of_crashing(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterWifiScan::class, ['router' => $router])
            ->call('scan')
            ->assertSet('networks', [])
            ->assertSee('Scan failed');
    }

    public function test_console_surfaces_a_connection_error_instead_of_crashing(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterConsole::class, ['router' => $router])
            ->set('command', '/interface print')
            ->call('run')
            ->assertSet('rows', []);
    }

    public function test_console_rejects_non_print_commands_without_reaching_the_router(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterConsole::class, ['router' => $router])
            ->set('command', '/ip firewall filter add chain=input action=drop')
            ->call('run')
            ->assertSet('rows', [])
            ->assertSee('Only read-only');
    }
}
