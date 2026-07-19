<?php

namespace Tests\Feature;

use App\Livewire\Admin\SecurityActivitiesIndex;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityActivityReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_security_activity_report(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenantAdmin = User::factory()->create([
            'name' => 'Tenant Owner',
            'email' => 'tenant@example.com',
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $tenantAdmin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'passkey_login',
            'label' => 'Signed in with passkey: Office laptop.',
            'ip_address' => '10.8.0.10',
            'user_agent' => 'Feature Test Browser',
            'created_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->get(route('admin.security-activity.index'))
            ->assertOk()
            ->assertSee('Security Activity')
            ->assertSee('Signed in with passkey: Office laptop.')
            ->assertSee('Tenant Owner')
            ->assertSee('Mondi Internet');
    }

    public function test_security_activity_report_filters_for_super_admin(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenantAdmin = User::factory()->create([
            'name' => 'Tenant Owner',
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $tenantAdmin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'passkey_registered',
            'label' => 'Passkey registered: Office laptop.',
            'ip_address' => '10.8.0.10',
        ]);
        $tenantAdmin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'password_updated',
            'label' => 'Password changed from profile.',
            'ip_address' => '10.8.0.11',
        ]);

        Livewire::actingAs($superAdmin)
            ->test(SecurityActivitiesIndex::class)
            ->assertSee('Passkey registered: Office laptop.')
            ->assertSee('Password changed from profile.')
            ->set('action_group', 'passkey')
            ->assertSee('Passkey registered: Office laptop.')
            ->assertDontSee('Password changed from profile.')
            ->set('search', '10.8.0.10')
            ->assertSee('Passkey registered: Office laptop.')
            ->call('clearFilters')
            ->assertSet('action_group', '')
            ->assertSet('search', '')
            ->assertSet('date_preset', '30');
    }

    public function test_tenant_admin_only_sees_own_tenant_security_activity(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Own Tenant',
            'owner_email' => 'own@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $actor = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $ownUser = User::factory()->create([
            'name' => 'Own Staff',
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'name' => 'Other Staff',
            'tenant_id' => $otherTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $ownUser->securityActivities()->create([
            'tenant_id' => $ownTenant->id,
            'action' => 'login',
            'label' => 'Own tenant login.',
            'ip_address' => '10.8.0.20',
        ]);
        $otherUser->securityActivities()->create([
            'tenant_id' => $otherTenant->id,
            'action' => 'login',
            'label' => 'Other tenant login.',
            'ip_address' => '10.8.0.30',
        ]);

        $this->actingAs($actor)
            ->get(route('admin.security-activity.index'))
            ->assertOk()
            ->assertSee('Own tenant login.')
            ->assertDontSee('Other tenant login.')
            ->assertDontSee('All tenants');
    }
}
