<?php

namespace Tests\Feature;

use App\Livewire\Admin\CustomerContactsIndex;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PosDevice;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminCustomerContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_sees_unique_numbers_deduplicated_across_sources(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        // The exact same person shows up as both a hotspot customer and a
        // PPPoE subscriber under the same (already-normalized) number --
        // this must collapse into ONE row, not two.
        Customer::create(['shop_id' => $shop->id, 'mac_address' => 'AA:BB:CC:DD:EE:01', 'phone' => '08012345678']);
        PppoeSubscriber::create([
            'shop_id' => $shop->id, 'package_id' => $package->id, 'username' => 'dupe-user',
            'password' => 'secret', 'phone' => '08012345678', 'full_name' => 'Shared Person',
            'starts_at' => now(), 'expires_at' => now()->addMonth(), 'is_active' => true,
        ]);
        PosDevice::create([
            'shop_id' => $shop->id, 'package_id' => $package->id, 'device_name' => 'Counter 1',
            'mac_address' => 'AA:BB:CC:DD:EE:02', 'phone' => '09011112222', 'owner_name' => 'POS Owner',
            'starts_at' => now(), 'expires_at' => now()->addMonth(), 'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(CustomerContactsIndex::class)
            ->assertSee('08012345678')
            ->assertSee('Shared Person')
            ->assertSee('09011112222')
            ->assertSee('POS Owner');

        $this->actingAs($user)
            ->get(route('admin.customer-contacts.index'))
            ->assertOk()
            ->assertSee('2 unique phone numbers');
    }

    public function test_tenant_admin_only_sees_own_tenants_numbers(): void
    {
        [$tenantOne, $shopOne] = $this->fixture('Tenant One', 'Shop One');
        [$tenantTwo, $shopTwo] = $this->fixture('Tenant Two', 'Shop Two');
        Customer::create(['shop_id' => $shopOne->id, 'mac_address' => 'AA:BB:CC:DD:EE:01', 'phone' => '08011110000']);
        Customer::create(['shop_id' => $shopTwo->id, 'mac_address' => 'AA:BB:CC:DD:EE:02', 'phone' => '08022220000']);

        $user = User::factory()->create(['tenant_id' => $tenantOne->id, 'role' => 'tenant_admin', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(CustomerContactsIndex::class)
            ->assertSee('08011110000')
            ->assertDontSee('08022220000');
    }

    public function test_super_admin_sees_numbers_across_every_tenant(): void
    {
        [$tenantOne, $shopOne] = $this->fixture('Tenant One', 'Shop One');
        [$tenantTwo, $shopTwo] = $this->fixture('Tenant Two', 'Shop Two');
        Customer::create(['shop_id' => $shopOne->id, 'mac_address' => 'AA:BB:CC:DD:EE:01', 'phone' => '08011110000']);
        Customer::create(['shop_id' => $shopTwo->id, 'mac_address' => 'AA:BB:CC:DD:EE:02', 'phone' => '08022220000']);

        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'is_active' => true]);

        Livewire::actingAs($superAdmin)
            ->test(CustomerContactsIndex::class)
            ->assertSee('08011110000')
            ->assertSee('08022220000');
    }

    public function test_search_filters_by_phone_name_or_shop(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        PppoeSubscriber::create([
            'shop_id' => $shop->id, 'package_id' => $package->id, 'username' => 'u1',
            'password' => 'secret', 'phone' => '08033334444', 'full_name' => 'Findable Person',
            'starts_at' => now(), 'expires_at' => now()->addMonth(), 'is_active' => true,
        ]);
        Customer::create(['shop_id' => $shop->id, 'mac_address' => 'AA:BB:CC:DD:EE:03', 'phone' => '08055556666']);

        Livewire::actingAs($user)
            ->test(CustomerContactsIndex::class)
            ->set('search', 'Findable')
            ->assertSee('08033334444')
            ->assertDontSee('08055556666');
    }

    public function test_export_csv_includes_unique_contacts(): void
    {
        [$tenant, $shop] = $this->fixture();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        Customer::create(['shop_id' => $shop->id, 'mac_address' => 'AA:BB:CC:DD:EE:04', 'phone' => '08077778888']);

        $response = $this->actingAs($user)->get(route('admin.customer-contacts.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('08077778888', $response->streamedContent());
    }

    /**
     * @return array{0: Tenant, 1: Shop, 2: Package}
     */
    private function fixture(string $tenantName = 'Demo Tenant', string $shopName = 'Demo Shop'): array
    {
        $tenant = Tenant::create(['company_name' => $tenantName, 'owner_email' => fake()->unique()->safeEmail()]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => $shopName]);
        $package = Package::create([
            'shop_id' => $shop->id, 'name' => 'Home 10M', 'service_type' => 'both', 'price' => 1000,
            'currency' => 'NGN', 'limit_uptime_seconds' => 3600, 'speed_limit_profile' => '5M/5M', 'is_active' => true,
        ]);

        return [$tenant, $shop, $package];
    }
}
