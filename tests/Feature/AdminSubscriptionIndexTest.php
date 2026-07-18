<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
