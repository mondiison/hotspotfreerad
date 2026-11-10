<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Payment;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PppoeRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRadiusTables();
    }

    public function test_renewal_page_shows_subscriber_and_package_details(): void
    {
        [$subscriber] = $this->fixture();

        $this->get(route('pppoe.renewal.show', $subscriber->public_token))
            ->assertOk()
            ->assertSee($subscriber->username)
            ->assertSee('Home 10M')
            ->assertSee('NGN 12,000.00');
    }

    public function test_renewal_page_404s_for_an_unknown_token(): void
    {
        $this->get(route('pppoe.renewal.show', 'not-a-real-token'))
            ->assertNotFound();
    }

    public function test_paying_redirects_to_the_shops_configured_gateway_checkout(): void
    {
        Http::fake([
            'idp.flutterwave.com/*' => Http::response(['access_token' => 'FLW_V4_TOKEN', 'expires_in' => 600]),
            'developersandbox-api.flutterwave.com/orchestration/direct-charges' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'chg_pppoe_123',
                    'reference' => 'pending',
                    'next_action' => ['type' => 'redirect_url', 'redirect_url' => ['url' => 'https://flutterwave.example/redirect']],
                ],
            ]),
        ]);
        config(['services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token']);
        config(['services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com']);
        config(['services.flutterwave.client_id' => 'client-id']);
        config(['services.flutterwave.client_secret' => 'client-secret']);
        config(['services.flutterwave.default_payment_method' => 'opay']);

        [$subscriber, $shop] = $this->fixture();
        $shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
        ]);

        $this->post(route('pppoe.renewal.pay', $subscriber->public_token))
            ->assertRedirect('https://flutterwave.example/redirect');

        $payment = Payment::firstOrFail();
        $this->assertSame($subscriber->id, $payment->pppoe_subscriber_id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(12000.0, (float) $payment->amount);
    }

    public function test_paying_via_manual_bank_shows_transfer_details_and_does_not_renew_yet(): void
    {
        $originalExpiry = now()->addDay();
        [$subscriber, $shop, $tenant] = $this->fixture(expiresAt: $originalExpiry);
        $shop->update(['payment_gateway' => 'manual_bank']);
        $tenant->update([
            'payment_gateway_settings' => [
                'manual_bank' => [
                    'bank_name' => 'GTBank',
                    'account_name' => 'Demo Shop',
                    'account_number' => '0123456789',
                ],
            ],
        ]);

        $this->post(route('pppoe.renewal.pay', $subscriber->public_token))
            ->assertOk()
            ->assertSee('0123456789')
            ->assertSee('GTBank');

        $payment = Payment::firstOrFail();
        $this->assertSame('pending', $payment->status);

        // Nothing is renewed until the operator confirms the transfer --
        // the expiry stays exactly where it was.
        $subscriber->refresh();
        $this->assertEqualsWithDelta($originalExpiry->timestamp, $subscriber->expires_at->timestamp, 2);
    }

    public function test_a_successful_online_payment_renews_the_subscriber_and_reprovisions_radius(): void
    {
        config(['services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token']);
        config(['services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com']);

        [$subscriber, $shop] = $this->fixture(expiresAt: now()->subDay());
        $shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
        ]);

        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $subscriber->package_id,
            'pppoe_subscriber_id' => $subscriber->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PPPOER-TEST-RENEW',
            'provider_reference' => 'ord_pppoe_renew',
            'amount' => 12000,
            'gross_amount' => 12000,
            'platform_fee_amount' => 0,
            'tenant_net_amount' => 12000,
            'currency' => 'NGN',
            'status' => 'pending',
            'payload' => ['username' => $subscriber->username],
        ]);

        Http::fake([
            'idp.flutterwave.com/*' => Http::response(['access_token' => 'FLW_V4_TOKEN', 'expires_in' => 600]),
            'developersandbox-api.flutterwave.com/orders/ord_pppoe_renew' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_pppoe_renew',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 12000,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $this->post(route('hotspot.payment.verify'), ['tx_ref' => $payment->tx_ref])
            ->assertOk()
            ->assertSee('Your plan has been renewed.');

        $payment->refresh();
        $this->assertSame('successful', $payment->status);

        $subscriber->refresh();
        $this->assertTrue($subscriber->expires_at->isFuture());
        $this->assertTrue($subscriber->is_active);
        $this->assertDatabaseHas('radcheck', [
            'username' => $subscriber->username,
            'attribute' => 'Cleartext-Password',
        ]);

        // The already-verified payment itself IS the transaction record --
        // renewFromOnlinePayment() must not create a second, duplicate one.
        $this->assertSame(1, Payment::where('pppoe_subscriber_id', $subscriber->id)->count());
    }

    private function fixture(?Carbon $expiresAt = null): array
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => fake()->unique()->safeEmail(),
            'brand_color' => '#0f766e',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Home 10M',
            'service_type' => 'pppoe',
            'price' => 12000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);
        $subscriber = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'customer001',
            'password' => 'secret-pass',
            'email' => 'customer@example.com',
            'starts_at' => now()->subMonth(),
            'expires_at' => $expiresAt ?? now()->addDay(),
            'is_active' => true,
        ]);

        return [$subscriber, $shop, $tenant];
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
