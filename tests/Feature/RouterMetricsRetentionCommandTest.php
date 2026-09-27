<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterMetricSample;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterMetricsRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(): Router
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Retention Router',
            'nas_identifier' => 'retention-router',
            'wireguard_internal_ip' => '192.0.2.50',
            'shared_secret' => 'radius-secret',
        ]);
    }

    public function test_router_metrics_prune_command_reports_dry_run_without_deleting(): void
    {
        $router = $this->makeRouter();

        RouterMetricSample::create(['router_id' => $router->id, 'latency_ms' => 5, 'sampled_at' => now()->subDays(91)]);
        RouterMetricSample::create(['router_id' => $router->id, 'latency_ms' => 5, 'sampled_at' => now()->subDays(10)]);

        $this->artisan('hotspot:prune-router-metrics --days=90 --dry-run')
            ->expectsOutput('1 router metric sample(s) older than 90 day(s) would be pruned.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('router_metric_samples', 2);
    }

    public function test_router_metrics_prune_command_deletes_only_expired_samples(): void
    {
        $router = $this->makeRouter();

        RouterMetricSample::create(['router_id' => $router->id, 'latency_ms' => 5, 'sampled_at' => now()->subDays(91)]);
        RouterMetricSample::create(['router_id' => $router->id, 'latency_ms' => 5, 'sampled_at' => now()->subDays(89)]);

        $this->artisan('hotspot:prune-router-metrics --days=90')
            ->expectsOutput('Pruned 1 router metric sample(s) older than 90 day(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('router_metric_samples', 1);
        $this->assertDatabaseHas('router_metric_samples', ['router_id' => $router->id]);
    }

    public function test_router_metrics_prune_command_rejects_invalid_retention(): void
    {
        $this->artisan('hotspot:prune-router-metrics --days=0')
            ->expectsOutput('Retention days must be at least 1.')
            ->assertExitCode(1);
    }
}
