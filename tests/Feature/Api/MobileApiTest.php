<?php

namespace Tests\Feature\Api;

use App\Models\Package;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-11-11: the mobile app's Phase 1 API surface (login/logout,
 * /api/me, /api/shops, /api/voucher-batches) -- every endpoint is a thin
 * wrapper over the exact services/scoping the web admin already uses
 * (VoucherManagementService, TenantAccess, StaffPermissions), so these
 * tests mirror AdminVoucherTest's own assertions rather than reinventing
 * them, just over JSON instead of Livewire.
 */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_bearer_token_and_user_profile(): void
    {
        [$tenant] = $this->fixture();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
            'device_name' => 'Test Device',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.tenant.company_name', $tenant->company_name)
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Test Device',
        ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        [$tenant] = $this->fixture();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
            'device_name' => 'Test Device',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_re_login_on_the_same_device_replaces_the_old_token(): void
    {
        [$tenant] = $this->fixture();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'correct-password', 'device_name' => 'Pixel 8']);
        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'correct-password', 'device_name' => 'Pixel 8']);

        $this->assertSame(1, $user->tokens()->where('name', 'Pixel 8')->count());
    }

    public function test_logout_revokes_the_current_token(): void
    {
        [$tenant] = $this->fixture();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $token = $user->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        // Sanctum's guard memoizes the resolved user on first use and is
        // cached by the AuthManager for the lifetime of this test's shared
        // container -- forgetGuards() forces a fresh token lookup for the
        // next call, the same as a genuinely separate HTTP request gets in
        // production (where every request boots a brand new container).
        auth()->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_shops_endpoint_only_returns_the_users_own_tenant(): void
    {
        [$tenantOne, $shopOne] = $this->fixture('Tenant One', 'Shop One');
        [$tenantTwo, $shopTwo] = $this->fixture('Tenant Two', 'Shop Two');
        $user = User::factory()->create(['tenant_id' => $tenantOne->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $token = $user->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/shops')
            ->assertOk()
            ->assertJsonFragment(['name' => $shopOne->name])
            ->assertJsonMissing(['name' => $shopTwo->name]);
    }

    public function test_staff_without_vouchers_permission_cannot_create_a_voucher_batch(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => ['pos'],
            'is_active' => true,
        ]);
        $token = $staff->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/voucher-batches', [
                'shop_id' => $shop->id,
                'package_id' => $package->id,
                'name' => 'Test Batch',
                'quantity' => 5,
                'code_length' => 8,
            ])
            ->assertForbidden();
    }

    public function test_staff_with_vouchers_permission_can_create_a_voucher_batch(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => ['vouchers'],
            'is_active' => true,
        ]);
        $token = $staff->createToken('Test Device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/voucher-batches', [
                'shop_id' => $shop->id,
                'package_id' => $package->id,
                'name' => 'Test Batch',
                'quantity' => 5,
                'code_length' => 8,
            ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Test Batch');

        $this->assertDatabaseHas('voucher_batches', [
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'Test Batch',
            'quantity' => 5,
        ]);
        $this->assertSame(5, Voucher::where('voucher_batch_id', $response->json('data.id'))->count());
    }

    public function test_batch_detail_includes_its_vouchers(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $token = $user->createToken('Test Device')->plainTextToken;
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id, 'package_id' => $package->id, 'name' => 'Batch A',
            'quantity' => 2, 'code_length' => 8, 'status' => 'active',
        ]);
        Voucher::create(['voucher_batch_id' => $batch->id, 'shop_id' => $shop->id, 'package_id' => $package->id, 'code' => 'ABC12345', 'status' => 'unused']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/voucher-batches/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.vouchers.0.code', 'ABC12345');
    }

    public function test_a_tenant_admin_cannot_view_another_tenants_voucher_batch(): void
    {
        [$tenantOne] = $this->fixture('Tenant One', 'Shop One');
        [$tenantTwo, $shopTwo, $packageTwo] = $this->fixture('Tenant Two', 'Shop Two');
        $batch = VoucherBatch::create([
            'shop_id' => $shopTwo->id, 'package_id' => $packageTwo->id, 'name' => 'Other Tenant Batch',
            'quantity' => 1, 'code_length' => 8, 'status' => 'active',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenantOne->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $token = $user->createToken('Test Device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/voucher-batches/{$batch->id}")
            ->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: Shop, 2: Package}
     */
    private function fixture(string $tenantName = 'Demo Tenant', string $shopName = 'Demo Shop'): array
    {
        $tenant = Tenant::create(['company_name' => $tenantName, 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => $shopName]);
        $package = Package::create([
            'shop_id' => $shop->id, 'name' => 'Home 10M', 'service_type' => 'hotspot', 'price' => 500,
            'currency' => 'NGN', 'limit_uptime_seconds' => 3600, 'speed_limit_profile' => '5M/5M', 'is_active' => true,
        ]);

        return [$tenant, $shop, $package];
    }
}
