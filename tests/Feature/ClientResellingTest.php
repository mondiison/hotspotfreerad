<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\Client;
use App\Models\ClientBillingPayment;
use App\Models\ClientBillingPlan;
use App\Models\ClientBillingSubscription;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-11-12: the reseller-tier model's Phase 1 foundation -- a Client
 * tier parallel to tenant_admin/tenant_staff, the super-admin-only
 * technical-router lockdown, and the trial-to-paywall lapse enforcement.
 * See CLAUDE.md's "Reseller model" entry for the full design.
 */
class ClientResellingTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Technical router detail lockdown -- super_admin only, everywhere
    // -----------------------------------------------------------------

    public function test_tenant_admin_cannot_reach_the_router_detail_page(): void
    {
        [$tenant, $shop] = $this->tenantAndShop();
        $router = $this->router($shop);

        $tenantAdmin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->actingAs($tenantAdmin)
            ->get(route('admin.routers.show', $router))
            ->assertForbidden();
    }

    public function test_tenant_staff_with_routers_permission_cannot_reach_the_router_detail_page(): void
    {
        [$tenant, $shop] = $this->tenantAndShop();
        $router = $this->router($shop);

        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'is_active' => true,
            'permissions' => ['routers'],
        ]);

        $this->actingAs($staff)
            ->get(route('admin.routers.show', $router))
            ->assertForbidden();
    }

    public function test_super_admin_can_reach_the_router_detail_page(): void
    {
        [, $shop] = $this->tenantAndShop();
        $router = $this->router($shop);

        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($superAdmin)
            ->get(route('admin.routers.show', $router))
            ->assertOk();
    }

    public function test_tenant_admin_cannot_reach_any_router_action_route(): void
    {
        [$tenant, $shop] = $this->tenantAndShop();
        $router = $this->router($shop);
        $tenantAdmin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $actionRoutes = [
            'admin.routers.test-api-connection',
            'admin.routers.regenerate-wireguard-key',
            'admin.routers.regenerate-api-credentials',
            'admin.routers.provision-hotspot',
            'admin.routers.provision-pppoe',
            'admin.routers.provision-pos',
            'admin.routers.provision-staff-wifi',
            'admin.routers.provision-lan',
            'admin.routers.push-fresh-infrastructure',
            'admin.routers.sync-trunk-ports',
        ];

        $this->actingAs($tenantAdmin);

        foreach ($actionRoutes as $routeName) {
            $this->post(route($routeName, $router))->assertForbidden();
        }
    }

    public function test_topology_hides_wireguard_ip_and_the_router_link_for_non_super_admins(): void
    {
        [$tenant, $shop] = $this->tenantAndShop();
        $router = $this->router($shop);
        $tenantAdmin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->actingAs($tenantAdmin)
            ->get(route('admin.topology.index'))
            ->assertOk()
            ->assertDontSee($router->wireguard_internal_ip)
            ->assertDontSee(route('admin.routers.show', $router));
    }

    // -----------------------------------------------------------------
    // Client role -- its own authenticated tier, parallel to tenant_admin/
    // tenant_staff, scoped entirely to client.* routes
    // -----------------------------------------------------------------

    public function test_client_user_can_reach_its_own_dashboard_but_not_the_admin_panel(): void
    {
        [$tenant, $shop, $client] = $this->tenantShopAndTrialingClient();
        $clientUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'role' => 'client',
            'is_active' => true,
        ]);

        $this->actingAs($clientUser)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee($shop->name);

        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_tenant_admin_cannot_reach_client_routes(): void
    {
        [$tenant] = $this->tenantShopAndTrialingClient();
        $tenantAdmin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->actingAs($tenantAdmin)
            ->get(route('client.dashboard'))
            ->assertForbidden();
    }

    public function test_client_is_locked_out_once_its_subscription_lapses_but_its_real_customers_are_untouched(): void
    {
        [$tenant, $shop, $client] = $this->tenantShopAndTrialingClient();
        $clientUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'role' => 'client',
            'is_active' => true,
        ]);

        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'service_type' => 'hotspot',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'data_limit_bytes' => 5368709120,
            'is_active' => true,
        ]);
        $customerSubscription = Subscription::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'starts_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $originalExpiry = $customerSubscription->expires_at;

        $client->currentBillingSubscription->forceFill(['status' => 'past_due'])->save();

        $this->actingAs($clientUser)
            ->get(route('client.dashboard'))
            ->assertForbidden();

        // The Client's lockout has no bearing on its real hotspot
        // customers at all -- their RADIUS/Subscription flow never reads
        // client_billing_subscriptions.status.
        $this->assertTrue($customerSubscription->refresh()->expires_at->equalTo($originalExpiry));
    }

    // -----------------------------------------------------------------
    // hotspot:enforce-client-billing -- the lapse-enforcement command
    // -----------------------------------------------------------------

    public function test_a_trialing_subscription_past_its_trial_end_is_flipped_to_past_due_and_voids_only_unused_vouchers(): void
    {
        [, $shop, $client] = $this->tenantShopAndTrialingClient();
        $subscription = $client->currentBillingSubscription;
        $subscription->forceFill(['trial_ends_at' => now()->subDay()])->save();

        [$unused, $sold] = $this->voucherPair($shop);

        Artisan::call('hotspot:enforce-client-billing');

        $this->assertSame('past_due', $subscription->refresh()->status);
        $this->assertSame('void', $unused->refresh()->status);
        $this->assertSame('sold', $sold->refresh()->status);
    }

    public function test_dry_run_makes_no_changes_at_all(): void
    {
        [, $shop, $client] = $this->tenantShopAndTrialingClient();
        $subscription = $client->currentBillingSubscription;
        $subscription->forceFill(['trial_ends_at' => now()->subDay()])->save();
        [$unused] = $this->voucherPair($shop);

        Artisan::call('hotspot:enforce-client-billing', ['--dry-run' => true]);

        $this->assertSame('trialing', $subscription->refresh()->status);
        $this->assertSame('unused', $unused->refresh()->status);
    }

    public function test_an_already_past_due_subscription_is_not_reprocessed(): void
    {
        [, $shop, $client] = $this->tenantShopAndTrialingClient();
        $subscription = $client->currentBillingSubscription;
        $subscription->forceFill(['status' => 'past_due', 'trial_ends_at' => now()->subDay()])->save();

        // A voucher batch created AFTER the lapse should never be touched --
        // the command only re-voids on a lapse it just detected, not on
        // every run for an already-past_due Client.
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $this->package($shop)->id,
            'name' => 'Printed after lockout',
            'quantity' => 1,
            'code_length' => 8,
            'status' => 'active',
        ]);
        $voucher = Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $batch->package_id,
            'code' => 'MMS-AFTER01',
            'status' => 'unused',
        ]);

        Artisan::call('hotspot:enforce-client-billing');

        $this->assertSame('unused', $voucher->refresh()->status);
    }

    public function test_auto_void_every_cycle_fires_once_per_calendar_month_regardless_of_payment_status(): void
    {
        [$tenant, $shop, $client] = $this->tenantShopAndTrialingClient();
        $tenant->update(['auto_void_unused_vouchers_every_cycle' => true]);

        // An ACTIVE, successfully-renewed subscription -- never lapses, its
        // period end is comfortably in the future -- but the tenant wants
        // unused vouchers wiped every cycle regardless of payment status.
        $subscription = $client->currentBillingSubscription;
        $subscription->forceFill([
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_starts_at' => now()->subWeek(),
            'current_period_ends_at' => now()->addMonth(),
        ])->save();

        [$unused] = $this->voucherPair($shop);

        Artisan::call('hotspot:enforce-client-billing');

        $this->assertSame('active', $subscription->refresh()->status);
        $this->assertSame('void', $unused->refresh()->status);

        // A second, brand-new unused voucher printed later the SAME
        // calendar month must not be touched by a second run that month --
        // otherwise this would wipe a Client's vouchers every single day
        // forever, not "every cycle."
        $secondVoucher = Voucher::create([
            'voucher_batch_id' => $unused->voucher_batch_id,
            'shop_id' => $shop->id,
            'package_id' => $unused->package_id,
            'code' => 'MMS-SECOND1',
            'status' => 'unused',
        ]);

        Artisan::call('hotspot:enforce-client-billing');

        $this->assertSame('unused', $secondVoucher->refresh()->status);

        // Once a new calendar month genuinely arrives, the next cycle's
        // unused vouchers are fair game again -- entirely independent of
        // whether the subscription ever lapsed.
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());

        Artisan::call('hotspot:enforce-client-billing');

        $this->assertSame('void', $secondVoucher->refresh()->status);
        $this->assertSame('active', $subscription->refresh()->status);
    }

    // -----------------------------------------------------------------
    // Client-to-Tenant checkout (Paystack)
    // -----------------------------------------------------------------

    public function test_client_can_start_paystack_checkout_and_a_successful_callback_activates_the_subscription(): void
    {
        [$tenant, , $client] = $this->tenantShopAndTrialingClient();
        $tenant->update([
            'client_checkout_gateway' => 'paystack',
            'payment_gateway_settings' => ['paystack' => ['secret_key' => 'sk_test_tenant']],
        ]);
        $clientUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'role' => 'client',
            'is_active' => true,
        ]);

        Http::fake([
            'api.paystack.co/transaction/initialize' => fn ($request) => Http::response([
                'status' => true,
                'data' => [
                    'reference' => $request['reference'],
                    'authorization_url' => 'https://checkout.paystack.com/client-subscription',
                ],
            ]),
        ]);

        $this->actingAs($clientUser)
            ->post(route('client.billing.checkout'))
            ->assertRedirect('https://checkout.paystack.com/client-subscription');

        $payment = ClientBillingPayment::firstOrFail();
        $this->assertSame('paystack', $payment->provider);
        $this->assertSame('pending', $payment->status);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => $payment->tx_ref,
                    'currency' => $payment->currency,
                    'amount' => ((float) $payment->amount) * 100,
                ],
            ]),
        ]);

        $this->actingAs($clientUser)
            ->get(route('client.billing.callback', ['reference' => $payment->tx_ref]))
            ->assertRedirect(route('client.billing.index'));

        $this->assertSame('successful', $payment->refresh()->status);
        $this->assertDatabaseHas('client_billing_subscriptions', [
            'client_id' => $client->id,
            'status' => 'active',
        ]);
    }

    public function test_a_successful_paystack_webhook_activates_the_subscription_too(): void
    {
        [$tenant, , $client] = $this->tenantShopAndTrialingClient();
        $tenant->update(['payment_gateway_settings' => ['paystack' => ['secret_key' => 'sk_test_tenant']]]);
        $plan = $client->currentBillingSubscription->clientBillingPlan;

        $payment = ClientBillingPayment::create([
            'client_id' => $client->id,
            'client_billing_plan_id' => $plan->id,
            'provider' => 'paystack',
            'tx_ref' => 'CBF-PAYSTACK-WEBHOOK-123',
            'provider_reference' => 'CBF-PAYSTACK-WEBHOOK-123',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'status' => 'pending',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 918273646,
                    'status' => 'success',
                    'reference' => $payment->tx_ref,
                    'currency' => $payment->currency,
                    'amount' => ((float) $payment->amount) * 100,
                ],
            ]),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 918273646,
                'reference' => $payment->tx_ref,
                'status' => 'success',
            ],
        ];
        $signature = hash_hmac('sha512', json_encode($payload), 'sk_test_tenant');

        $this->withHeaders(['x-paystack-signature' => $signature])
            ->postJson(route('client.billing.webhook'), $payload)
            ->assertOk()
            ->assertSee('ok');

        $this->assertDatabaseHas('client_billing_payments', [
            'id' => $payment->id,
            'status' => 'successful',
        ]);
        $this->assertDatabaseHas('client_billing_subscriptions', [
            'client_id' => $client->id,
            'status' => 'active',
            'provider' => 'paystack',
        ]);
    }

    // -----------------------------------------------------------------
    // Tenant admin's own onboarding flow, end to end over real HTTP --
    // enable reselling, create a plan, create a Client with a shop
    // assigned, pick a checkout gateway, edit the Client afterward.
    // -----------------------------------------------------------------

    public function test_tenant_admin_cannot_create_a_client_until_reselling_is_enabled(): void
    {
        [$tenant] = $this->tenantAndShop();
        $plan = ClientBillingPlan::create(['tenant_id' => $tenant->id, 'name' => 'Standard', 'price' => 5000, 'currency' => 'NGN', 'is_active' => true]);
        $tenantAdmin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->actingAs($tenantAdmin)
            ->post(route('admin.clients.store'), ['name' => 'Downtown Reseller', 'client_billing_plan_id' => $plan->id])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('clients', ['tenant_id' => $tenant->id]);
    }

    public function test_tenant_admin_can_enable_reselling_create_a_plan_and_onboard_a_client_with_a_shop(): void
    {
        [$tenant, $shop] = $this->tenantAndShop();
        $this->subscribeTenantToAClientResellingCapablePlatformPlan($tenant);
        $tenantAdmin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->actingAs($tenantAdmin)
            ->get(route('admin.clients.index'))
            ->assertOk();

        $this->post(route('admin.clients.reselling.enable'))->assertRedirect(route('admin.clients.index'));
        $this->assertTrue($tenant->refresh()->client_reselling_enabled);

        $this->post(route('admin.clients.checkout-gateway'), ['client_checkout_gateway' => 'paystack'])
            ->assertRedirect(route('admin.clients.index'));
        $this->assertSame('paystack', $tenant->refresh()->client_checkout_gateway);

        $planResponse = $this->post(route('admin.client-billing-plans.store'), [
            'name' => 'Standard',
            'price' => 5000,
            'currency' => 'NGN',
        ]);
        $planResponse->assertRedirect();
        $plan = ClientBillingPlan::where('tenant_id', $tenant->id)->firstOrFail();

        $this->post(route('admin.clients.store'), [
            'name' => 'Downtown Reseller',
            'client_billing_plan_id' => $plan->id,
            'shop_ids' => [$shop->id],
        ])->assertRedirect(route('admin.clients.index'));

        $client = Client::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($client->id, $shop->refresh()->client_id);
        $this->assertSame('trialing', $client->currentBillingSubscription->status);

        $this->get(route('admin.clients.edit', $client))->assertOk()->assertSee('Downtown Reseller');

        $this->put(route('admin.clients.update', $client), [
            'name' => 'Downtown Reseller Ltd',
            'shop_ids' => [],
        ])->assertRedirect(route('admin.clients.index'));

        $this->assertSame('Downtown Reseller Ltd', $client->refresh()->name);
        $this->assertNull($shop->refresh()->client_id);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function tenantAndShop(): array
    {
        $tenant = Tenant::create(['company_name' => 'MMS Tenant', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Park Area']);

        return [$tenant, $shop];
    }

    private function router(Shop $shop): Router
    {
        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'demo-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);
    }

    private function package(Shop $shop): Package
    {
        return Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'service_type' => 'hotspot',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'data_limit_bytes' => 5368709120,
            'is_active' => true,
        ]);
    }

    private function voucherPair(Shop $shop): array
    {
        $package = $this->package($shop);
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'Front Desk Sheet',
            'quantity' => 2,
            'code_length' => 8,
            'status' => 'active',
        ]);
        $unused = Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-UNUSED01',
            'status' => 'unused',
        ]);
        $sold = Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-SOLD0001',
            'status' => 'sold',
            'sold_at' => now(),
        ]);

        return [$unused, $sold];
    }

    private function subscribeTenantToAClientResellingCapablePlatformPlan(Tenant $tenant): TenantBillingSubscription
    {
        $plan = BillingPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
            'supports_client_reselling' => true,
            'default_client_commission_rate' => 10,
            'default_client_trial_days' => 14,
            'is_active' => true,
        ]);

        return TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
    }

    private function tenantShopAndTrialingClient(): array
    {
        [$tenant, $shop] = $this->tenantAndShop();
        $tenant->update(['client_reselling_enabled' => true, 'default_client_trial_days' => 14]);

        $plan = ClientBillingPlan::create([
            'tenant_id' => $tenant->id,
            'name' => 'Standard',
            'price' => 5000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'Downtown Reseller', 'is_active' => true]);
        $shop->update(['client_id' => $client->id]);

        ClientBillingSubscription::create([
            'client_id' => $client->id,
            'client_billing_plan_id' => $plan->id,
            'status' => 'trialing',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'trial_ends_at' => now()->addDays(14),
        ]);

        return [$tenant->refresh(), $shop->refresh(), $client->refresh()];
    }
}
