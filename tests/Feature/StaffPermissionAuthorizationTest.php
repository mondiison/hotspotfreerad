<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\StaffPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Staff Test ISP',
            'owner_email' => 'owner@example.com',
        ]);
    }

    public function test_staff_with_vouchers_permission_can_reach_vouchers_but_not_expenses(): void
    {
        $tenant = $this->makeTenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => [StaffPermissions::VOUCHERS],
            'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('admin.vouchers.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.expenses.index'))->assertForbidden();
    }

    public function test_staff_can_always_reach_shared_account_routes(): void
    {
        $tenant = $this->makeTenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => [],
            'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($staff)->get(route('admin.profile.edit'))->assertOk();
    }

    public function test_staff_can_never_reach_user_management_regardless_of_permissions(): void
    {
        $tenant = $this->makeTenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'permissions' => StaffPermissions::keys(),
            'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.shops.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.billing.index'))->assertForbidden();
    }

    public function test_tenant_admin_is_unaffected_by_the_staff_permission_map(): void
    {
        $tenant = $this->makeTenant();
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.expenses.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.shops.index'))->assertOk();
    }

    public function test_super_admin_is_unaffected_by_the_staff_permission_map(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)->get(route('admin.expenses.index'))->assertOk();
        $this->actingAs($superAdmin)->get(route('admin.users.index'))->assertOk();
    }

    public function test_tenant_admin_can_create_a_staff_user_with_scoped_permissions(): void
    {
        $tenant = $this->makeTenant();
        $actor = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($actor)
            ->post(route('admin.users.store'), [
                'tenant_id' => $tenant->id,
                'name' => 'Cashier One',
                'email' => 'cashier@example.com',
                'role' => 'tenant_staff',
                'permissions' => [StaffPermissions::VOUCHERS, StaffPermissions::POS],
                'password' => 'secret-password',
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.users.index'));

        $created = User::where('email', 'cashier@example.com')->firstOrFail();

        $this->assertSame($tenant->id, $created->tenant_id);
        $this->assertSame('tenant_staff', $created->role);
        $this->assertSame([StaffPermissions::VOUCHERS, StaffPermissions::POS], $created->permissions);
    }

    public function test_non_staff_users_have_permissions_cleared_regardless_of_input(): void
    {
        $tenant = $this->makeTenant();
        $actor = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($actor)
            ->post(route('admin.users.store'), [
                'tenant_id' => $tenant->id,
                'name' => 'Co Admin',
                'email' => 'co-admin@example.com',
                'role' => 'tenant_admin',
                'permissions' => [StaffPermissions::VOUCHERS],
                'password' => 'secret-password',
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.users.index'));

        $created = User::where('email', 'co-admin@example.com')->firstOrFail();

        $this->assertSame('tenant_admin', $created->role);
        $this->assertNull($created->permissions);
    }

    public function test_tenant_admin_cannot_create_a_super_admin(): void
    {
        $tenant = $this->makeTenant();
        $actor = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($actor)
            ->post(route('admin.users.store'), [
                'tenant_id' => $tenant->id,
                'name' => 'Sneaky',
                'email' => 'sneaky@example.com',
                'role' => 'super_admin',
                'password' => 'secret-password',
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }
}
