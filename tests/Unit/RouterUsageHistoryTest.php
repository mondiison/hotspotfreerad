<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Support\RouterUsageHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RouterUsageHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_usage_buckets_sessions_by_start_date_and_fills_gaps(): void
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'History Router',
            'nas_identifier' => 'history-router',
            'wireguard_internal_ip' => '10.8.0.60',
            'shared_secret' => 'radius-secret',
        ]);

        DB::table('radacct')->insert([
            'acctsessionid' => 'session-1',
            'acctuniqueid' => 'unique-1',
            'username' => 'AA:BB:CC:DD:EE:01',
            'nasipaddress' => '10.8.0.60',
            'acctstarttime' => now()->subDays(2),
            'acctinputoctets' => 1000,
            'acctoutputoctets' => 2000,
        ]);
        DB::table('radacct')->insert([
            'acctsessionid' => 'session-2',
            'acctuniqueid' => 'unique-2',
            'username' => 'AA:BB:CC:DD:EE:02',
            'nasipaddress' => '10.8.0.60',
            'acctstarttime' => now()->subDays(2),
            'acctinputoctets' => 500,
            'acctoutputoctets' => 1500,
        ]);
        // Different router: must not be counted.
        DB::table('radacct')->insert([
            'acctsessionid' => 'session-3',
            'acctuniqueid' => 'unique-3',
            'username' => 'AA:BB:CC:DD:EE:03',
            'nasipaddress' => '10.8.0.61',
            'acctstarttime' => now()->subDays(2),
            'acctinputoctets' => 99999,
            'acctoutputoctets' => 99999,
        ]);

        $series = app(RouterUsageHistory::class)->daily($router, 7);

        $this->assertCount(7, $series);

        $twoDaysAgo = collect($series)->firstWhere('date', now()->subDays(2)->toDateString());
        $this->assertSame(1500, $twoDaysAgo['upload_bytes']);
        $this->assertSame(3500, $twoDaysAgo['download_bytes']);

        $today = collect($series)->firstWhere('date', now()->toDateString());
        $this->assertSame(0, $today['upload_bytes']);
        $this->assertSame(0, $today['download_bytes']);
    }
}
