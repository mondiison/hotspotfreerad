<?php

namespace Tests\Feature;

use App\Livewire\Admin\ProfileCard;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('Mondi Internet')
            ->assertSee('Profile photo')
            ->assertSee('Passkeys')
            ->assertSee('Not configured')
            ->assertSee(route('admin.passkeys.index'), false);
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
        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'password_updated',
            'label' => 'Password changed from profile.',
        ]);
    }

    public function test_livewire_profile_card_uploads_and_removes_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'name' => 'Avatar Owner',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ProfileCard::class)
            ->set('avatar', UploadedFile::fake()->image('owner.jpg', 400, 400))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Profile updated.');

        $freshUser = $user->fresh();

        $this->assertNotNull($freshUser->avatar_path);
        Storage::disk('public')->assertExists($freshUser->avatar_path);

        Livewire::actingAs($freshUser)
            ->test(ProfileCard::class)
            ->call('removeAvatar')
            ->assertHasNoErrors()
            ->assertSee('Profile photo removed.');

        Storage::disk('public')->assertMissing($freshUser->avatar_path);
        $this->assertNull($freshUser->fresh()->avatar_path);
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
            ->assertSee('Scan QR code')
            ->assertSee('<svg', false)
            ->assertDontSee('Preparing QR code')
            ->assertSee('Setup key')
            ->assertSee('Copy setup key')
            ->assertSee('Copy URI');

        $secret = $user->fresh()->two_factor_secret;

        $component
            ->set('two_factor_code', $twoFactor->currentCode($secret))
            ->call('confirmTwoFactor')
            ->assertHasNoErrors()
            ->assertSee('Two-factor authentication is enabled.')
            ->assertSee('Save these recovery codes now')
            ->assertSee('Copy recovery codes')
            ->assertSee('Download');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $user->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'two_factor_enabled',
        ]);

        $component
            ->call('regenerateRecoveryCodes')
            ->assertSee('Recovery codes regenerated.');
        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'recovery_codes_regenerated',
        ]);

        $component
            ->set('two_factor_disable_password', 'current-password')
            ->call('disableTwoFactor')
            ->assertHasNoErrors()
            ->assertSee('Two-factor authentication is disabled.');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'two_factor_disabled',
        ]);
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
        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'other_sessions_logged_out',
        ]);
    }

    public function test_livewire_profile_card_shows_recent_security_activity(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $user->securityActivities()->create([
            'action' => 'login',
            'label' => 'Signed in successfully.',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test Browser',
        ]);

        Livewire::actingAs($user)
            ->test(ProfileCard::class)
            ->assertSee('Security Activity')
            ->assertSee('Signed in successfully.')
            ->assertSee('127.0.0.1')
            ->assertSee('Feature Test Browser');
    }

    public function test_livewire_profile_card_shows_passkey_status(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $user->passkeys()->create([
            'name' => 'Office laptop',
            'credential_id' => 'profile-passkey',
            'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
        ]);

        Livewire::actingAs($user)
            ->test(ProfileCard::class)
            ->assertSee('Trusted device sign-in')
            ->assertSee('1 registered')
            ->assertSee('Manage passkeys');
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
