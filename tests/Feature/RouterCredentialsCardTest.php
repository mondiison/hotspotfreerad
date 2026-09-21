<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterCredentialsCard;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouterCredentialsCardTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(array $overrides = []): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner-'.uniqid().'@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create(array_merge([
            'shop_id' => $shop->id,
            'name' => 'ZT Router',
            'nas_identifier' => 'zt-router-'.uniqid(),
            'wireguard_internal_ip' => '10.8.0.'.random_int(20, 250),
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'zerotier',
        ], $overrides));
    }

    public function test_saving_a_node_id_for_the_first_time_persists_it(): void
    {
        $router = $this->makeRouter();

        Livewire::test(RouterCredentialsCard::class, ['router' => $router])
            ->set('zerotierNodeId', 'a943bf5013')
            ->call('saveZeroTierNodeId')
            ->assertHasNoErrors();

        $this->assertSame('a943bf5013', $router->fresh()->zerotier_node_id);
    }

    public function test_changing_an_already_authorized_node_id_clears_the_authorization_timestamp(): void
    {
        $router = $this->makeRouter([
            'zerotier_node_id' => 'oldnode0001',
            'zerotier_authorized_at' => now(),
        ]);

        Livewire::test(RouterCredentialsCard::class, ['router' => $router])
            ->set('zerotierNodeId', 'newnode0002')
            ->call('saveZeroTierNodeId')
            ->assertHasNoErrors();

        $router->refresh();
        $this->assertSame('newnode0002', $router->zerotier_node_id);
        $this->assertNull($router->zerotier_authorized_at);
    }

    public function test_resaving_the_same_node_id_does_not_clear_the_authorization_timestamp(): void
    {
        $authorizedAt = now()->subMinutes(5);

        $router = $this->makeRouter([
            'zerotier_node_id' => 'samenode001',
            'zerotier_authorized_at' => $authorizedAt,
        ]);

        Livewire::test(RouterCredentialsCard::class, ['router' => $router])
            ->set('zerotierNodeId', 'samenode001')
            ->call('saveZeroTierNodeId')
            ->assertHasNoErrors();

        $router->refresh();
        $this->assertSame('samenode001', $router->zerotier_node_id);
        $this->assertNotNull($router->zerotier_authorized_at);
        $this->assertEquals($authorizedAt->timestamp, $router->zerotier_authorized_at->timestamp);
    }

    public function test_a_node_id_already_used_by_another_router_is_rejected(): void
    {
        $this->makeRouter(['zerotier_node_id' => 'takennode01']);
        $router = $this->makeRouter();

        Livewire::test(RouterCredentialsCard::class, ['router' => $router])
            ->set('zerotierNodeId', 'takennode01')
            ->call('saveZeroTierNodeId')
            ->assertHasErrors(['zerotier_node_id']);

        $this->assertNull($router->fresh()->zerotier_node_id);
    }

    public function test_clearing_the_node_id_saves_null(): void
    {
        $router = $this->makeRouter(['zerotier_node_id' => 'clearme0001']);

        Livewire::test(RouterCredentialsCard::class, ['router' => $router])
            ->set('zerotierNodeId', '')
            ->call('saveZeroTierNodeId')
            ->assertHasNoErrors();

        $this->assertNull($router->fresh()->zerotier_node_id);
    }
}
