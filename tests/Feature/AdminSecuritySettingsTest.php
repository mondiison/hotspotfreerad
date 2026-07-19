<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlatformSecuritySettings;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformSecuritySettingsService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_security_settings_page(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.security.index'))
            ->assertOk()
            ->assertSee('Security')
            ->assertSee('Require 2FA for super admins');
    }

    public function test_tenant_admin_cannot_view_platform_security_settings(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.security.index'))
            ->assertForbidden();
    }

    public function test_livewire_security_settings_updates_super_admin_two_factor_policy(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PlatformSecuritySettings::class)
            ->set('require_super_admin_two_factor', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Platform security settings updated.');

        $this->assertDatabaseHas('platform_settings', [
            'key' => PlatformSecuritySettingsService::REQUIRE_SUPER_ADMIN_TWO_FACTOR,
        ]);
        $this->assertTrue(app(PlatformSecuritySettingsService::class)->requireSuperAdminTwoFactor());
        $this->assertDatabaseHas('security_activities', [
            'user_id' => $user->id,
            'action' => 'platform_security_updated',
        ]);
    }

    public function test_security_settings_show_super_admin_two_factor_compliance(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecret();
        $readyAdmin = User::factory()->create([
            'name' => 'Ready Admin',
            'email' => 'ready@example.com',
            'role' => 'super_admin',
            'is_active' => true,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes(['ABCDE-12345']),
            'two_factor_confirmed_at' => now(),
        ]);
        $missingAdmin = User::factory()->create([
            'name' => 'Missing Admin',
            'email' => 'missing@example.com',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($readyAdmin)
            ->test(PlatformSecuritySettings::class)
            ->assertSee('Super Admin Compliance')
            ->assertSee('Ready Admin')
            ->assertSee('ready@example.com')
            ->assertSee('2FA enabled')
            ->assertSee('Missing Admin')
            ->assertSee('missing@example.com')
            ->assertSee('2FA not enabled')
            ->assertSee('Need setup');

        $this->assertFalse($missingAdmin->hasTwoFactorEnabled());
    }

    public function test_super_admin_without_two_factor_is_redirected_when_policy_requires_it(): void
    {
        PlatformSetting::create([
            'key' => PlatformSecuritySettingsService::REQUIRE_SUPER_ADMIN_TWO_FACTOR,
            'value' => ['value' => true],
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.profile.edit'))
            ->assertSessionHas('status', 'Platform policy requires two-factor authentication for super admins. Enable 2FA before continuing.');

        $this->actingAs($user)
            ->get(route('admin.profile.edit'))
            ->assertOk()
            ->assertSee('Two-Factor Authentication');
    }

    public function test_super_admin_with_two_factor_can_access_admin_when_policy_requires_it(): void
    {
        PlatformSetting::create([
            'key' => PlatformSecuritySettingsService::REQUIRE_SUPER_ADMIN_TWO_FACTOR,
            'value' => ['value' => true],
        ]);
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecret();
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes(['ABCDE-12345']),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }
}
