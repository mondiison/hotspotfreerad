<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_cannot_access_tenant_management(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.tenants.index'))
            ->assertForbidden();
    }

    public function test_tenant_admin_only_sees_their_shops(): void
    {
        [$tenantOne, $tenantTwo] = $this->tenants();
        $ownShop = $this->shop($tenantOne, 'Own Shop');
        $otherShop = $this->shop($tenantTwo, 'Other Shop');

        $user = User::factory()->create([
            'tenant_id' => $tenantOne->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.shops.index'))
            ->assertOk()
            ->assertSee($ownShop->name)
            ->assertDontSee($otherShop->name);
    }

    public function test_tenant_admin_cannot_access_another_tenants_router(): void
    {
        [$tenantOne, $tenantTwo] = $this->tenants();
        $router = Router::create([
            'shop_id' => $this->shop($tenantTwo, 'Other Shop')->id,
            'name' => 'Other Router',
            'nas_identifier' => 'other-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenantOne->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.routers.show', $router))
            ->assertForbidden();
    }

    public function test_tenant_admin_cannot_create_package_for_another_tenants_shop(): void
    {
        [$tenantOne, $tenantTwo] = $this->tenants();
        $otherShop = $this->shop($tenantTwo, 'Other Shop');

        $user = User::factory()->create([
            'tenant_id' => $tenantOne->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.packages.store'), [
                'shop_id' => $otherShop->id,
                'name' => 'Blocked Plan',
                'price' => 500,
                'currency' => 'NGN',
                'limit_uptime_seconds' => 3600,
                'speed_limit_profile' => '5M/5M',
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('shop_id');

        $this->assertDatabaseMissing('packages', [
            'name' => 'Blocked Plan',
        ]);
    }

    public function test_tenant_admin_only_sees_their_packages(): void
    {
        [$tenantOne, $tenantTwo] = $this->tenants();
        $ownPackage = $this->package($this->shop($tenantOne, 'Own Shop'), 'Own Plan');
        $otherPackage = $this->package($this->shop($tenantTwo, 'Other Shop'), 'Other Plan');

        $user = User::factory()->create([
            'tenant_id' => $tenantOne->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.packages.index'))
            ->assertOk()
            ->assertSee($ownPackage->name)
            ->assertDontSee($otherPackage->name);
    }

    private function tenants(): array
    {
        return [
            Tenant::create([
                'company_name' => 'Tenant One',
                'owner_email' => 'one@example.com',
            ]),
            Tenant::create([
                'company_name' => 'Tenant Two',
                'owner_email' => 'two@example.com',
            ]),
        ];
    }

    private function shop(Tenant $tenant, string $name): Shop
    {
        return Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
        ]);
    }

    private function package(Shop $shop, string $name): Package
    {
        return Package::create([
            'shop_id' => $shop->id,
            'name' => $name,
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
    }
}
