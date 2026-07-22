<?php

namespace Tests\Feature;

use App\Jobs\VerifyHotspotPaymentWebhook;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
            ->assertSee('NGN 500')
            ->assertSee('View plan')
            ->assertSee('1 hour')
            ->assertSee('5 GB')
            ->assertSee('After 2 GB: 1M/1M')
            ->assertSee('Continue to payment')
            ->assertSee('Opening payment...')
            ->assertSee('Pay with')
            ->assertSee('Card')
            ->assertSee('Transfer')
            ->assertSee('Start test access')
            ->assertSee('x-data="{ selectedPlan: null }"', false)
            ->assertSee('x-cloak', false)
            ->assertSee('sm:grid-cols-2 lg:grid-cols-3', false)
            ->assertSee('grid-cols-3 gap-2', false);
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

    public function test_portal_auto_connects_device_with_active_subscription(): void
    {
        [$router, $package] = $this->routerWithPackage();

        Subscription::create([
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'starts_at' => now()->subMinutes(5),
            'expires_at' => now()->addHour(),
            'is_throttled' => false,
        ]);

        $this->get('/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid='.$router->nas_identifier.'&link-login='.urlencode('http://10.5.50.1/login').'&link-orig='.urlencode('http://example.com'))
            ->assertOk()
            ->assertSee('Access provisioned')
            ->assertSee('Connecting this device...')
            ->assertSee('Connect now')
            ->assertSee('id="mikrotik-login"', false)
            ->assertSee('http://10.5.50.1/login', false)
            ->assertSee('document.getElementById', false)
            ->assertDontSee('Choose internet access');
    }

    public function test_portal_keeps_showing_plans_for_expired_subscription(): void
    {
        [$router, $package] = $this->routerWithPackage();

        Subscription::create([
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'starts_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'is_throttled' => false,
        ]);

        $this->get('/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid='.$router->nas_identifier)
            ->assertOk()
            ->assertSee('Choose internet access')
            ->assertSee($package->name)
            ->assertDontSee('Access provisioned');
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

        $this->assertNull(data_get(Payment::firstOrFail()->payload, 'payment_method'));
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
        config(['services.flutterwave.default_payment_method' => null]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'FLW_V4_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orchestration/direct-charges' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'chg_12345',
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

        $payment = Payment::firstOrFail();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-charges')
            && $request->hasHeader('Authorization', 'Bearer FLW_V4_TOKEN')
            && $request->hasHeader('X-Trace-Id')
            && $request->hasHeader('X-Idempotency-Key')
            && $request['reference']
            && $request['amount'] === 500.0
            && $request['currency'] === 'NGN'
            && data_get($request->data(), 'payment_method.type') === 'opay'
            && data_get($request->data(), 'customer.address.postal_code') === '100001'
            && $request['meta']['credential_source'] === 'tenant'
            && $request['meta']['credential_label'] === 'Demo ISP / Demo Shop'
            && $request['meta']['tenant_name'] === 'Demo ISP'
            && $request['meta']['shop_name'] === 'Demo Shop'
            && $request['meta']['package_name'] === 'One Hour Ultra'
            && $request['meta']['device_mac'] === 'AA:BB:CC:DD:EE:FF'
            && $request['meta']['nas_identifier'] === $router->nas_identifier
            && $request['redirect_url'] === route('hotspot.payment.callback', ['tx_ref' => $payment->tx_ref]));

        $this->assertSame('tenant', data_get($payment->payload, 'flutterwave_account.source'));
    }

    public function test_payment_step_rejects_legacy_bank_transfer_method_value(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
            'payment_method' => 'banktransfer',
        ])
            ->assertSessionHasErrors('payment_method');
    }

    public function test_payment_step_creates_hosted_checkout_session_for_card(): void
    {
        $this->configureFlutterwave();
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'FLW_V4_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/customers' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'cus_card_12345',
                ],
            ], 201),
            'developersandbox-api.flutterwave.com/checkout/sessions' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'cks_12345',
                    'amount' => 500,
                    'currency' => 'NGN',
                    'customer_id' => 'cus_card_12345',
                    'link' => 'https://developersandbox.flutterwave.com/checkout/cks_12345',
                    'redirect_url' => route('hotspot.payment.callback'),
                    'reference' => 'pending',
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
            'phone' => '08000000000',
            'payment_method' => 'card',
        ])
            ->assertRedirect('https://developersandbox.flutterwave.com/checkout/cks_12345');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/customers')
            && data_get($request->data(), 'email') === 'customer@example.com');

        $payment = Payment::firstOrFail();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/checkout/sessions')
            && $request['reference']
            && $request['customer_id'] === 'cus_card_12345'
            && $request['amount'] === 500.0
            && $request['currency'] === 'NGN'
            && $request['redirect_url'] === route('hotspot.payment.callback', ['tx_ref' => $payment->tx_ref]));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-charges'));

        $this->assertSame('card', data_get($payment->payload, 'payment_method'));
        $this->assertSame('cks_12345', $payment->provider_reference);
        $this->assertSame('https://developersandbox.flutterwave.com/checkout/cks_12345', data_get($payment->payload, 'checkout_url'));
    }

    public function test_payment_step_creates_dynamic_virtual_account_for_bank_transfer(): void
    {
        $this->configureFlutterwave();
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'FLW_V4_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/customers' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'cus_12345',
                ],
            ], 201),
            'developersandbox-api.flutterwave.com/virtual-accounts' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'van_12345',
                    'account_number' => '9059273981',
                    'account_bank_name' => 'Flutterwave MFB',
                    'account_type' => 'dynamic',
                    'account_expiration_datetime' => '2026-07-20T18:00:00.000Z',
                    'currency' => 'NGN',
                    'narration' => 'Demo Shop hotspot',
                ],
            ], 201),
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
            'phone' => '08000000000',
            'payment_method' => 'bank_transfer',
        ])
            ->assertOk()
            ->assertSee('Pay by bank transfer')
            ->assertSee('Flutterwave MFB')
            ->assertSee('9059273981')
            ->assertSee('NGN 500.00')
            ->assertSee('I have paid');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/customers')
            && data_get($request->data(), 'email') === 'customer@example.com');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/virtual-accounts')
            && $request['reference']
            && $request['customer_id'] === 'cus_12345'
            && $request['amount'] === 500.0
            && $request['currency'] === 'NGN'
            && $request['account_type'] === 'dynamic');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-charges'));

        $payment = Payment::firstOrFail();
        $this->assertSame('bank_transfer', data_get($payment->payload, 'payment_method'));
        $this->assertSame('van_12345', $payment->provider_reference);
        $this->assertSame('9059273981', data_get($payment->payload, 'virtual_account.account_number'));
    }

    public function test_bank_transfer_check_provisions_radius_access_when_charge_is_successful(): void
    {
        [$router, $package] = $this->routerWithPackage();
        $router->shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
        ]);

        $payment = Payment::create([
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'HSF-20260720180000-TRANSFER',
            'provider_reference' => 'van_12345',
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 500,
            'currency' => 'NGN',
            'status' => 'pending',
            'payload' => [
                'mac' => 'AA:BB:CC:DD:EE:FF',
                'nasid' => $router->nas_identifier,
                'payment_method' => 'bank_transfer',
                'virtual_account' => [
                    'virtual_account_id' => 'van_12345',
                    'account_number' => '9059273981',
                    'bank_name' => 'Flutterwave MFB',
                ],
            ],
        ]);

        $this->configureFlutterwave();
        Http::fake(fn ($request) => match (true) {
            str_contains($request->url(), '/charges/chg_12345') => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'chg_12345',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 500,
                    'currency' => 'NGN',
                ],
            ]),
            str_contains($request->url(), '/charges') => Http::response([
                'status' => 'success',
                'data' => [
                    [
                        'id' => 'chg_12345',
                        'status' => 'succeeded',
                        'reference' => $payment->tx_ref,
                        'amount' => 500,
                        'currency' => 'NGN',
                    ],
                ],
            ]),
            default => Http::response(['access_token' => 'FLW_V4_TOKEN']),
        });

        $this->post(route('hotspot.payment.bank-transfer.check'), [
            'tx_ref' => $payment->tx_ref,
        ])
            ->assertOk()
            ->assertSee('Access provisioned');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'successful',
            'provider_reference' => 'chg_12345',
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
            'developersandbox-api.flutterwave.com/orchestration/direct-charges' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'chg_tenant_123',
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
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orchestration/direct-charges')
            && $request['meta']['credential_source'] === 'tenant'
            && $request['meta']['credential_label'] === 'Demo ISP / Demo Shop');

        $payment = Payment::firstOrFail();
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
            ->assertSee('no complete Flutterwave client ID and client secret');

        Http::assertNothingSent();

        $payment = Payment::firstOrFail();
        $this->assertNull(data_get($payment->payload, 'flutterwave_account.source'));
    }

    public function test_configured_tenant_payment_shows_checkout_failure_reason_when_flutterwave_fails(): void
    {
        $this->configureFlutterwave();
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'TENANT_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orchestration/direct-charges' => Http::response([
                'message' => 'Invalid payment method',
            ], 422),
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
            ->assertOk()
            ->assertSee('Flutterwave checkout could not start even though credentials were found')
            ->assertSee('Demo ISP / Demo Shop');

        $payment = Payment::firstOrFail();
        $this->assertNull(data_get($payment->payload, 'checkout_url'));
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

        $payment = Payment::firstOrFail();
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

    public function test_flutterwave_callback_without_matching_payment_shows_lookup_message(): void
    {
        $this->get(route('hotspot.payment.callback', [
            'status' => 'succeeded',
            'id' => 'ord_missing_12345',
        ]))
            ->assertOk()
            ->assertSee('Payment lookup needed')
            ->assertSee('ord_missing_12345');
    }

    public function test_failed_payment_page_has_verify_and_whatsapp_support_actions(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $payment = Payment::create([
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'HSF-FAILED-123',
            'provider_reference' => 'ord_failed_123',
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 500,
            'currency' => 'NGN',
            'status' => 'verification_failed',
            'payload' => [
                'mac' => 'AA:BB:CC:DD:EE:FF',
                'nasid' => $router->nas_identifier,
            ],
        ]);

        $this->get(route('hotspot.payment.callback', [
            'tx_ref' => $payment->tx_ref,
            'status' => 'failed',
            'id' => 'ord_failed_123',
        ]))
            ->assertOk()
            ->assertSee('Verify and connect')
            ->assertSee('Verifying payment...')
            ->assertSee('x-data="{ verifying: false }"', false)
            ->assertSee('Message support on WhatsApp')
            ->assertSee('2347063218823')
            ->assertSee($payment->tx_ref);
    }

    public function test_manual_payment_verification_provisions_radius_access(): void
    {
        [$router, $package] = $this->routerWithPackage();

        $payment = Payment::create([
            'shop_id' => $router->shop_id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'HSF-MANUAL-VERIFY',
            'provider_reference' => 'ord_manual_123',
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 500,
            'currency' => 'NGN',
            'status' => 'pending',
            'payload' => [
                'mac' => 'AA:BB:CC:DD:EE:FF',
                'nasid' => $router->nas_identifier,
                'link_login' => 'http://10.5.50.1/login',
                'link_orig' => 'http://example.com',
            ],
        ]);
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
            'developersandbox-api.flutterwave.com/orders/ord_manual_123' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_manual_123',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 500,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $this->post(route('hotspot.payment.verify'), [
            'tx_ref' => $payment->tx_ref,
        ])
            ->assertOk()
            ->assertSee('Access provisioned')
            ->assertSee('Connecting this device...')
            ->assertSee('Connect now')
            ->assertSee('id="mikrotik-login"', false)
            ->assertSee('http://10.5.50.1/login', false)
            ->assertSee('document.getElementById', false);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'successful',
            'provider_reference' => 'ord_manual_123',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'payment_id' => $payment->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
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

        $payment = Payment::firstOrFail();
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

    public function test_flutterwave_webhook_dispatches_payment_verification_job(): void
    {
        Queue::fake();
        [$router, $package] = $this->routerWithPackage();

        $this->post(route('hotspot.pay'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'package_id' => $package->id,
            'email' => 'customer@example.com',
        ]);

        $payment = Payment::firstOrFail();
        $router->shop->update(['flutterwave_webhook_secret' => 'webhook-secret']);

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

        Queue::assertPushed(VerifyHotspotPaymentWebhook::class);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
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
