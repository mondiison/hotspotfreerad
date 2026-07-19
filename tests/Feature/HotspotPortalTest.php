<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HotspotPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->createRadiusTables();
    }

    public function test_portal_resolves_router_and_lists_active_packages(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
            'brand_color' => '#2563eb',
            'public_site_tagline' => 'Premium guest Wi-Fi.',
            'hero_image_path' => 'tenant-brand/1/hero.jpg',
            'flyer_image_path' => 'tenant-brand/1/flyer.jpg',
            'public_site_slides' => ['tenant-brand/1/slides/offer.jpg'],
        ]);

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);

        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'data_limit_bytes' => 5368709120,
            'speed_limit_profile' => '5M/5M',
            'fup_data_threshold_bytes' => 2147483648,
            'fup_speed_limit_profile' => '1M/1M',
            'is_active' => true,
        ]);

        $this->get('/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid=demo-router')
            ->assertOk()
            ->assertSee('Demo Shop')
            ->assertSee('Premium guest Wi-Fi.')
            ->assertSee('#2563eb')
            ->assertSee('/storage/tenant-brand/1/hero.jpg', false)
            ->assertSee('/storage/tenant-brand/1/flyer.jpg', false)
            ->assertSee('/storage/tenant-brand/1/slides/offer.jpg', false)
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('One Hour Ultra')
            ->assertSee('NGN 500.00')
            ->assertSee('1 hour')
            ->assertSee('5 GB')
            ->assertSee('After 2 GB: 1M/1M')
            ->assertSee('Continue to payment')
            ->assertSee('Start test access');
    }

    public function test_portal_displays_helpful_page_for_unknown_router(): void
    {
        $this->get('/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid=missing-router')
            ->assertOk()
            ->assertSee('Router not registered')
            ->assertSee('missing-router')
            ->assertSee('AA:BB:CC:DD:EE:FF');
    }

    public function test_portal_displays_helpful_page_when_mikrotik_parameters_are_missing(): void
    {
        $this->get('/hotspot/portal')
            ->assertOk()
            ->assertSee('Redirect incomplete')
            ->assertSee('Device MAC')
            ->assertSee('Missing');
    }

    public function test_grant_creates_subscription_and_radius_access(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $this->post('/hotspot/grant', [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'link-login' => 'http://hotspot.local/login',
            'link-orig' => 'http://neverssl.com',
        ])
            ->assertOk()
            ->assertSee('Access provisioned')
            ->assertSee('Demo ISP')
            ->assertSee('#0f766e')
            ->assertSee('/storage/tenant-brand/demo/fallback-flyer.jpg', false)
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('http://hotspot.local/login', false);

        $this->assertDatabaseHas('customers', [
            'shop_id' => $router->shop_id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => 'authenticated_device_pass',
        ]);
        $this->assertDatabaseHas('radusergroup', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'groupname' => "tenant_{$router->shop->tenant_id}_shop_{$router->shop_id}_one_hour_ultra",
        ]);
    }

    public function test_payment_step_creates_pending_payment_and_customer(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
            'phone' => '08000000000',
            'link-login' => 'http://hotspot.local/login',
            'link-orig' => 'http://neverssl.com',
        ])
            ->assertOk()
            ->assertSee('Confirm internet access')
            ->assertSee('Demo ISP')
            ->assertSee('#0f766e')
            ->assertSee('/storage/tenant-brand/demo/fallback-hero.jpg', false)
            ->assertSee('One Hour Ultra')
            ->assertSee('NGN 500.00')
            ->assertSee('Start test access');

        $this->assertDatabaseHas('customers', [
            'shop_id' => $router->shop_id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'email' => 'customer@example.com',
            'phone' => '08000000000',
        ]);
        $this->assertDatabaseHas('payments', [
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 500,
            'billing_model' => 'subscription',
            'currency' => 'NGN',
            'status' => 'pending',
            'provider' => 'flutterwave',
        ]);
    }

    public function test_payment_step_captures_commission_snapshot_for_commission_tenant(): void
    {
        [$router, $package] = $this->routerWithPackage([
            'billing_model' => 'commission',
            'commission_rate' => 10,
        ]);

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
        ])
            ->assertOk();

        $this->assertDatabaseHas('payments', [
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => 50,
            'tenant_net_amount' => 450,
            'commission_rate' => 10,
            'billing_model' => 'commission',
            'status' => 'pending',
        ]);
    }

    public function test_payment_step_redirects_to_flutterwave_when_configured(): void
    {
        $this->configureFlutterwave();
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'FLW_V4_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orchestration/direct-orders' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_12345',
                    'reference' => 'pending',
                    'next_action' => [
                        'type' => 'redirect_url',
                        'redirect_url' => [
                            'url' => 'https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/opay/demo',
                        ],
                    ],
                ],
            ]),
        ]);
        [$router, $package] = $this->routerWithPackage();
        $router->shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
        ]);

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
        ])
            ->assertRedirect('https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/opay/demo');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-orders')
            && $request->hasHeader('Authorization', 'Bearer FLW_V4_TOKEN')
            && $request->hasHeader('X-Trace-Id')
            && $request->hasHeader('X-Idempotency-Key')
            && $request['reference']
            && $request['amount'] === 500.0
            && $request['currency'] === 'NGN'
            && $request['payment_method'] === 'opay'
            && $request['metadata']['credential_source'] === 'tenant'
            && $request['metadata']['credential_label'] === 'Demo ISP / Demo Shop'
            && $request['metadata']['tenant_name'] === 'Demo ISP'
            && $request['metadata']['shop_name'] === 'Demo Shop'
            && $request['metadata']['package_name'] === 'One Hour Ultra'
            && $request['metadata']['device_mac'] === 'AA:BB:CC:DD:EE:FF'
            && $request['metadata']['nas_identifier'] === $router->nas_identifier
            && $request['redirect_url'] === route('hotspot.payment.callback'));

        $payment = \App\Models\Payment::firstOrFail();
        $this->assertSame('tenant', data_get($payment->payload, 'flutterwave_account.source'));
    }

    public function test_tenant_flutterwave_credentials_are_used_when_complete(): void
    {
        config([
            'services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
            'services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com',
            'services.flutterwave.client_id' => 'platform-client-id',
            'services.flutterwave.client_secret' => 'platform-client-secret',
            'services.flutterwave.default_payment_method' => 'opay',
        ]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'TENANT_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orchestration/direct-orders' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_tenant_123',
                    'next_action' => [
                        'redirect_url' => [
                            'url' => 'https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/opay/tenant',
                        ],
                    ],
                ],
            ]),
        ]);
        [$router, $package] = $this->routerWithPackage();
        $router->shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
        ]);

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
        ])
            ->assertRedirect('https://developer-sandbox-ui-sit.flutterwave.cloud/redirects/opay/tenant');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'idp.flutterwave.com')
            && $request['client_id'] === 'tenant-client-id'
            && $request['client_secret'] === 'tenant-client-secret');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-orders')
            && $request['metadata']['credential_source'] === 'tenant'
            && $request['metadata']['credential_label'] === 'Demo ISP / Demo Shop');

        $payment = \App\Models\Payment::firstOrFail();
        $this->assertSame('tenant', data_get($payment->payload, 'flutterwave_account.source'));
    }

    public function test_incomplete_tenant_flutterwave_credentials_do_not_use_platform_account_for_customer_payments(): void
    {
        $this->configureFlutterwave();
        Http::fake();
        [$router, $package] = $this->routerWithPackage();
        $router->shop->update([
            'flutterwave_client_id' => 'tenant-client-id-only',
        ]);

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
        ])
            ->assertOk()
            ->assertSee('online payment is not available');

        Http::assertNothingSent();

        $payment = \App\Models\Payment::firstOrFail();
        $this->assertNull(data_get($payment->payload, 'flutterwave_account.source'));
    }

    public function test_successful_flutterwave_callback_provisions_radius_access(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
        ]);

        $payment = \App\Models\Payment::firstOrFail();
        $router->shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
        ]);
        $this->configureFlutterwave();
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'FLW_V4_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orders/ord_12345' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_12345',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 500,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $this->get(route('hotspot.payment.callback', [
            'status' => 'succeeded',
            'reference' => $payment->tx_ref,
            'id' => 'ord_12345',
        ]))
            ->assertOk()
            ->assertSee('Access provisioned');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'successful',
            'provider_reference' => 'ord_12345',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'payment_id' => $payment->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'attribute' => 'Cleartext-Password',
        ]);
    }

    public function test_successful_flutterwave_webhook_provisions_radius_access(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
        ]);

        $payment = \App\Models\Payment::firstOrFail();
        $router->shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
            'flutterwave_webhook_secret' => 'webhook-secret',
        ]);
        $this->configureFlutterwave();
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'FLW_V4_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orders/ord_12345' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_12345',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 500,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $this->postJson(route('hotspot.payment.webhook'), [
            'data' => [
                'id' => 'ord_12345',
                'reference' => $payment->tx_ref,
                'status' => 'succeeded',
            ],
        ], [
            'verif-hash' => 'webhook-secret',
        ])
            ->assertOk()
            ->assertSee('ok');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'successful',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'payment_id' => $payment->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    private function routerWithPackage(array $tenantOverrides = []): array
    {
        $tenant = Tenant::create(array_merge([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
            'brand_color' => '#0f766e',
            'hero_image_path' => 'tenant-brand/demo/fallback-hero.jpg',
            'flyer_image_path' => 'tenant-brand/demo/fallback-flyer.jpg',
        ], $tenantOverrides));

        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);

        return [$router, $package];
    }

    private function configureFlutterwave(): void
    {
        config([
            'services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
            'services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com',
            'services.flutterwave.client_id' => 'client-id',
            'services.flutterwave.client_secret' => 'client-secret',
            'services.flutterwave.default_payment_method' => 'opay',
        ]);
    }

    private function createRadiusTables(): void
    {
        if (Schema::hasTable('radcheck')) {
            return;
        }

        Schema::create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });

        Schema::create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });

        Schema::create('radusergroup', function (Blueprint $table) {
            $table->string('username');
            $table->string('groupname');
            $table->integer('priority')->default(1);
        });

        Schema::create('radgroupreply', function (Blueprint $table) {
            $table->id();
            $table->string('groupname');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });
    }
}
