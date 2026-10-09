<?php

namespace Tests\Feature;

use App\Livewire\Admin\PaymentsIndex;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Services\RadiusProvisioningService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPaymentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_payment_report_across_tenants(): void
    {
        [$ownPayment] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Own Shop', 'OWN-REF', 'successful');
        [$otherPayment] = $this->paymentFixture('Other Tenant', 'other@example.com', 'Other Shop', 'OTHER-REF', 'pending');
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($ownPayment->tx_ref)
            ->assertSee($otherPayment->tx_ref)
            ->assertSee('NGN 500.00')
            ->assertSee('Transactions')
            ->assertSee('Gross Sales');
    }

    public function test_tenant_admin_only_sees_own_payments(): void
    {
        [$ownPayment, $ownTenant] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Own Shop', 'OWN-REF', 'successful');
        [$otherPayment] = $this->paymentFixture('Other Tenant', 'other@example.com', 'Other Shop', 'OTHER-REF', 'successful');
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($ownPayment->tx_ref)
            ->assertDontSee($otherPayment->tx_ref)
            ->assertSee('Own Shop')
            ->assertDontSee('Other Shop');
    }

    public function test_payment_report_can_filter_by_status_and_search(): void
    {
        [$successfulPayment] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Main Hall', 'SUCCESS-REF', 'successful');
        [$pendingPayment, $tenant] = $this->paymentFixture('Own Tenant Two', 'two@example.com', 'Annex', 'PENDING-REF', 'pending');
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index', [
                'status' => 'pending',
                'search' => 'Annex',
            ]))
            ->assertOk()
            ->assertSee($pendingPayment->tx_ref)
            ->assertDontSee($successfulPayment->tx_ref);
    }

    public function test_payment_report_shows_commission_totals(): void
    {
        [$payment] = $this->paymentFixture('Commission Tenant', 'commission@example.com', 'Commission Shop', 'COMMISSION-REF', 'successful', [
            'billing_model' => 'commission',
            'commission_rate' => 20,
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($payment->tx_ref)
            ->assertSee('Platform Commission')
            ->assertSee('Tenant Net')
            ->assertSee('NGN 100.00')
            ->assertSee('NGN 400.00')
            ->assertSee('20.00%');
    }

    public function test_payment_report_can_filter_by_date_presets_and_show_attention_totals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));

        try {
            [$recentPending] = $this->paymentFixture('Preset Tenant', 'preset@example.com', 'Recent Shop', 'RECENT-PENDING', 'pending');
            [$recentFailed] = $this->paymentFixture('Failed Preset Tenant', 'failed-preset@example.com', 'Failed Shop', 'RECENT-FAILED', 'failed');
            [$oldPayment] = $this->paymentFixture('Old Preset Tenant', 'old-preset@example.com', 'Old Shop', 'OLD-PENDING', 'pending');
            $oldPayment->update([
                'created_at' => '2026-07-01 10:00:00',
                'updated_at' => '2026-07-01 10:00:00',
            ]);

            $user = User::factory()->create([
                'role' => 'super_admin',
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get(route('admin.payments.index', [
                    'preset' => 'last_7_days',
                ]))
                ->assertOk()
                ->assertSee('7 days')
                ->assertSee('2026-07-13')
                ->assertSee('2026-07-19')
                ->assertSee($recentPending->tx_ref)
                ->assertSee($recentFailed->tx_ref)
                ->assertSee('Failed')
                ->assertSee('NGN 500.00 awaiting confirmation')
                ->assertSee('NGN 500.00 not confirmed')
                ->assertDontSee($oldPayment->tx_ref)
                ->assertDontSee('Old Shop');

            $this->actingAs($user)
                ->get(route('admin.payments.index', [
                    'from' => '2026-07-01',
                    'to' => '2026-07-02',
                ]))
                ->assertOk()
                ->assertSee($oldPayment->tx_ref)
                ->assertDontSee($recentPending->tx_ref);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_payment_report_attention_filter_groups_unresolved_payments(): void
    {
        [$pendingPayment] = $this->paymentFixture('Attention Tenant', 'attention@example.com', 'Pending Shop', 'ATTENTION-PENDING', 'pending');
        [$failedPayment] = $this->paymentFixture('Attention Failed Tenant', 'attention-failed@example.com', 'Failed Shop', 'ATTENTION-FAILED', 'failed');
        [$verificationPayment] = $this->paymentFixture('Attention Verification Tenant', 'attention-verification@example.com', 'Verification Shop', 'ATTENTION-VERIFY', 'verification_failed');
        [$successfulPayment] = $this->paymentFixture('Attention Success Tenant', 'attention-success@example.com', 'Success Shop', 'ATTENTION-SUCCESS', 'successful');
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payments.index', [
                'status' => 'attention',
            ]))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee($pendingPayment->tx_ref)
            ->assertSee($failedPayment->tx_ref)
            ->assertSee($verificationPayment->tx_ref)
            ->assertDontSee($successfulPayment->tx_ref);
    }

    public function test_tenant_admin_can_export_filtered_payment_report(): void
    {
        [$ownPayment, $ownTenant] = $this->paymentFixture('Own Export Tenant', 'own-export@example.com', 'Own Export Shop', 'EXPORT-OWN', 'successful', [
            'billing_model' => 'commission',
            'commission_rate' => 20,
        ]);
        [$otherPayment] = $this->paymentFixture('Other Export Tenant', 'other-export@example.com', 'Other Export Shop', 'EXPORT-OTHER', 'successful');
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.payments.export', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfDay()->toDateString(),
                'status' => 'successful',
                'search' => 'Own Export',
            ]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Payment Report', $content);
        $this->assertStringContainsString('Status,Successful', $content);
        $this->assertStringContainsString('Search,"Own Export"', $content);
        $this->assertStringContainsString('"Transaction Ref","Provider Ref",Provider,Status', $content);
        $this->assertStringContainsString($ownPayment->tx_ref, $content);
        $this->assertStringContainsString('"Own Export Shop"', $content);
        $this->assertStringContainsString('"Own Export Tenant"', $content);
        $this->assertStringContainsString('500.00,100.00,400.00,20.00,commission,Yes', $content);
        $this->assertStringNotContainsString($otherPayment->tx_ref, $content);
        $this->assertStringNotContainsString('Other Export Shop', $content);
    }

    public function test_livewire_payment_report_filters_without_page_reload(): void
    {
        [$successfulPayment] = $this->paymentFixture('Own Tenant', 'own@example.com', 'Main Hall', 'SUCCESS-LIVE', 'successful');
        [$pendingPayment] = $this->paymentFixture('Own Tenant Two', 'two@example.com', 'Annex', 'PENDING-LIVE', 'pending');
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->set('status', 'pending')
            ->set('search', 'Annex')
            ->assertSee($pendingPayment->tx_ref)
            ->assertDontSee($successfulPayment->tx_ref)
            ->assertSee('NGN 500.00 awaiting confirmation')
            ->call('clearFilters')
            ->assertSet('status', '')
            ->assertSet('search', '')
            ->assertSee($successfulPayment->tx_ref);
    }

    public function test_livewire_payment_report_presets_and_export_link(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));

        try {
            [$recentPayment] = $this->paymentFixture('Preset Tenant', 'preset@example.com', 'Recent Shop', 'RECENT-LIVE', 'successful');
            [$oldPayment] = $this->paymentFixture('Old Preset Tenant', 'old-preset@example.com', 'Old Shop', 'OLD-LIVE', 'successful');
            $oldPayment->update([
                'created_at' => '2026-07-01 10:00:00',
                'updated_at' => '2026-07-01 10:00:00',
            ]);
            $user = User::factory()->create([
                'role' => 'super_admin',
                'is_active' => true,
            ]);

            Livewire::actingAs($user)
                ->test(PaymentsIndex::class)
                ->call('setPreset', 'last_7_days')
                ->assertSet('preset', 'last_7_days')
                ->assertSet('from', '2026-07-13')
                ->assertSet('to', '2026-07-19')
                ->assertSee($recentPayment->tx_ref)
                ->assertDontSee($oldPayment->tx_ref)
                ->assertSee(route('admin.payments.export', ['preset' => 'last_7_days']), false)
                ->call('useCustomRange')
                ->set('from', '2026-07-01')
                ->set('to', '2026-07-02')
                ->assertSet('preset', '')
                ->assertSee($oldPayment->tx_ref)
                ->assertDontSee($recentPayment->tx_ref);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_livewire_payment_report_respects_tenant_scope(): void
    {
        [$ownPayment, $ownTenant] = $this->paymentFixture('Own Live Tenant', 'own-live@example.com', 'Own Live Shop', 'OWN-LIVE', 'successful');
        [$otherPayment] = $this->paymentFixture('Other Live Tenant', 'other-live@example.com', 'Other Live Shop', 'OTHER-LIVE', 'successful');
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->assertSee($ownPayment->tx_ref)
            ->assertDontSee($otherPayment->tx_ref)
            ->assertSee('Own Live Shop')
            ->assertDontSee('Other Live Shop');
    }

    public function test_tenant_admin_can_confirm_manual_bank_transfer_and_provision_access(): void
    {
        $this->createRadiusTables();
        [$payment, $tenant] = $this->paymentFixture('Manual Tenant', 'manual@example.com', 'Manual Shop', 'MANUAL-PENDING', 'pending');
        $payment->update([
            'provider' => 'manual_bank',
            'provider_reference' => $payment->tx_ref,
            'payload' => array_merge($payment->payload ?? [], [
                'manual_bank_transfer' => [
                    'bank_name' => 'MMS Microfinance Bank',
                    'account_name' => 'Manual Shop',
                    'account_number' => '1234567890',
                ],
            ]),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->assertSee('MANUAL-PENDING')
            ->assertSee('Confirm')
            ->call('confirmManualTransfer', $payment->id)
            ->assertDispatched('notify');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'successful',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'payment_id' => $payment->id,
            'mac_address' => data_get($payment->payload, 'mac'),
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => data_get($payment->payload, 'mac'),
            'attribute' => 'Cleartext-Password',
        ]);
    }

    public function test_tenant_admin_cannot_confirm_other_tenant_manual_transfer(): void
    {
        [$payment] = $this->paymentFixture('Other Manual Tenant', 'other-manual@example.com', 'Other Manual Shop', 'OTHER-MANUAL', 'pending');
        $payment->update([
            'provider' => 'manual_bank',
            'provider_reference' => $payment->tx_ref,
        ]);
        $actorTenant = Tenant::create([
            'company_name' => 'Actor Tenant',
            'owner_email' => 'actor@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $actorTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        try {
            Livewire::actingAs($user)
                ->test(PaymentsIndex::class)
                ->call('confirmManualTransfer', $payment->id);

            $this->fail('Expected tenant-scoped manual transfer confirmation to hide other tenant payment.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    /**
     * 2026-09-25, direct request after a live report of a stuck customer
     * payment (a malformed Monnify redirect URL, separately fixed --
     * PortalController's callback lookup failed, but the underlying Payment
     * row + provider_reference were saved correctly at checkout time). Adds
     * an admin-facing "Verify" action mirroring BillingController::verify()'s
     * already-proven platform-billing pattern, reusing the same
     * verifyAndGrant() every other confirmation path already goes through.
     */
    public function test_tenant_admin_can_manually_verify_a_pending_online_payment(): void
    {
        $this->createRadiusTables();
        [$payment, $tenant] = $this->paymentFixture('Verify Tenant', 'verify@example.com', 'Verify Shop', 'HSF-VERIFY-PENDING', 'pending');
        $payment->update(['provider_reference' => 'ord_verify_123']);
        $payment->shop->update([
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
        ]);
        config([
            'services.flutterwave.auth_url' => 'https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token',
            'services.flutterwave.base_url' => 'https://developersandbox-api.flutterwave.com',
        ]);
        Http::fake([
            'idp.flutterwave.com/*' => Http::response([
                'access_token' => 'FLW_V4_TOKEN',
                'expires_in' => 600,
            ]),
            'developersandbox-api.flutterwave.com/orders/ord_verify_123' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 'ord_verify_123',
                    'status' => 'succeeded',
                    'reference' => $payment->tx_ref,
                    'amount' => 500,
                    'currency' => 'NGN',
                ],
            ]),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->assertSee('HSF-VERIFY-PENDING')
            ->assertSee('Verify')
            ->call('verifyPayment', $payment->id)
            ->assertDispatched('notify');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'successful',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'payment_id' => $payment->id,
            'mac_address' => data_get($payment->payload, 'mac'),
        ]);
    }

    public function test_manual_verify_does_nothing_for_manual_bank_transfers(): void
    {
        [$payment, $tenant] = $this->paymentFixture('Manual Guard Tenant', 'manual-guard@example.com', 'Manual Guard Shop', 'HSF-MANUAL-GUARD', 'pending');
        $payment->update([
            'provider' => 'manual_bank',
            'provider_reference' => $payment->tx_ref,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Http::fake();

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->call('verifyPayment', $payment->id)
            ->assertDispatched('notify');

        Http::assertNothingSent();
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    public function test_tenant_admin_cannot_verify_another_tenants_payment(): void
    {
        [$payment] = $this->paymentFixture('Other Verify Tenant', 'other-verify@example.com', 'Other Verify Shop', 'HSF-OTHER-VERIFY', 'pending');
        $payment->update(['provider_reference' => 'ord_other_verify']);
        $actorTenant = Tenant::create([
            'company_name' => 'Verify Actor Tenant',
            'owner_email' => 'verify-actor@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $actorTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        try {
            Livewire::actingAs($user)
                ->test(PaymentsIndex::class)
                ->call('verifyPayment', $payment->id);

            $this->fail('Expected tenant-scoped payment verification to hide other tenant payment.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    /**
     * 2026-10-09, direct request after a live report: a successful payment
     * whose MAC never shows an actual login/radacct record (almost always
     * a randomized-MAC mismatch between what paid and what the device
     * later presents). Admin generates a recovery voucher linked back to
     * the same payment, the old MAC's RADIUS rows are revoked, and nothing
     * creates a second payment row.
     */
    public function test_tenant_admin_can_generate_a_recovery_voucher_for_a_stuck_payment(): void
    {
        $this->createRadiusTables();
        [$payment, $tenant] = $this->paymentFixture('Recovery Tenant', 'recovery@example.com', 'Recovery Shop', 'HSF-RECOVERY', 'successful');
        $subscription = $payment->subscription;
        app(RadiusProvisioningService::class)->grantSubscriptionAccess($subscription);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->assertSee('Recover access')
            ->call('generateRecoveryVoucher', $payment->id)
            ->assertSet('showRecoveryVoucherModal', true);

        $payment->refresh();
        $this->assertNotNull($payment->voucher_id);

        $voucher = Voucher::findOrFail($payment->voucher_id);
        $this->assertSame('unused', $voucher->status);
        $this->assertSame($payment->shop_id, $voucher->shop_id);
        $this->assertSame($payment->package_id, $voucher->package_id);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
        ]);
        $subscription->refresh();
        $this->assertTrue($subscription->expires_at->isPast());

        $this->assertDatabaseMissing('radcheck', [
            'username' => $subscription->mac_address,
        ]);
    }

    public function test_recovery_voucher_cannot_be_generated_twice_for_the_same_payment(): void
    {
        $this->createRadiusTables();
        [$payment, $tenant] = $this->paymentFixture('Recovery Twice Tenant', 'recovery-twice@example.com', 'Recovery Twice Shop', 'HSF-RECOVERY-TWICE', 'successful');
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->call('generateRecoveryVoucher', $payment->id)
            ->assertSet('showRecoveryVoucherModal', true);

        $payment->refresh();
        $firstVoucherId = $payment->voucher_id;

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->set('showRecoveryVoucherModal', false)
            ->call('generateRecoveryVoucher', $payment->id)
            ->assertSet('showRecoveryVoucherModal', false)
            ->assertDispatched('notify');

        $payment->refresh();
        $this->assertSame($firstVoucherId, $payment->voucher_id);
    }

    public function test_recovery_voucher_is_not_offered_once_the_subscription_already_expired(): void
    {
        [$payment, $tenant] = $this->paymentFixture('Recovery Expired Tenant', 'recovery-expired@example.com', 'Recovery Expired Shop', 'HSF-RECOVERY-EXPIRED', 'successful');
        $payment->subscription->update(['expires_at' => now()->subMinute()]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->assertDontSee('Recover access')
            ->call('generateRecoveryVoucher', $payment->id)
            ->assertSet('showRecoveryVoucherModal', false)
            ->assertDispatched('notify');

        $payment->refresh();
        $this->assertNull($payment->voucher_id);
    }

    public function test_tenant_admin_cannot_generate_a_recovery_voucher_for_another_tenants_payment(): void
    {
        [$payment] = $this->paymentFixture('Recovery Other Tenant', 'recovery-other@example.com', 'Recovery Other Shop', 'HSF-RECOVERY-OTHER', 'successful');
        $actorTenant = Tenant::create([
            'company_name' => 'Recovery Actor Tenant',
            'owner_email' => 'recovery-actor@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $actorTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        try {
            Livewire::actingAs($user)
                ->test(PaymentsIndex::class)
                ->call('generateRecoveryVoucher', $payment->id);

            $this->fail('Expected tenant-scoped recovery voucher generation to hide other tenant payment.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $payment->refresh();
        $this->assertNull($payment->voucher_id);
    }

    public function test_recovery_voucher_can_be_redeemed_under_a_new_mac_without_a_duplicate_payment(): void
    {
        $this->createRadiusTables();
        [$payment, $tenant, $shop] = $this->paymentFixture('Recovery Redeem Tenant', 'recovery-redeem@example.com', 'Recovery Redeem Shop', 'HSF-RECOVERY-REDEEM', 'successful');
        $oldMac = $payment->subscription->mac_address;
        app(RadiusProvisioningService::class)->grantSubscriptionAccess($payment->subscription);
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Recovery Router',
            'nas_identifier' => 'recovery-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(PaymentsIndex::class)
            ->call('generateRecoveryVoucher', $payment->id);

        $payment->refresh();
        $voucher = Voucher::findOrFail($payment->voucher_id);
        $newMac = 'FF:EE:DD:CC:BB:AA';

        $this->post(route('hotspot.voucher.redeem'), [
            'mac' => $newMac,
            'nasid' => $router->nas_identifier,
            'voucher_code' => $voucher->code,
            'link-login' => 'http://hotspot.local/login',
        ])->assertOk()->assertSee('Access provisioned');

        $voucher->refresh();
        $this->assertSame('used', $voucher->status);
        $this->assertSame($newMac, $voucher->used_mac_address);

        // Same payment, not a duplicate -- only ever one Voucher per Payment (unique constraint).
        $this->assertSame(1, Payment::where('voucher_id', $voucher->id)->count());
        $this->assertSame($payment->id, $voucher->payment->id);

        $this->assertDatabaseHas('subscriptions', [
            'payment_id' => $payment->id,
            'mac_address' => $newMac,
        ]);
        $this->assertDatabaseHas('radcheck', ['username' => $newMac]);
        $this->assertDatabaseMissing('radcheck', ['username' => $oldMac]);
    }

    private function paymentFixture(string $tenantName, string $ownerEmail, string $shopName, string $txRef, string $status, array $tenantOverrides = []): array
    {
        $tenant = Tenant::create(array_merge([
            'company_name' => $tenantName,
            'owner_email' => $ownerEmail,
        ], $tenantOverrides));
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $shopName,
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
        $customer = Customer::create([
            'shop_id' => $shop->id,
            'mac_address' => 'AA:BB:CC:DD:EE:'.substr($txRef, 0, 2),
            'email' => strtolower($txRef).'@example.com',
            'phone' => '08000000000',
        ]);
        $commissionRate = ($tenant->billing_model ?? 'subscription') === 'commission' ? (float) $tenant->commission_rate : 0.0;
        $platformFee = round(500 * ($commissionRate / 100), 2);

        $payment = Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'provider' => 'flutterwave',
            'tx_ref' => $txRef,
            'provider_reference' => $status === 'successful' ? 'ord_'.$txRef : null,
            'amount' => 500,
            'gross_amount' => 500,
            'platform_fee_amount' => $platformFee,
            'tenant_net_amount' => 500 - $platformFee,
            'commission_rate' => $commissionRate,
            'billing_model' => $tenant->billing_model ?? 'subscription',
            'currency' => 'NGN',
            'status' => $status,
            'paid_at' => $status === 'successful' ? now() : null,
            'payload' => ['mac' => $customer->mac_address],
        ]);

        if ($status === 'successful') {
            Subscription::create([
                'shop_id' => $shop->id,
                'package_id' => $package->id,
                'payment_id' => $payment->id,
                'mac_address' => $customer->mac_address,
                'starts_at' => now(),
                'expires_at' => now()->addHour(),
            ]);
        }

        return [$payment, $tenant, $shop, $package, $customer];
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
