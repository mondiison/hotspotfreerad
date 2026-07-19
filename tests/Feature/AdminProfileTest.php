<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProfileCard;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_livewire_profile_card_enables_regenerates_and_disables_two_factor(): void
    {
        $user = User::factory()->create([
            'password' => 'current-password',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
        $twoFactor = app(TwoFactorService::class);

        $component = Livewire::actingAs($user)
            ->test(ProfileCard::class)
            ->call('startTwoFactorSetup')
            ->assertSee('Setup key');

        $secret = $user->fresh()->two_factor_secret;

        $component
            ->set('two_factor_code', $twoFactor->currentCode($secret))
            ->call('confirmTwoFactor')
            ->assertHasNoErrors()
            ->assertSee('Two-factor authentication is enabled.')
            ->assertSee('Save these recovery codes now');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $user->fresh()->two_factor_recovery_codes);

        $component
            ->call('regenerateRecoveryCodes')
            ->assertSee('Recovery codes regenerated.');

        $component
            ->set('two_factor_disable_password', 'current-password')
            ->call('disableTwoFactor')
            ->assertHasNoErrors()
            ->assertSee('Two-factor authentication is disabled.');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_livewire_profile_card_lists_and_logs_out_other_sessions(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create([
            'password' => 'current-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        session()->setId('current-session');

        DB::table('sessions')->insert([
            [
                'id' => 'current-session',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'other-session',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.44',
                'user_agent' => 'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1',
                'payload' => '',
                'last_activity' => now()->subMinutes(10)->timestamp,
            ],
        ]);

        Livewire::actingAs($user)
            ->test(ProfileCard::class)
            ->assertSee('Chrome on Windows')
            ->assertSee('Safari on iOS')
            ->set('logout_other_sessions_password', 'current-password')
            ->call('logoutOtherSessions')
            ->assertHasNoErrors()
            ->assertSee('Signed out');

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
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
