<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\RouterMetricSample;
use App\Models\Shop;
use App\Models\Tenant;
use App\Support\RadiusAccountingStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for a live 2026-09-23 report: a ZeroTier-only
 * router's radacct rows are keyed to its zerotier_ip, not
 * wireguard_internal_ip -- RadiusAccountingStats used to only ever match
 * against wireguard_internal_ip, so such a router showed "No accounting
 * yet"/"Last seen never" permanently regardless of real traffic.
 */
class RadiusAccountingStatsTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);

        return Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
    }

    private function insertSession(string $nasIp, string $acctUniqueId, ?string $stopTime = null): void
    {
        DB::table('radacct')->insert([
            'acctsessionid' => 'session-'.$acctUniqueId,
            'acctuniqueid' => $acctUniqueId,
            'username' => 'AA:BB:CC:DD:EE:FF',
            'nasipaddress' => $nasIp,
            'acctstarttime' => now()->subMinutes(2),
            'acctupdatetime' => now()->subMinute(),
            'acctstoptime' => $stopTime,
        ]);
    }

    public function test_it_detects_recent_activity_over_the_wireguard_ip(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'WireGuard Router',
            'nas_identifier' => 'wg-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
        ]);

        $this->insertSession('10.8.0.20', 'wg-session-1');

        $refreshed = app(RadiusAccountingStats::class)->refreshRouterHealth(
            Router::query()->whereKey($router->id)->get()
        )->first();

        $this->assertTrue($refreshed->is_online);
        $this->assertNotNull($refreshed->last_seen_at);
    }

    public function test_it_detects_recent_activity_over_the_zerotier_ip_for_a_zerotier_only_router(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'ZeroTier Router',
            'nas_identifier' => 'zt-router',
            'wireguard_internal_ip' => '10.8.0.21',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'zerotier',
            'zerotier_ip' => '10.9.0.21',
        ]);

        // Traffic only ever arrives from the ZeroTier IP -- wireguard_internal_ip
        // is a stored-but-unused value for a zerotier-only router.
        $this->insertSession('10.9.0.21', 'zt-session-1');

        $refreshed = app(RadiusAccountingStats::class)->refreshRouterHealth(
            Router::query()->whereKey($router->id)->get()
        )->first();

        $this->assertTrue($refreshed->is_online);
        $this->assertNotNull($refreshed->last_seen_at);
    }

    public function test_summary_and_online_sessions_also_match_the_zerotier_ip(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'ZeroTier Router',
            'nas_identifier' => 'zt-router-2',
            'wireguard_internal_ip' => '10.8.0.22',
            'shared_secret' => 'radius-secret',
            'tunnel_mode' => 'zerotier',
            'zerotier_ip' => '10.9.0.22',
        ]);

        $this->insertSession('10.9.0.22', 'zt-session-2');

        $stats = app(RadiusAccountingStats::class);
        $routers = Router::query()->whereKey($router->id)->get();

        $summary = $stats->summary($routers);
        $this->assertSame(1, $summary['active_session_count']);

        $sessions = $stats->onlineSessions($routers);
        $this->assertCount(1, $sessions);
        $this->assertSame('ZeroTier Router', $sessions->first()->router_name);
    }

    public function test_a_router_with_no_matching_accounting_rows_reports_no_data_yet(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Idle Router',
            'nas_identifier' => 'idle-router',
            'wireguard_internal_ip' => '10.8.0.23',
            'shared_secret' => 'radius-secret',
        ]);

        $refreshed = app(RadiusAccountingStats::class)->refreshRouterHealth(
            Router::query()->whereKey($router->id)->get()
        )->first();

        $this->assertFalse($refreshed->is_online);
        $this->assertSame('No data yet', $refreshed->detected_status);
    }

    /**
     * Regression coverage for a live 2026-09-27 report: a router with zero
     * customers currently connected (no active accounting session at all)
     * is not the same thing as a router that's actually down, but the old
     * "online = has an active accounting session" check couldn't tell them
     * apart. A fresh, reachable heartbeat sample (hotspot:sample-router-metrics)
     * now counts as online on its own, independent of accounting activity.
     */
    public function test_a_fresh_reachable_heartbeat_counts_as_online_with_no_accounting_activity(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Heartbeat Only Router',
            'nas_identifier' => 'heartbeat-router',
            'wireguard_internal_ip' => '10.8.0.24',
            'shared_secret' => 'radius-secret',
        ]);

        RouterMetricSample::create([
            'router_id' => $router->id,
            'latency_ms' => 12,
            'sampled_at' => now()->subMinutes(2),
        ]);

        $refreshed = app(RadiusAccountingStats::class)->refreshRouterHealth(
            Router::query()->whereKey($router->id)->get()
        )->first();

        $this->assertTrue($refreshed->is_online);
        $this->assertSame('Online', $refreshed->detected_status);
        $this->assertNotNull($refreshed->last_seen_at);
    }

    /**
     * A heartbeat sample that exists but is stale (older than the 15-minute
     * freshness window) or came back unreachable (null latency) must not
     * count as currently online -- only a recent, successful ping should.
     */
    public function test_a_stale_or_unreachable_heartbeat_does_not_count_as_online(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Stale Heartbeat Router',
            'nas_identifier' => 'stale-heartbeat-router',
            'wireguard_internal_ip' => '10.8.0.25',
            'shared_secret' => 'radius-secret',
        ]);

        RouterMetricSample::create([
            'router_id' => $router->id,
            'latency_ms' => 9,
            'sampled_at' => now()->subMinutes(30),
        ]);

        $refreshed = app(RadiusAccountingStats::class)->refreshRouterHealth(
            Router::query()->whereKey($router->id)->get()
        )->first();

        $this->assertFalse($refreshed->is_online);
        $this->assertNotSame('Online', $refreshed->detected_status);
    }
}
