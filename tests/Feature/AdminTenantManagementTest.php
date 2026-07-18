<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTenantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_creates_tenant_with_login_user(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.tenants.store'), [
                'company_name' => 'Mondi Internet',
                'slug' => 'mondi-internet',
                'owner_email' => 'mondiison@gmail.com',
                'owner_password' => 'tenant-password',
                'owner_password_confirmation' => 'tenant-password',
                'subscription_plan' => 'basic',
                'is_active' => 1,
                'public_site_enabled' => 1,
                'brand_color' => '#0f766e',
            ])
            ->assertRedirect(route('admin.tenants.index'));

        $tenant = Tenant::where('owner_email', 'mondiison@gmail.com')->firstOrFail();
        $tenantAdmin = User::where('email', 'mondiison@gmail.com')->firstOrFail();

        $this->assertSame($tenant->id, $tenantAdmin->tenant_id);
        $this->assertSame('tenant_admin', $tenantAdmin->role);
        $this->assertTrue($tenantAdmin->is_active);
        $this->assertTrue(Hash::check('tenant-password', $tenantAdmin->password));
    }

    public function test_super_admin_updates_tenant_owner_email_on_existing_login_user(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'old@example.com',
        ]);
        $tenantAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'old@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('admin.tenants.update', $tenant), [
                'company_name' => 'Mondi Internet',
                'slug' => $tenant->slug,
                'owner_email' => 'new@example.com',
                'owner_password' => 'new-password',
                'owner_password_confirmation' => 'new-password',
                'subscription_plan' => 'growth',
                'is_active' => 1,
                'public_site_enabled' => 1,
                'brand_color' => '#2563eb',
            ])
            ->assertRedirect(route('admin.tenants.index'));

        $this->assertSame('new@example.com', $tenantAdmin->fresh()->email);
        $this->assertTrue(Hash::check('new-password', $tenantAdmin->fresh()->password));
    }

    public function test_super_admin_can_create_missing_owner_login_when_updating_tenant(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Legacy Tenant',
            'owner_email' => 'legacy@example.com',
        ]);

        $this->actingAs($superAdmin)
            ->put(route('admin.tenants.update', $tenant), [
                'company_name' => 'Legacy Tenant',
                'slug' => $tenant->slug,
                'owner_email' => 'legacy@example.com',
                'owner_password' => 'legacy-password',
                'owner_password_confirmation' => 'legacy-password',
                'subscription_plan' => 'basic',
                'is_active' => 1,
                'public_site_enabled' => 1,
                'brand_color' => '#0f766e',
            ])
            ->assertRedirect(route('admin.tenants.index'));

        $tenantAdmin = User::where('email', 'legacy@example.com')->firstOrFail();

        $this->assertSame($tenant->id, $tenantAdmin->tenant_id);
        $this->assertTrue(Hash::check('legacy-password', $tenantAdmin->password));
    }
}
