<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantAdminTemporaryPassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminTenantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_creates_tenant_with_login_user(): void
    {
        Notification::fake();

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
        $this->assertTrue($tenantAdmin->must_change_password);

        Notification::assertSentTo($tenantAdmin, TenantAdminTemporaryPassword::class);
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
                'subscription_plan' => 'growth',
                'is_active' => 1,
                'public_site_enabled' => 1,
                'brand_color' => '#2563eb',
            ])
            ->assertRedirect(route('admin.tenants.index'));

        $this->assertSame('new@example.com', $tenantAdmin->fresh()->email);
    }

    public function test_super_admin_can_send_owner_password_reset_link(): void
    {
        Notification::fake();

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Legacy Tenant',
            'owner_email' => 'legacy@example.com',
        ]);
        $tenantAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'legacy@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.tenants.owner-reset-link', $tenant))
            ->assertSessionHas('status');

        Notification::assertSentTo($tenantAdmin, ResetPassword::class);
    }

    public function test_owner_password_reset_link_creates_missing_owner_login(): void
    {
        Notification::fake();

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
            ->post(route('admin.tenants.owner-reset-link', $tenant))
            ->assertSessionHas('status');

        $tenantAdmin = User::where('email', 'legacy@example.com')->firstOrFail();

        $this->assertSame($tenant->id, $tenantAdmin->tenant_id);
        $this->assertTrue($tenantAdmin->must_change_password);
        Notification::assertSentTo($tenantAdmin, ResetPassword::class);
    }
}
