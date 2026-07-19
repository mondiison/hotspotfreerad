<?php

namespace Tests\Feature;

use App\Livewire\Admin\SecurityActivitiesIndex;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
            ->assertSet('attention', '')
            ->assertSet('date_preset', '30');
    }

    public function test_security_activity_report_filters_attention_events(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Attention Tenant',
            'owner_email' => 'attention@example.com',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $admin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'two_factor_challenge_failed',
            'label' => 'Two-factor challenge failed.',
            'ip_address' => '10.8.0.70',
        ]);
        $admin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'login',
            'label' => 'Normal sign-in event.',
            'ip_address' => '10.8.0.71',
        ]);

        Livewire::actingAs($admin)
            ->test(SecurityActivitiesIndex::class)
            ->assertSee('Needs attention')
            ->assertSee('Two-factor challenge failed.')
            ->assertSee('Normal sign-in event.')
            ->set('attention', '1')
            ->assertSee('Two-factor challenge failed.')
            ->assertDontSee('Normal sign-in event.');
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

    public function test_admin_can_open_security_activity_detail_modal(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Detail Tenant',
            'owner_email' => 'detail@example.com',
        ]);
        $admin = User::factory()->create([
            'name' => 'Detail Owner',
            'email' => 'detail-owner@example.com',
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $activity = $admin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'passkey_registered',
            'label' => 'Passkey registered: Detail laptop.',
            'ip_address' => '10.8.0.60',
            'user_agent' => 'Feature Test Detail Browser',
            'metadata' => [
                'passkey_name' => 'Detail laptop',
                'transport' => ['internal'],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(SecurityActivitiesIndex::class)
            ->call('viewActivity', $activity->id)
            ->assertSet('showDetailModal', true)
            ->assertSet('selectedActivityId', $activity->id)
            ->assertSee('Feature Test Detail Browser')
            ->assertSee('passkey_name')
            ->assertSee('Detail laptop')
            ->call('closeDetailModal')
            ->assertSet('showDetailModal', false)
            ->assertSet('selectedActivityId', null);
    }

    public function test_tenant_admin_cannot_open_another_tenants_security_activity_detail(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Own Detail Tenant',
            'owner_email' => 'own-detail@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Detail Tenant',
            'owner_email' => 'other-detail@example.com',
        ]);
        $actor = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $activity = $otherUser->securityActivities()->create([
            'tenant_id' => $otherTenant->id,
            'action' => 'login',
            'label' => 'Other tenant detail login.',
            'ip_address' => '10.8.0.61',
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($actor)
            ->test(SecurityActivitiesIndex::class)
            ->call('viewActivity', $activity->id);
    }

    public function test_security_activity_report_can_be_exported_as_csv(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Export Tenant',
            'owner_email' => 'export@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Export Tenant',
            'owner_email' => 'other-export@example.com',
        ]);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenantAdmin = User::factory()->create([
            'name' => 'Export Owner',
            'email' => 'export-owner@example.com',
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $otherAdmin = User::factory()->create([
            'name' => 'Other Export Owner',
            'tenant_id' => $otherTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $tenantAdmin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'passkey_registered',
            'label' => 'Passkey registered: Export laptop.',
            'ip_address' => '10.8.0.40',
            'metadata' => ['passkey' => 'Export laptop'],
        ]);
        $tenantAdmin->securityActivities()->create([
            'tenant_id' => $tenant->id,
            'action' => 'password_updated',
            'label' => 'Password changed from export test.',
            'ip_address' => '10.8.0.41',
        ]);
        $otherAdmin->securityActivities()->create([
            'tenant_id' => $otherTenant->id,
            'action' => 'passkey_registered',
            'label' => 'Other tenant passkey event.',
            'ip_address' => '10.8.0.42',
        ]);

        $response = $this->actingAs($superAdmin)
            ->get(route('admin.security-activity.export', [
                'tenant_id' => $tenant->id,
                'action_group' => 'passkey',
            ]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Security Activity Report', $content);
        $this->assertStringContainsString('"Event Group",Passkeys', $content);
        $this->assertStringContainsString('"Attention Only",No', $content);
        $this->assertStringContainsString('"Created At",Event,Action,Priority', $content);
        $this->assertStringContainsString('Passkey registered: Export laptop.', $content);
        $this->assertStringContainsString('Normal', $content);
        $this->assertStringContainsString('Export Owner', $content);
        $this->assertStringContainsString('Export Tenant', $content);
        $this->assertStringNotContainsString('Password changed from export test.', $content);
        $this->assertStringNotContainsString('Other tenant passkey event.', $content);
    }

    public function test_tenant_admin_exports_only_own_security_activity(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Tenant Export Scope',
            'owner_email' => 'own-scope@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Export Scope',
            'owner_email' => 'other-scope@example.com',
        ]);
        $actor = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $ownUser = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $ownUser->securityActivities()->create([
            'tenant_id' => $ownTenant->id,
            'action' => 'login',
            'label' => 'Own exported login.',
            'ip_address' => '10.8.0.50',
        ]);
        $otherUser->securityActivities()->create([
            'tenant_id' => $otherTenant->id,
            'action' => 'login',
            'label' => 'Other exported login.',
            'ip_address' => '10.8.0.51',
        ]);

        $response = $this->actingAs($actor)
            ->get(route('admin.security-activity.export', [
                'tenant_id' => $otherTenant->id,
                'date_preset' => 'all',
            ]));

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('Own exported login.', $content);
        $this->assertStringNotContainsString('Other exported login.', $content);
    }
}
