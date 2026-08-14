<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterHotspotLoginPage;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouterHotspotLoginPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Login Page Router',
            'nas_identifier' => 'login-page-router',
            // Reserved documentation-only address, guaranteed unreachable.
            'wireguard_internal_ip' => '192.0.2.12',
            'shared_secret' => 'radius-secret',
        ]);
    }

    public function test_mount_defaults_to_the_default_hotspot_directory(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterHotspotLoginPage::class, ['router' => $router])
            ->assertSet('selectedDirectory', RouterOsConnectionService::DEFAULT_HOTSPOT_DIRECTORY)
            ->assertSet('directories', [])
            ->assertSet('hasListed', false);
    }

    public function test_list_directories_surfaces_a_connection_error_instead_of_crashing(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterHotspotLoginPage::class, ['router' => $router])
            ->call('listDirectories')
            ->assertSet('directories', [])
            ->assertSet('hasListed', true)
            ->assertSet('listError', fn (?string $error) => filled($error));
    }

    public function test_push_surfaces_a_connection_error_instead_of_crashing(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterHotspotLoginPage::class, ['router' => $router])
            ->call('push')
            ->assertSet('statusMessage', null)
            ->assertSet('pushError', fn (?string $error) => filled($error));
    }

    public function test_push_uses_the_custom_directory_when_the_toggle_is_on(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterHotspotLoginPage::class, ['router' => $router])
            ->set('useCustomDirectory', true)
            ->set('customDirectory', '')
            ->call('push')
            ->assertSet('pushError', 'Choose or enter a directory first.');
    }
}
