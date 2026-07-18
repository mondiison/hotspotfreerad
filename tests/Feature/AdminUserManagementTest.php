<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_manage_platform_and_tenant_users(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $platformUser = User::factory()->create([
            'name' => 'Platform Owner',
            'email' => 'platform@example.com',
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $tenantUser = User::factory()->create([
            'name' => 'Tenant Owner',
            'email' => 'tenant@example.com',
            'role' => 'tenant_admin',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $this->actingAs($platformUser)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Platform Owner')
            ->assertSee('Tenant Owner')
            ->assertSee('Mondi Internet');

        $this->actingAs($platformUser)
            ->get(route('admin.users.edit', $tenantUser))
            ->assertOk()
            ->assertSee('Edit User');
    }

    public function test_tenant_admin_only_manages_own_tenant_users(): void
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

        $this->actingAs($actor)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Own Staff')
            ->assertDontSee('Other Staff');

        $this->actingAs($actor)
            ->get(route('admin.users.edit', $ownUser))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.users.edit', $otherUser))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_create_user_only_inside_own_tenant(): void
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

        $this->actingAs($actor)
            ->post(route('admin.users.store'), [
                'tenant_id' => $otherTenant->id,
                'name' => 'New Tenant Admin',
                'email' => 'new-admin@example.com',
                'role' => 'super_admin',
                'password' => 'secret-password',
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.users.index'));

        $created = User::where('email', 'new-admin@example.com')->firstOrFail();

        $this->assertSame($ownTenant->id, $created->tenant_id);
        $this->assertSame('tenant_admin', $created->role);
        $this->assertTrue(Hash::check('secret-password', $created->password));
    }

    public function test_user_cannot_delete_self(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.users.destroy', $user))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }
}
