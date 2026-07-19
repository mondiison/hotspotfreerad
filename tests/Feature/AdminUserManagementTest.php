<?php

namespace Tests\Feature;

use App\Livewire\Admin\UsersIndex;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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

    public function test_livewire_user_index_creates_tenant_admin_from_modal(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $platformUser = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($platformUser)
            ->test(UsersIndex::class)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('tenant_id', (string) $tenant->id)
            ->set('name', 'Tenant Manager')
            ->set('email', 'manager@example.com')
            ->set('user_role', 'tenant_admin')
            ->set('password', 'secret-password')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('User created.')
            ->assertSee('Tenant Manager');

        $created = User::where('email', 'manager@example.com')->firstOrFail();

        $this->assertSame($tenant->id, $created->tenant_id);
        $this->assertSame('tenant_admin', $created->role);
        $this->assertTrue(Hash::check('secret-password', $created->password));
    }

    public function test_livewire_user_index_edits_user_and_keeps_blank_password(): void
    {
        $platformUser = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $managedUser = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
            'password' => 'original-password',
        ]);

        Livewire::actingAs($platformUser)
            ->test(UsersIndex::class)
            ->call('edit', $managedUser->id)
            ->assertSet('showFormModal', true)
            ->assertSet('name', 'Old Name')
            ->set('name', 'Updated Name')
            ->set('email', 'updated@example.com')
            ->set('user_role', 'super_admin')
            ->set('password', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('User updated.');

        $managedUser->refresh();

        $this->assertSame('Updated Name', $managedUser->name);
        $this->assertSame('updated@example.com', $managedUser->email);
        $this->assertTrue(Hash::check('original-password', $managedUser->password));
    }

    public function test_livewire_user_index_filters_without_page_reload(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Filter Tenant',
            'owner_email' => 'filter@example.com',
        ]);
        $platformUser = User::factory()->create([
            'name' => 'Platform Admin',
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        User::factory()->create([
            'name' => 'Tenant Staff',
            'role' => 'tenant_admin',
            'tenant_id' => $tenant->id,
            'is_active' => false,
        ]);
        $securedUser = User::factory()->create([
            'name' => 'Secured Admin',
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $securedUser->passkeys()->create([
            'name' => 'Office laptop',
            'credential_id' => 'user-index-passkey',
            'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
        ]);

        Livewire::actingAs($platformUser)
            ->test(UsersIndex::class)
            ->set('search', 'Tenant')
            ->set('role', 'tenant_admin')
            ->set('status', 'inactive')
            ->assertSee('Tenant Staff')
            ->assertDontSee('Platform Admin')
            ->set('search', '')
            ->set('role', '')
            ->set('status', '')
            ->set('passkey_status', 'registered')
            ->assertSee('Secured Admin')
            ->assertSee('1 passkey')
            ->assertDontSee('Tenant Staff')
            ->set('passkey_status', 'missing')
            ->assertSee('Tenant Staff')
            ->assertDontSee('Secured Admin')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('role', '')
            ->assertSet('status', '')
            ->assertSet('passkey_status', '')
            ->assertSee('Platform Admin');
    }

    public function test_livewire_user_index_sends_password_reset_link_to_managed_user(): void
    {
        Notification::fake();

        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $actor = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $managedUser = User::factory()->create([
            'name' => 'Tenant Staff',
            'email' => 'staff@example.com',
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($actor)
            ->test(UsersIndex::class)
            ->call('sendPasswordResetLink', $managedUser->id)
            ->assertHasNoErrors()
            ->assertSee('Password reset link sent to staff@example.com.');

        Notification::assertSentTo($managedUser, ResetPassword::class);
        $this->assertDatabaseHas('security_activities', [
            'user_id' => $actor->id,
            'action' => 'managed_user_password_reset_sent',
        ]);
    }

    public function test_livewire_user_index_cannot_send_reset_link_to_other_tenant_user(): void
    {
        Notification::fake();

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
        $otherUser = User::factory()->create([
            'email' => 'other-staff@example.com',
            'tenant_id' => $otherTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($actor)
            ->test(UsersIndex::class)
            ->call('sendPasswordResetLink', $otherUser->id)
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_livewire_user_index_deletes_user_with_confirmation(): void
    {
        $platformUser = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $managedUser = User::factory()->create([
            'name' => 'Delete User',
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($platformUser)
            ->test(UsersIndex::class)
            ->call('confirmDelete', $managedUser->id)
            ->assertSet('showDeleteModal', true)
            ->assertSee('Delete User')
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertSee('User deleted.');

        $this->assertDatabaseMissing('users', [
            'id' => $managedUser->id,
        ]);
    }

    public function test_livewire_user_index_cannot_delete_self(): void
    {
        $platformUser = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($platformUser)
            ->test(UsersIndex::class)
            ->set('deletingUserId', $platformUser->id)
            ->call('delete')
            ->assertHasErrors('user');

        $this->assertDatabaseHas('users', [
            'id' => $platformUser->id,
        ]);
    }
}
