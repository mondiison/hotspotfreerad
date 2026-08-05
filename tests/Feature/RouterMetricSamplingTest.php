<?php

namespace Tests\Feature;

use App\Livewire\Admin\RouterInsight;
use App\Models\Router;
use App\Models\RouterMetricSample;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterMetricSamplingService;
use App\Support\RouterMetricHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RouterMetricSamplingTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(string $ip = '192.0.2.20'): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Metrics Router',
            'nas_identifier' => 'metrics-router',
            'wireguard_internal_ip' => $ip,
            'shared_secret' => 'radius-secret',
        ]);
    }

    public function test_sampling_an_unreachable_router_still_records_a_row(): void
    {
        $router = $this->makeRouter();

        $sample = app(RouterMetricSamplingService::class)->sample($router);

        $this->assertDatabaseHas('router_metric_samples', [
            'id' => $sample->id,
            'router_id' => $router->id,
        ]);
        // The reserved documentation-only test IP never responds.
        $this->assertNull($sample->latency_ms);
        $this->assertNull($sample->cpu_percent);
    }

    public function test_ping_latency_returns_a_value_for_localhost(): void
    {
        $latency = app(RouterMetricSamplingService::class)->pingLatencyMs('127.0.0.1');

        $this->assertIsInt($latency);
        $this->assertGreaterThanOrEqual(0, $latency);
    }

    public function test_metric_history_buckets_samples_into_a_series(): void
    {
        $router = $this->makeRouter();

        RouterMetricSample::create([
            'router_id' => $router->id,
            'cpu_percent' => 10,
            'ram_used_bytes' => 50 * 1048576,
            'ram_total_bytes' => 100 * 1048576,
            'latency_ms' => 5,
            'sampled_at' => now()->subHours(2),
        ]);
        RouterMetricSample::create([
            'router_id' => $router->id,
            'cpu_percent' => 30,
            'ram_used_bytes' => 70 * 1048576,
            'ram_total_bytes' => 100 * 1048576,
            'latency_ms' => 15,
            'sampled_at' => now()->subHours(1),
        ]);

        $series = app(RouterMetricHistory::class)->series($router, Carbon::now()->subDay(), Carbon::now());

        $this->assertNotEmpty($series);
        $totalCpu = collect($series)->sum('cpu_percent');
        $this->assertEqualsWithDelta(40, $totalCpu, 0.01);
    }

    public function test_router_insight_component_shows_error_for_unreachable_router(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(RouterInsight::class, ['router' => $router])
            ->assertSet('liveResource', null)
            ->assertSee('Could not reach this router');
    }

    public function test_router_insight_shows_history_once_samples_exist(): void
    {
        $router = $this->makeRouter();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        RouterMetricSample::create([
            'router_id' => $router->id,
            'cpu_percent' => 10,
            'latency_ms' => 5,
            'sampled_at' => now()->subDays(2),
        ]);
        RouterMetricSample::create([
            'router_id' => $router->id,
            'cpu_percent' => 20,
            'latency_ms' => 8,
            'sampled_at' => now()->subHour(),
        ]);

        Livewire::actingAs($user)
            ->test(RouterInsight::class, ['router' => $router])
            ->call('setRange', '7d')
            ->assertSee('Latency to router');
    }
}
