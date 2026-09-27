<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterAvailability;
use App\Models\Router;
use App\Models\RouterMetricSample;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RouterAvailabilityHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RouterAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Availability Router',
            'nas_identifier' => 'availability-router',
            'wireguard_internal_ip' => '192.0.2.40',
            'shared_secret' => 'radius-secret',
        ]);
    }

    private function sampleAt(Router $router, Carbon $sampledAt, ?int $latencyMs): void
    {
        RouterMetricSample::create([
            'router_id' => $router->id,
            'latency_ms' => $latencyMs,
            'sampled_at' => $sampledAt,
        ]);
    }

    public function test_summary_reports_no_samples_when_range_is_empty(): void
    {
        $router = $this->makeRouter();

        $summary = app(RouterAvailabilityHistory::class)->summary($router, now()->subDay(), now());

        $this->assertFalse($summary['has_samples']);
        $this->assertNull($summary['uptime_percent']);
        $this->assertSame('no_data', $summary['segments'][0]['status']);
    }

    public function test_summary_computes_100_percent_uptime_when_every_sample_is_reachable(): void
    {
        $router = $this->makeRouter();
        $start = now()->subHour();

        for ($minute = 0; $minute <= 60; $minute += 5) {
            $this->sampleAt($router, $start->copy()->addMinutes($minute), 10);
        }

        $summary = app(RouterAvailabilityHistory::class)->summary($router, $start, now());

        $this->assertTrue($summary['has_samples']);
        $this->assertSame(100.0, $summary['uptime_percent']);
        $this->assertCount(0, $summary['downtime_incidents']);
    }

    /**
     * Regression coverage for the core "Meraki-style" requirement: an outage
     * in the middle of an otherwise-healthy range must show up as its own
     * discrete downtime segment with the exact start/end RouterMetricSamplingService
     * actually recorded, sandwiched between two "up" segments -- not averaged
     * away or merged into a single number.
     */
    public function test_summary_detects_a_downtime_window_between_two_up_periods(): void
    {
        $router = $this->makeRouter();
        $start = now()->subHours(2)->startOfSecond();

        $this->sampleAt($router, $start->copy(), 10);
        $this->sampleAt($router, $start->copy()->addMinutes(5), 10);
        $this->sampleAt($router, $start->copy()->addMinutes(10), null);
        $this->sampleAt($router, $start->copy()->addMinutes(15), null);
        $this->sampleAt($router, $start->copy()->addMinutes(20), null);
        $this->sampleAt($router, $start->copy()->addMinutes(25), 12);
        $this->sampleAt($router, $start->copy()->addMinutes(30), 12);

        $summary = app(RouterAvailabilityHistory::class)->summary(
            $router,
            $start,
            $start->copy()->addMinutes(30)
        );

        $this->assertCount(1, $summary['downtime_incidents']);

        $incident = $summary['downtime_incidents'][0];
        $this->assertTrue($incident['start']->equalTo($start->copy()->addMinutes(10)));
        $this->assertTrue($incident['end']->equalTo($start->copy()->addMinutes(25)));
        $this->assertSame(15 * 60, $incident['duration_seconds']);

        $statuses = array_column($summary['segments'], 'status');
        $this->assertSame(['up', 'down', 'up'], $statuses);
    }

    /**
     * A gap between samples much larger than the 5-minute sampling cadence
     * means the scheduler wasn't running (or the router didn't exist yet),
     * not that every ping genuinely failed across that whole span -- it must
     * be reported as "no_data", separate from a real, pinged-and-failed
     * "down" run.
     */
    public function test_a_large_gap_between_samples_is_reported_as_no_data_not_down(): void
    {
        $router = $this->makeRouter();
        $start = now()->subHours(3);

        $this->sampleAt($router, $start->copy(), 10);
        $this->sampleAt($router, $start->copy()->addHours(2), 10);

        $summary = app(RouterAvailabilityHistory::class)->summary(
            $router,
            $start,
            $start->copy()->addHours(2)
        );

        $statuses = array_column($summary['segments'], 'status');
        $this->assertContains('no_data', $statuses);
        $this->assertCount(0, $summary['downtime_incidents']);
    }

    public function test_router_availability_component_renders_uptime_summary(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $start = now()->subHour();
        $this->sampleAt($router, $start->copy(), 10);
        $this->sampleAt($router, $start->copy()->addMinutes(30), null);
        $this->sampleAt($router, $start->copy()->addMinutes(59), 10);

        Livewire::actingAs($user)
            ->test(RouterAvailability::class, ['router' => $router])
            ->call('setRange', '7d')
            ->assertSee('Uptime')
            ->assertSee('Downtime incidents');
    }
}
