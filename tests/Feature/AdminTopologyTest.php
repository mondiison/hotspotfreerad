<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTopologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_all_tenants_shops_and_routers(): void
    {
        $ownTenant = Tenant::create(['company_name' => 'Own ISP', 'owner_email' => 'own@example.com']);
        $otherTenant = Tenant::create(['company_name' => 'Other ISP', 'owner_email' => 'other@example.com']);

        $ownShop = Shop::create(['tenant_id' => $ownTenant->id, 'name' => 'Own Shop']);
        $otherShop = Shop::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Shop']);

        Router::create([
            'shop_id' => $ownShop->id,
            'name' => 'Own Router',
            'nas_identifier' => 'own-router',
            'wireguard_internal_ip' => '10.8.0.80',
            'shared_secret' => 'radius-secret',
        ]);
        Router::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Router',
            'nas_identifier' => 'other-router',
            'wireguard_internal_ip' => '10.8.0.81',
            'shared_secret' => 'radius-secret',
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('admin.topology.index'))
            ->assertOk()
            ->assertSee('Own ISP')
            ->assertSee('Own Shop')
            ->assertSee('Own Router')
            ->assertSee('Other ISP')
            ->assertSee('Other Shop')
            ->assertSee('Other Router');
    }

    public function test_tenant_admin_only_sees_their_own_tenant(): void
    {
        $ownTenant = Tenant::create(['company_name' => 'Own ISP', 'owner_email' => 'own@example.com']);
        $otherTenant = Tenant::create(['company_name' => 'Other ISP', 'owner_email' => 'other@example.com']);

        $ownShop = Shop::create(['tenant_id' => $ownTenant->id, 'name' => 'Own Shop']);
        $otherShop = Shop::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Shop']);

        Router::create([
            'shop_id' => $ownShop->id,
            'name' => 'Own Router',
            'nas_identifier' => 'own-router-2',
            'wireguard_internal_ip' => '10.8.0.82',
            'shared_secret' => 'radius-secret',
        ]);
        Router::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Router',
            'nas_identifier' => 'other-router-2',
            'wireguard_internal_ip' => '10.8.0.83',
            'shared_secret' => 'radius-secret',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.topology.index'))
            ->assertOk()
            ->assertSee('Own Shop')
            ->assertSee('Own Router')
            ->assertDontSee('Other ISP')
            ->assertDontSee('Other Shop')
            ->assertDontSee('Other Router');
    }

    public function test_empty_state_when_no_routers_registered(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('admin.topology.index'))
            ->assertOk()
            ->assertSee('No routers registered yet');
    }
}
