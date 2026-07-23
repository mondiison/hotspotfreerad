<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_heartbeat_command_records_last_run_time(): void
    {
        Cache::forget('hotspot.scheduler.last_run_at');

        $this->artisan('hotspot:scheduler-heartbeat')
            ->expectsOutput('Scheduler heartbeat recorded at '.now()->toDateTimeString().'.')
            ->assertExitCode(0);

        $this->assertNotNull(Cache::get('hotspot.scheduler.last_run_at'));
    }

    public function test_dashboard_shows_scheduler_health_when_heartbeat_is_recent(): void
    {
        Cache::forever('hotspot.scheduler.last_run_at', now()->toISOString());
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Scheduler Health')
            ->assertSee('Healthy')
            ->assertSee('Cron active');
    }

    public function test_dashboard_warns_when_scheduler_heartbeat_is_missing(): void
    {
        Cache::forget('hotspot.scheduler.last_run_at');
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Scheduler Health')
            ->assertSee('Not seen yet')
            ->assertSee('Check cron');
    }
}
