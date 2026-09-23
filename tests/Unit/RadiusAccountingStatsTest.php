<?php

namespace Tests\Unit;

use App\Models\Router;
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

    public function test_a_router_with_no_matching_accounting_rows_reports_no_accounting_yet(): void
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
        $this->assertSame('No accounting yet', $refreshed->detected_status);
    }
}
