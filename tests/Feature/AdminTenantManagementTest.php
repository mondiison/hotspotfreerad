<?php

namespace Tests\Feature;

use App\Livewire\Admin\TenantsIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantAdminTemporaryPassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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
        $this->assertSame('subscription', $tenant->billing_model);
        $this->assertSame('0.00', $tenant->commission_rate);
        $this->assertSame('tenant_admin', $tenantAdmin->role);
        $this->assertTrue($tenantAdmin->is_active);
        $this->assertTrue($tenantAdmin->must_change_password);

        Notification::assertSentTo($tenantAdmin, TenantAdminTemporaryPassword::class);
    }

    public function test_super_admin_can_create_commission_tenant(): void
    {
        Notification::fake();

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.tenants.store'), [
                'company_name' => 'Commission ISP',
                'slug' => 'commission-isp',
                'owner_email' => 'commission@example.com',
                'subscription_plan' => 'free',
                'billing_model' => 'commission',
                'commission_rate' => 12.5,
                'is_active' => 1,
                'public_site_enabled' => 1,
                'brand_color' => '#0f766e',
            ])
            ->assertRedirect(route('admin.tenants.index'));

        $tenant = Tenant::where('owner_email', 'commission@example.com')->firstOrFail();

        $this->assertSame('commission', $tenant->billing_model);
        $this->assertSame('12.50', $tenant->commission_rate);
    }

    public function test_tenant_index_shows_owner_access_status_and_reset_action(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $readyTenant = Tenant::create([
            'company_name' => 'Ready Tenant',
            'owner_email' => 'ready@example.com',
        ]);
        User::factory()->create([
            'tenant_id' => $readyTenant->id,
            'email' => 'ready@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $temporaryTenant = Tenant::create([
            'company_name' => 'Temporary Tenant',
            'owner_email' => 'temporary@example.com',
        ]);
        User::factory()->create([
            'tenant_id' => $temporaryTenant->id,
            'email' => 'temporary@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
            'must_change_password' => true,
        ]);
        Tenant::create([
            'company_name' => 'Missing Tenant',
            'owner_email' => 'missing@example.com',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertSee('Ready')
            ->assertSee('Temporary password')
            ->assertSee('Login missing')
            ->assertSee(route('admin.tenants.owner-reset-link', $readyTenant), false);
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

    public function test_livewire_tenant_index_creates_tenant_from_modal(): void
    {
        Notification::fake();

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($superAdmin)
            ->test(TenantsIndex::class)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('company_name', 'Modal ISP')
            ->set('slug', 'modal-isp')
            ->set('owner_email', 'modal@example.com')
            ->set('subscription_plan', 'growth')
            ->set('is_active', true)
            ->set('require_two_factor', true)
            ->set('public_site_enabled', true)
            ->set('brand_color', '#2563eb')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Tenant created and temporary password sent to owner email.');

        $tenant = Tenant::where('owner_email', 'modal@example.com')->firstOrFail();
        $tenantAdmin = User::where('email', 'modal@example.com')->firstOrFail();

        $this->assertSame($tenant->id, $tenantAdmin->tenant_id);
        $this->assertTrue($tenant->require_two_factor);
        $this->assertSame('tenant_admin', $tenantAdmin->role);
        $this->assertTrue($tenantAdmin->must_change_password);
        Notification::assertSentTo($tenantAdmin, TenantAdminTemporaryPassword::class);
    }

    public function test_livewire_tenant_index_edits_tenant_from_modal(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Old ISP',
            'owner_email' => 'old-modal@example.com',
            'subscription_plan' => 'basic',
            'billing_model' => 'subscription',
            'commission_rate' => 0,
            'brand_color' => '#0f766e',
        ]);
        $tenantAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'old-modal@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($superAdmin)
            ->test(TenantsIndex::class)
            ->call('edit', $tenant->id)
            ->assertSet('showFormModal', true)
            ->set('company_name', 'Updated ISP')
            ->set('owner_email', 'updated-modal@example.com')
            ->set('subscription_plan', 'pro')
            ->set('form_billing_model', 'commission')
            ->set('commission_rate', '15.5')
            ->set('require_two_factor', true)
            ->set('brand_color', '#7c3aed')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Tenant updated.');

        $tenant->refresh();

        $this->assertSame('Updated ISP', $tenant->company_name);
        $this->assertSame('updated-modal@example.com', $tenant->owner_email);
        $this->assertSame('pro', $tenant->subscription_plan);
        $this->assertSame('commission', $tenant->billing_model);
        $this->assertSame('15.50', $tenant->commission_rate);
        $this->assertTrue($tenant->require_two_factor);
        $this->assertSame('#7c3aed', $tenant->brand_color);
        $this->assertSame('updated-modal@example.com', $tenantAdmin->fresh()->email);
    }

    public function test_livewire_tenant_index_filters_without_page_reload(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        Tenant::create([
            'company_name' => 'Active Fiber',
            'owner_email' => 'active-fiber@example.com',
            'is_active' => true,
            'billing_model' => 'subscription',
        ]);
        Tenant::create([
            'company_name' => 'Commission WiFi',
            'owner_email' => 'commission-wifi@example.com',
            'is_active' => false,
            'billing_model' => 'commission',
            'commission_rate' => 10,
        ]);

        Livewire::actingAs($superAdmin)
            ->test(TenantsIndex::class)
            ->set('search', 'Commission')
            ->set('status', 'inactive')
            ->set('billing_model', 'commission')
            ->assertSee('Commission WiFi')
            ->assertDontSee('Active Fiber')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('status', '')
            ->assertSet('billing_model', '')
            ->assertSee('Commission WiFi')
            ->assertSee('Active Fiber');
    }

    public function test_livewire_tenant_index_sends_owner_reset_link(): void
    {
        Notification::fake();

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Reset Tenant',
            'owner_email' => 'reset-modal@example.com',
            'is_active' => true,
        ]);
        $tenantAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'reset-modal@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($superAdmin)
            ->test(TenantsIndex::class)
            ->call('sendResetLink', $tenant->id)
            ->assertHasNoErrors()
            ->assertSee('Password reset link sent to reset-modal@example.com.');

        Notification::assertSentTo($tenantAdmin, ResetPassword::class);
    }

    public function test_livewire_tenant_index_deletes_tenant_with_confirmation(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $tenant = Tenant::create([
            'company_name' => 'Delete Tenant',
            'owner_email' => 'delete-modal@example.com',
        ]);

        Livewire::actingAs($superAdmin)
            ->test(TenantsIndex::class)
            ->call('confirmDelete', $tenant->id)
            ->assertSet('showDeleteModal', true)
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertSee('Tenant deleted.');

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }
}
