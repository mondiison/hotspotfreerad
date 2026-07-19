<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityActivityRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_activity_prune_command_reports_dry_run_without_deleting(): void
    {
        $user = $this->createTenantAdminWithActivity();

        $user->securityActivities()->create([
            'tenant_id' => $user->tenant_id,
            'action' => 'login',
            'label' => 'Old login event.',
        ])->forceFill([
            'created_at' => now()->subDays(181),
            'updated_at' => now()->subDays(181),
        ])->save();
        $user->securityActivities()->create([
            'tenant_id' => $user->tenant_id,
            'action' => 'login',
            'label' => 'Recent login event.',
        ])->forceFill([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ])->save();

        $this->artisan('hotspot:prune-security-activity --days=180 --dry-run')
            ->expectsOutput('1 security activity record(s) older than 180 day(s) would be pruned.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('security_activities', ['label' => 'Old login event.']);
        $this->assertDatabaseHas('security_activities', ['label' => 'Recent login event.']);
    }

    public function test_security_activity_prune_command_deletes_only_expired_records(): void
    {
        $user = $this->createTenantAdminWithActivity();

        $user->securityActivities()->create([
            'tenant_id' => $user->tenant_id,
            'action' => 'password_updated',
            'label' => 'Expired password event.',
        ])->forceFill([
            'created_at' => now()->subDays(91),
            'updated_at' => now()->subDays(91),
        ])->save();
        $user->securityActivities()->create([
            'tenant_id' => $user->tenant_id,
            'action' => 'two_factor_challenge_failed',
            'label' => 'Recent failed 2FA event.',
        ])->forceFill([
            'created_at' => now()->subDays(89),
            'updated_at' => now()->subDays(89),
        ])->save();

        $this->artisan('hotspot:prune-security-activity --days=90')
            ->expectsOutput('Pruned 1 security activity record(s) older than 90 day(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('security_activities', ['label' => 'Expired password event.']);
        $this->assertDatabaseHas('security_activities', ['label' => 'Recent failed 2FA event.']);
    }

    public function test_security_activity_prune_command_rejects_invalid_retention(): void
    {
        $this->artisan('hotspot:prune-security-activity --days=0')
            ->expectsOutput('Retention days must be at least 1.')
            ->assertExitCode(1);
    }

    private function createTenantAdminWithActivity(): User
    {
        $tenant = Tenant::create([
            'company_name' => 'Retention Tenant',
            'owner_email' => 'retention@example.com',
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
    }
}
