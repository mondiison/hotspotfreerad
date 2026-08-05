<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterWireGuardProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function makeShop(): Shop
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

    public function test_creating_a_router_auto_generates_a_wireguard_keypair(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Auto Key Router',
            'nas_identifier' => 'auto-key-router',
            'wireguard_internal_ip' => '10.8.0.55',
            'shared_secret' => 'radius-secret',
        ]);

        $this->assertNotNull($router->wireguard_private_key);
        $this->assertNotNull($router->wireguard_public_key);
        $this->assertSame(
            $router->wireguard_public_key,
            \App\Support\WireGuardKeyPair::publicKeyFor($router->wireguard_private_key)
        );
    }

    public function test_wireguard_private_key_is_hidden_from_serialization(): void
    {
        $router = Router::create([
            'shop_id' => $this->makeShop()->id,
            'name' => 'Hidden Key Router',
            'nas_identifier' => 'hidden-key-router',
            'wireguard_internal_ip' => '10.8.0.56',
            'shared_secret' => 'radius-secret',
        ]);

        $this->assertArrayNotHasKey('wireguard_private_key', $router->toArray());
        $this->assertArrayNotHasKey('shared_secret', $router->toArray());
    }

    public function test_suggested_wireguard_internal_ip_skips_used_addresses(): void
    {
        $shop = $this->makeShop();

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Router A',
            'nas_identifier' => 'router-a',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Router B',
            'nas_identifier' => 'router-b',
            'wireguard_internal_ip' => '10.8.0.12',
            'shared_secret' => 'radius-secret',
        ]);

        $suggested = app(RouterManagementService::class)->suggestedWireguardInternalIp();

        $this->assertSame('10.8.0.13', $suggested);
    }

    public function test_suggested_nas_identifier_is_unique_and_derived_from_shop_name(): void
    {
        $shop = $this->makeShop();

        $routers = app(RouterManagementService::class);
        $first = $routers->suggestedNasIdentifier($shop);

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Router A',
            'nas_identifier' => $first,
            'wireguard_internal_ip' => '10.8.0.60',
            'shared_secret' => 'radius-secret',
        ]);

        $second = $routers->suggestedNasIdentifier($shop);

        $this->assertSame('demo-shop-1', $first);
        $this->assertSame('demo-shop-2', $second);
        $this->assertNotSame($first, $second);
    }

    public function test_regenerating_wireguard_key_replaces_both_keys(): void
    {
        $shop = $this->makeShop();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Regenerate Router',
            'nas_identifier' => 'regenerate-router',
            'wireguard_internal_ip' => '10.8.0.61',
            'shared_secret' => 'radius-secret',
        ]);

        $originalPublicKey = $router->wireguard_public_key;

        app(RouterManagementService::class)->regenerateWireguardKey($router, $user);
        $router->refresh();

        $this->assertNotSame($originalPublicKey, $router->wireguard_public_key);
    }

    public function test_router_script_page_shows_the_generated_public_key(): void
    {
        $shop = $this->makeShop();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Visible Key Router',
            'nas_identifier' => 'visible-key-router',
            'wireguard_internal_ip' => '10.8.0.62',
            'shared_secret' => 'radius-secret',
        ]);

        $this->actingAs($user)
            ->get(route('admin.routers.show', $router))
            ->assertOk()
            ->assertSee($router->wireguard_public_key)
            ->assertSee('registered as a Pi peer automatically');
    }
}
