<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSubscriptionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_access_report_across_tenants(): void
    {
        [$ownSubscription] = $this->subscriptionFixture('Own Tenant', 'own@example.com', 'Own Shop', 'AA:BB:CC:DD:EE:01', true);
        [$otherSubscription] = $this->subscriptionFixture('Other Tenant', 'other@example.com', 'Other Shop', 'AA:BB:CC:DD:EE:02', false);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.subscriptions.index'))
            ->assertOk()
            ->assertSee($ownSubscription->mac_address)
            ->assertSee($otherSubscription->mac_address)
            ->assertSee('Active now')
            ->assertSee('Test access');
    }

    public function test_tenant_admin_only_sees_own_access_records(): void
    {
        [$ownSubscription, $ownTenant] = $this->subscriptionFixture('Own Tenant', 'own@example.com', 'Own Shop', 'AA:BB:CC:DD:EE:03', true);
        [$otherSubscription] = $this->subscriptionFixture('Other Tenant', 'other@example.com', 'Other Shop', 'AA:BB:CC:DD:EE:04', true);
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.subscriptions.index'))
            ->assertOk()
            ->assertSee($ownSubscription->mac_address)
            ->assertDontSee($otherSubscription->mac_address)
            ->assertSee('Own Shop')
            ->assertDontSee('Other Shop');
    }

    public function test_access_report_can_filter_by_status_source_and_search(): void
    {
        [$activeSubscription, $tenant] = $this->subscriptionFixture('Own Tenant', 'own@example.com', 'Main Hall', 'AA:BB:CC:DD:EE:05', true, 'Active Plan');
        [$expiredSubscription] = $this->subscriptionFixture('Own Tenant', 'own@example.com', 'Main Hall', 'AA:BB:CC:DD:EE:06', false, 'Expired Plan', $tenant);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.subscriptions.index', [
                'status' => 'active',
                'source' => 'paid',
                'search' => 'Active Plan',
            ]))
            ->assertOk()
            ->assertSee($activeSubscription->mac_address)
            ->assertDontSee($expiredSubscription->mac_address);
    }

    public function test_access_report_can_filter_by_start_date_presets_and_custom_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));

        try {
            [$recentSubscription, $tenant] = $this->subscriptionFixture('Date Tenant', 'date@example.com', 'Recent Shop', 'AA:BB:CC:DD:EE:10', true, 'Recent Plan');
            [$oldSubscription] = $this->subscriptionFixture('Date Tenant', 'date@example.com', 'Old Shop', 'AA:BB:CC:DD:EE:11', true, 'Old Plan', $tenant);
            $oldSubscription->update([
                'starts_at' => '2026-07-01 10:00:00',
                'expires_at' => '2026-07-01 11:00:00',
            ]);

            $user = User::factory()->create([
                'role' => 'super_admin',
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get(route('admin.subscriptions.index', [
                    'preset' => 'last_7_days',
                ]))
                ->assertOk()
                ->assertSee('7 days')
                ->assertSee('2026-07-13')
                ->assertSee('2026-07-19')
                ->assertSee($recentSubscription->mac_address)
                ->assertDontSee($oldSubscription->mac_address)
                ->assertDontSee('Old Shop');

            $this->actingAs($user)
                ->get(route('admin.subscriptions.index', [
                    'from' => '2026-07-01',
                    'to' => '2026-07-02',
                ]))
                ->assertOk()
                ->assertSee($oldSubscription->mac_address)
                ->assertDontSee($recentSubscription->mac_address);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tenant_admin_can_export_filtered_access_report(): void
    {
        [$ownSubscription, $ownTenant] = $this->subscriptionFixture('Own Export Tenant', 'own-export@example.com', 'Own Export Shop', 'AA:BB:CC:DD:EE:07', true, 'Export Plan');
        [$expiredSubscription] = $this->subscriptionFixture('Own Export Tenant', 'own-export@example.com', 'Own Export Shop', 'AA:BB:CC:DD:EE:08', false, 'Expired Export Plan', $ownTenant);
        [$otherSubscription] = $this->subscriptionFixture('Other Export Tenant', 'other-export@example.com', 'Other Export Shop', 'AA:BB:CC:DD:EE:09', true, 'Other Export Plan');
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.subscriptions.export', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfDay()->toDateString(),
                'status' => 'active',
                'source' => 'paid',
                'search' => 'Export Plan',
            ]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Access Report', $content);
        $this->assertStringContainsString('From,'.now()->startOfMonth()->toDateString(), $content);
        $this->assertStringContainsString('To,'.now()->endOfDay()->toDateString(), $content);
        $this->assertStringContainsString('Status,active', $content);
        $this->assertStringContainsString('Source,paid', $content);
        $this->assertStringContainsString('Search,"Export Plan"', $content);
        $this->assertStringContainsString('"MAC Address",Package,Shop,Tenant,Source,"Payment Ref",Status', $content);
        $this->assertStringContainsString($ownSubscription->mac_address, $content);
        $this->assertStringContainsString('"Export Plan","Own Export Shop","Own Export Tenant",Paid', $content);
        $this->assertStringContainsString(',Active,', $content);
        $this->assertStringContainsString(',No,5M/5M,', $content);
        $this->assertStringNotContainsString($expiredSubscription->mac_address, $content);
        $this->assertStringNotContainsString($otherSubscription->mac_address, $content);
        $this->assertStringNotContainsString('Other Export Shop', $content);
    }

    private function subscriptionFixture(
        string $tenantName,
        string $ownerEmail,
        string $shopName,
        string $macAddress,
        bool $active,
        string $packageName = 'One Hour Ultra',
        ?Tenant $existingTenant = null
    ): array {
        $tenant = $existingTenant ?? Tenant::create([
            'company_name' => $tenantName,
            'owner_email' => $ownerEmail,
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $shopName,
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => $packageName,
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $payment = $active ? Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'provider' => 'flutterwave',
            'tx_ref' => str_replace(':', '', $macAddress),
            'amount' => 500,
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
        ]) : null;
        $subscription = Subscription::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'payment_id' => $payment?->id,
            'mac_address' => $macAddress,
            'starts_at' => now()->subMinutes(10),
            'expires_at' => $active ? now()->addHour() : now()->subHour(),
            'is_throttled' => ! $active,
        ]);

        return [$subscription, $tenant, $shop, $package, $payment];
    }
}
