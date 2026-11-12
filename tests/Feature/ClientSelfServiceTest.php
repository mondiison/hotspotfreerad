<?php

namespace Tests\Feature;

use App\Livewire\Client\PackagesIndex;
use App\Livewire\Client\VouchersIndex;
use App\Models\Client;
use App\Models\ClientBillingPlan;
use App\Models\ClientBillingSubscription;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-11-13: Phase 2 of the reseller model -- a Client's own self-service
 * packages, vouchers, branding, Wi-Fi name, reports, and support. The
 * cross-cutting concern tested throughout: PackageManagementService/
 * VoucherManagementService are hardcoded to TenantAccess internally (a
 * Client's tenant_id matches every shop in the whole tenant, not just
 * their own), so ClientAccess's own narrower scoping has to be the real
 * gate -- every "cannot touch another Client's X" case here is testing
 * that gate specifically, not the underlying service.
 */
class ClientSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_create_a_package_for_its_own_shop(): void
    {
        [, $shop, $clientUser] = $this->tenantShopAndClientUser();

        Livewire::actingAs($clientUser)
            ->test(PackagesIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Daily 5GB')
            ->set('service_type', 'hotspot')
            ->set('price', '500')
            ->set('currency', 'NGN')
            ->set('limit_uptime_seconds', '86400')
            ->set('speed_limit_profile', '5M/5M')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('packages', ['shop_id' => $shop->id, 'name' => 'Daily 5GB']);
    }

    public function test_client_cannot_create_a_package_for_a_shop_it_does_not_own(): void
    {
        [$tenant, , $clientUser] = $this->tenantShopAndClientUser();
        $otherShop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Not mine']);

        Livewire::actingAs($clientUser)
            ->test(PackagesIndex::class)
            ->call('create')
            ->set('shop_id', (string) $otherShop->id)
            ->set('name', 'Sneaky package')
            ->set('service_type', 'hotspot')
            ->set('price', '500')
            ->set('currency', 'NGN')
            ->set('limit_uptime_seconds', '86400')
            ->set('speed_limit_profile', '5M/5M')
            ->call('save')
            ->assertHasErrors('shop_id');

        $this->assertDatabaseMissing('packages', ['shop_id' => $otherShop->id, 'name' => 'Sneaky package']);
    }

    public function test_client_cannot_edit_a_package_belonging_to_another_shop_in_the_same_tenant(): void
    {
        [$tenant, , $clientUser] = $this->tenantShopAndClientUser();
        $otherShop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Not mine']);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Not my package',
            'service_type' => 'hotspot',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '2M/2M',
            'is_active' => true,
        ]);

        Livewire::actingAs($clientUser)
            ->test(PackagesIndex::class)
            ->call('edit', $otherPackage->id)
            ->assertForbidden();
    }

    public function test_client_can_generate_a_voucher_batch_mark_sold_and_void_unused_for_its_own_shop(): void
    {
        [, $shop, $clientUser, , $package] = $this->tenantShopAndClientUser(withPackage: true);

        $component = Livewire::actingAs($clientUser)
            ->test(VouchersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('package_id', (string) $package->id)
            ->set('name', 'Front desk batch')
            ->set('quantity', '3')
            ->set('code_length', '8')
            ->call('save')
            ->assertHasNoErrors();

        $batch = VoucherBatch::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame(3, $batch->vouchers()->count());

        $voucher = $batch->vouchers()->first();
        $component
            ->call('confirmSale', $voucher->id)
            ->set('sale_amount', '500')
            ->call('markSold')
            ->assertHasNoErrors();

        $this->assertSame('sold', $voucher->refresh()->status);

        $component->call('confirmVoid', $batch->id)->call('voidUnused');

        $this->assertSame('void', $batch->vouchers()->where('id', '!=', $voucher->id)->first()->status);
        $this->assertSame('sold', $voucher->refresh()->status);
    }

    public function test_client_cannot_inspect_a_voucher_batch_belonging_to_another_shop(): void
    {
        [$tenant, , $clientUser] = $this->tenantShopAndClientUser();
        $otherShop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Not mine']);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Pkg',
            'service_type' => 'hotspot',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '2M/2M',
            'is_active' => true,
        ]);
        $otherBatch = VoucherBatch::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'name' => 'Not mine',
            'quantity' => 1,
            'code_length' => 8,
            'status' => 'active',
        ]);

        Livewire::actingAs($clientUser)
            ->test(VouchersIndex::class)
            ->call('inspect', $otherBatch->id)
            ->assertForbidden();
    }

    public function test_client_can_print_its_own_voucher_batch_but_not_anothers(): void
    {
        [, $shop, $clientUser, , $package] = $this->tenantShopAndClientUser(withPackage: true);
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'Mine',
            'quantity' => 1,
            'code_length' => 8,
            'status' => 'active',
        ]);
        Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-ABC12345',
            'status' => 'unused',
        ]);

        $this->actingAs($clientUser)
            ->get(route('client.voucher-batches.print', $batch))
            ->assertOk()
            ->assertSee('MMS-ABC12345');

        $tenant = $shop->tenant;
        $otherShop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Not mine']);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Pkg',
            'service_type' => 'hotspot',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '2M/2M',
            'is_active' => true,
        ]);
        $otherBatch = VoucherBatch::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'name' => 'Not mine',
            'quantity' => 1,
            'code_length' => 8,
            'status' => 'active',
        ]);

        $this->get(route('client.voucher-batches.print', $otherBatch))->assertForbidden();
    }

    public function test_client_can_update_its_own_shops_branding(): void
    {
        [, $shop, $clientUser] = $this->tenantShopAndClientUser();

        $this->actingAs($clientUser)
            ->put(route('client.branding.update', $shop), [
                'portal_welcome_message' => 'Welcome to our hotspot!',
                'contact_phone' => '08012345678',
            ])
            ->assertRedirect(route('client.branding.index'));

        $shop->refresh();
        $this->assertSame('Welcome to our hotspot!', $shop->portal_welcome_message);
        $this->assertSame('08012345678', $shop->contact_phone);
    }

    public function test_client_cannot_update_another_shops_branding(): void
    {
        [$tenant, , $clientUser] = $this->tenantShopAndClientUser();
        $otherShop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Not mine']);

        $this->actingAs($clientUser)
            ->put(route('client.branding.update', $otherShop), ['portal_welcome_message' => 'Hijacked'])
            ->assertForbidden();

        $this->assertNull($otherShop->refresh()->portal_welcome_message);
    }

    public function test_client_can_update_the_wifi_name_on_its_own_router_when_builtin_wifi_is_enabled(): void
    {
        [, $shop, $clientUser, $router] = $this->tenantShopAndClientUser(withBuiltinWifi: true);

        $this->actingAs($clientUser)
            ->put(route('client.routers.update-ssid', $router), ['hotspot_ssid' => 'Downtown Free WiFi'])
            ->assertRedirect(route('client.routers.show', $router));

        $this->assertSame('Downtown Free WiFi', $router->refresh()->provisioning_settings['hotspot_ssid']);
    }

    public function test_client_cannot_update_the_wifi_name_on_a_router_it_does_not_own(): void
    {
        [$tenant, , $clientUser] = $this->tenantShopAndClientUser();
        $otherShop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Not mine']);
        $otherRouter = Router::create([
            'shop_id' => $otherShop->id,
            'name' => 'Not my router',
            'nas_identifier' => 'other-router',
            'wireguard_internal_ip' => '10.8.0.12',
            'shared_secret' => 'radius-secret',
        ]);

        $this->actingAs($clientUser)
            ->put(route('client.routers.update-ssid', $otherRouter), ['hotspot_ssid' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_client_reports_only_show_its_own_shops_sales(): void
    {
        [$tenant, $shop, $clientUser, , $package] = $this->tenantShopAndClientUser(withPackage: true);
        $otherShop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Not mine']);
        $otherPackage = Package::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other package',
            'service_type' => 'hotspot',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '2M/2M',
            'is_active' => true,
        ]);

        Payment::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'provider' => 'manual_bank',
            'tx_ref' => 'MINE-001',
            'amount' => 500,
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
        ]);
        Payment::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'provider' => 'manual_bank',
            'tx_ref' => 'NOTMINE-001',
            'amount' => 9999,
            'currency' => 'NGN',
            'status' => 'successful',
            'paid_at' => now(),
        ]);

        $this->actingAs($clientUser)
            ->get(route('client.reports.index'))
            ->assertOk()
            ->assertSee('NGN 500.00')
            ->assertDontSee('NGN 9,999.00')
            ->assertDontSee('NGN 10,499.00');
    }

    public function test_client_support_page_shows_tenant_contact_details(): void
    {
        [, , $clientUser] = $this->tenantShopAndClientUser();

        $this->actingAs($clientUser)
            ->get(route('client.support.index'))
            ->assertOk()
            ->assertSee('MMS Tenant');
    }

    /**
     * @return array{0: Tenant, 1: Shop, 2: User, 3: ?Router, 4: ?Package}
     */
    private function tenantShopAndClientUser(bool $withPackage = false, bool $withBuiltinWifi = false): array
    {
        $tenant = Tenant::create([
            'company_name' => 'MMS Tenant',
            'owner_email' => 'owner@example.com',
            'contact_phone' => '08011112222',
        ]);
        $tenant->update(['client_reselling_enabled' => true, 'default_client_trial_days' => 14]);

        // A package create() goes through BillingPlanLimits::assertCanCreatePackage(),
        // which requires the TENANT (not the Client) to have an active
        // platform billing subscription -- unrelated to client reselling,
        // but still a real prerequisite this fixture must satisfy.
        $platformPlan = \App\Models\BillingPlan::create([
            'name' => 'Growth',
            'slug' => 'growth-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);
        \App\Models\TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $platformPlan->id,
            'status' => 'active',
            'amount' => $platformPlan->monthly_price,
            'currency' => $platformPlan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $plan = ClientBillingPlan::create([
            'tenant_id' => $tenant->id,
            'name' => 'Standard',
            'price' => 5000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'Downtown Reseller', 'is_active' => true]);
        ClientBillingSubscription::create([
            'client_id' => $client->id,
            'client_billing_plan_id' => $plan->id,
            'status' => 'trialing',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Park Area', 'client_id' => $client->id]);

        $clientUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'role' => 'client',
            'is_active' => true,
        ]);

        $router = null;
        if ($withBuiltinWifi) {
            $router = Router::create([
                'shop_id' => $shop->id,
                'name' => 'Main Router',
                'nas_identifier' => 'demo-router',
                'wireguard_internal_ip' => '10.8.0.10',
                'shared_secret' => 'radius-secret',
                'provisioning_settings' => ['enable_builtin_wifi' => true, 'hotspot_ssid' => 'MMS Hotspot'],
            ]);
        }

        $package = null;
        if ($withPackage) {
            $package = Package::create([
                'shop_id' => $shop->id,
                'name' => 'Daily 5GB',
                'service_type' => 'hotspot',
                'price' => 500,
                'currency' => 'NGN',
                'limit_uptime_seconds' => 86400,
                'speed_limit_profile' => '5M/5M',
                'is_active' => true,
            ]);
        }

        return [$tenant, $shop, $clientUser, $router, $package];
    }
}
