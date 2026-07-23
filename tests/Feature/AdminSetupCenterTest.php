<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminSetupCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_sees_setup_center_with_pppoe_guidance(): void
    {
        Cache::forever('hotspot.scheduler.last_run_at', now()->toISOString());

        $tenant = Tenant::create([
            'company_name' => 'MMS Tenant',
            'owner_email' => 'owner@example.com',
            'brand_color' => '#2563eb',
            'public_site_tagline' => 'Fast estate internet',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Park Area',
            'flutterwave_client_id' => 'client-id',
            'flutterwave_client_secret' => 'client-secret',
            'flutterwave_secret_key' => 'FLWSECK_TEST-secret',
            'flutterwave_webhook_secret' => 'hash-secret',
        ]);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main MikroTik',
            'nas_identifier' => 'park-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);
        Package::create([
            'shop_id' => $shop->id,
            'name' => 'Monthly Home 10M',
            'price' => 12000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.setup.index'))
            ->assertOk()
            ->assertSee('Setup Center')
            ->assertSee('MikroTik PPPoE')
            ->assertSee('MikroTik CPE / Client Router')
            ->assertSee('Other Router / ONT')
            ->assertSee('PPPoE Wizard')
            ->assertSee('Manage PPPoE customers')
            ->assertSee(route('admin.pppoe-subscribers.index'), false)
            ->assertSee('OPay and bank transfer')
            ->assertSee('Card checkout')
            ->assertSee('Webhook')
            ->assertSee('Scheduler Health')
            ->assertSee('Healthy')
            ->assertSee('Cron active')
            ->assertSee('Park Area')
            ->assertSee(route('admin.routers.show', $router), false);
    }

    public function test_setup_center_guides_empty_tenant_to_first_shop(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'New Tenant',
            'owner_email' => 'new@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.setup.index'))
            ->assertOk()
            ->assertSee('Add first shop')
            ->assertSee('Enable Laravel scheduler')
            ->assertSee('No heartbeat yet')
            ->assertSee('Create a shop first, then continue with routers, packages, and payments.')
            ->assertSee('0 OPay/transfer ready, 0 card ready, 0 webhook ready.');
    }
}
