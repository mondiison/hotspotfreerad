<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Mail\HotspotTestMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_tenant_admin_uses_shared_login_and_redirects_to_tenant_slug(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'tenant@example.com',
            'password' => 'secret-password',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertRedirect(route('tenant.public-site', $tenant));

        $this->assertAuthenticatedAs($user);
    }

    public function test_tenant_admin_login_ignores_intended_admin_url_and_redirects_to_tenant_slug(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'tenant@example.com',
            'password' => 'secret-password',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->get(route('admin.payment-settings.index'))
            ->assertRedirect(route('login'));

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertRedirect(route('tenant.public-site', $tenant));
    }

    public function test_tenant_admin_with_temporary_password_must_change_password_before_workspace(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'tenant@example.com',
            'password' => 'temporary-password',
            'role' => 'tenant_admin',
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'temporary-password',
        ])
            ->assertRedirect(route('password.force-change'));

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('password.force-change'));
    }

    public function test_tenant_admin_can_change_temporary_password_and_continue(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'tenant@example.com',
            'password' => 'temporary-password',
            'role' => 'tenant_admin',
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $this->actingAs($user)
            ->put(route('password.force-update'), [
                'current_password' => 'temporary-password',
                'password' => 'private-password',
                'password_confirmation' => 'private-password',
            ])
            ->assertRedirect(route('tenant.public-site', $tenant));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('private-password', $user->fresh()->password));
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => 'secret-password',
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => 'inactive@example.com',
            'password' => 'secret-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_page_links_to_forgot_password(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Forgot password?')
            ->assertSee(route('password.request'), false);
    }

    public function test_tenant_admin_cannot_login_when_tenant_is_inactive(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'owner@example.com',
            'is_active' => false,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_forgot_password_sends_reset_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_user_can_reset_password(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'old-password',
            'role' => 'super_admin',
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_default_super_admin_command_creates_mondiison_account(): void
    {
        $this->artisan('hotspot:seed-super-admin just-password')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'mondiison@yahoo.com',
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $this->assertTrue(Hash::check('just-password', User::where('email', 'mondiison@yahoo.com')->first()->password));
    }

    public function test_tenant_admin_command_creates_login_for_existing_tenant(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Internet',
            'owner_email' => 'mondiison@gmail.com',
        ]);

        $this->artisan('hotspot:create-tenant-admin mondiison@gmail.com tenant-password --name="Mondi Admin"')
            ->assertSuccessful();

        $user = User::where('email', 'mondiison@gmail.com')->first();

        $this->assertSame('Mondi Admin', $user->name);
        $this->assertSame('tenant_admin', $user->role);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertTrue(Hash::check('tenant-password', $user->password));
    }

    public function test_mail_test_command_hands_message_to_mailer(): void
    {
        Mail::fake();

        $this->artisan('hotspot:test-mail mondiison@yahoo.com')
            ->assertSuccessful();

        Mail::assertSent(HotspotTestMail::class);
    }
}
