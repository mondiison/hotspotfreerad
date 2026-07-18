<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_index_can_search_and_filter_by_status(): void
    {
        $tenant = $this->tenant();
        $activeShop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Hall',
            'location_city' => 'Ibadan',
            'is_active' => true,
        ]);
        $inactiveShop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Closed Annex',
            'location_city' => 'Lagos',
            'is_active' => false,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.shops.index', ['search' => 'Ibadan', 'status' => 'active']))
            ->assertOk()
            ->assertSee($activeShop->name)
            ->assertDontSee($inactiveShop->name);
    }

    public function test_router_index_can_search_and_filter_by_online_status(): void
    {
        $shop = $this->shop();
        $onlineRouter = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
            'is_online' => true,
        ]);
        $offlineRouter = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Backup Router',
            'nas_identifier' => 'backup-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
            'is_online' => false,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.routers.index', ['search' => 'main-router', 'status' => 'online']))
            ->assertOk()
            ->assertSee($onlineRouter->name)
            ->assertDontSee($offlineRouter->name);
    }

    public function test_package_index_can_search_and_filter_by_active_status(): void
    {
        $shop = $this->shop();
        $activePackage = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $inactivePackage = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Legacy Plan',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '1M/1M',
            'is_active' => false,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.packages.index', ['search' => 'Daily', 'status' => 'active']))
            ->assertOk()
            ->assertSee($activePackage->name)
            ->assertDontSee($inactivePackage->name);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Demo Tenant',
            'owner_email' => fake()->unique()->safeEmail(),
        ]);
    }

    private function shop(): Shop
    {
        return Shop::create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'Demo Shop',
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
