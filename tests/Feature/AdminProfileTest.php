<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProfileCard;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_profile(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant Owner',
            'email' => 'owner@example.com',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.profile.edit'))
            ->assertOk()
            ->assertSee('Tenant Owner')
            ->assertSee('owner@example.com')
            ->assertSee('Mondi Internet');
    }

    public function test_admin_can_update_profile_name_without_password_change(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'password' => 'current-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.profile.update'), [
                'name' => 'New Name',
            ])
            ->assertSessionHas('status');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_admin_can_change_own_password_from_profile(): void
    {
        $user = User::factory()->create([
            'password' => 'current-password',
            'role' => 'tenant_admin',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)
            ->put(route('admin.profile.update'), [
                'name' => $user->name,
                'current_password' => 'current-password',
                'password' => 'new-private-password',
                'password_confirmation' => 'new-private-password',
            ])
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-private-password', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_livewire_profile_card_updates_name_and_password_without_page_reload(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'password' => 'current-password',
            'role' => 'tenant_admin',
            'is_active' => true,
            'must_change_password' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ProfileCard::class)
            ->set('name', 'Updated Owner')
            ->set('current_password', 'current-password')
            ->set('password', 'new-private-password')
            ->set('password_confirmation', 'new-private-password')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('name', 'Updated Owner')
            ->assertSet('current_password', '')
            ->assertSet('password', '')
            ->assertSee('Profile updated.');

        $freshUser = $user->fresh();

        $this->assertSame('Updated Owner', $freshUser->name);
        $this->assertTrue(Hash::check('new-private-password', $freshUser->password));
        $this->assertFalse($freshUser->must_change_password);
    }

    public function test_profile_password_change_requires_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'current-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.profile.update'), [
                'name' => $user->name,
                'current_password' => 'wrong-password',
                'password' => 'new-private-password',
                'password_confirmation' => 'new-private-password',
            ])
            ->assertSessionHasErrors('current_password');
    }
}
