<?php

namespace Tests\Feature;

use App\Livewire\Admin\PackagesIndex;
use App\Livewire\Admin\RoutersIndex;
use App\Livewire\Admin\ShopsIndex;
use App\Models\ExpenseCategory;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_index_can_search_and_filter_by_status(): void
    {
        $tenant = $this->tenant();
        $activeShop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Hall',
            'location_city' => 'Ibadan',
            'is_active' => true,
        ]);
        $inactiveShop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Closed Annex',
            'location_city' => 'Lagos',
            'is_active' => false,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.shops.index', ['search' => 'Ibadan', 'status' => 'active']))
            ->assertOk()
            ->assertSee($activeShop->name)
            ->assertDontSee($inactiveShop->name);
    }

    public function test_shop_index_shows_and_filters_payment_configuration(): void
    {
        $tenant = $this->tenant();
        $configuredShop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Payment Ready Shop',
            'is_active' => true,
            'flutterwave_client_id' => 'tenant-client-id',
            'flutterwave_client_secret' => 'tenant-client-secret',
            'flutterwave_webhook_secret' => 'tenant-webhook-secret',
        ]);
        $unconfiguredShop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash Only Shop',
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.shops.index', ['payments' => 'configured']))
            ->assertOk()
            ->assertSee($configuredShop->name)
            ->assertSee('Configured')
            ->assertSee('Webhook secret saved')
            ->assertDontSee($unconfiguredShop->name);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.shops.index', ['payments' => 'unconfigured']))
            ->assertOk()
            ->assertSee($unconfiguredShop->name)
            ->assertSee('Not configured')
            ->assertSee('Customer payments disabled')
            ->assertDontSee($configuredShop->name);
    }

    public function test_livewire_shop_index_creates_shop_from_flyout(): void
    {
        $tenant = $this->tenant();

        Livewire::actingAs($this->superAdmin())
            ->test(ShopsIndex::class)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('tenant_id', (string) $tenant->id)
            ->set('name', 'Livewire Shop')
            ->set('location_city', 'Abeokuta')
            ->set('flutterwave_client_id', 'tenant-client-id')
            ->set('flutterwave_client_secret', 'tenant-client-secret')
            ->set('flutterwave_webhook_secret', 'tenant-webhook-secret')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Shop created.')
            ->assertSee('Livewire Shop');

        $this->assertDatabaseHas('shops', [
            'tenant_id' => $tenant->id,
            'name' => 'Livewire Shop',
            'location_city' => 'Abeokuta',
            'is_active' => true,
        ]);

        $shop = Shop::where('name', 'Livewire Shop')->firstOrFail();
        $this->assertTrue($shop->hasCompleteFlutterwaveCredentials());
        $this->assertTrue($shop->hasFlutterwaveWebhookSecret());
    }

    public function test_livewire_shop_index_edits_shop_from_flyout(): void
    {
        $tenant = $this->tenant();
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Shop',
            'location_city' => 'Old City',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(ShopsIndex::class)
            ->call('edit', $shop->id)
            ->assertSet('showFormModal', true)
            ->assertSet('name', 'Old Shop')
            ->set('name', 'Updated Shop')
            ->set('location_city', 'New City')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Shop updated.')
            ->assertSee('Updated Shop');

        $this->assertDatabaseHas('shops', [
            'id' => $shop->id,
            'name' => 'Updated Shop',
            'location_city' => 'New City',
            'is_active' => false,
        ]);
    }

    public function test_livewire_shop_index_filters_without_page_reload(): void
    {
        $tenant = $this->tenant();
        Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Hall',
            'location_city' => 'Ibadan',
            'is_active' => true,
        ]);
        Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Closed Annex',
            'location_city' => 'Lagos',
            'is_active' => false,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(ShopsIndex::class)
            ->set('search', 'Ibadan')
            ->set('status', 'active')
            ->assertSee('Main Hall')
            ->assertDontSee('Closed Annex')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('status', '')
            ->assertSee('Closed Annex');
    }

    public function test_livewire_shop_index_deletes_shop_with_confirmation(): void
    {
        $tenant = $this->tenant();
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Delete Me Shop',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(ShopsIndex::class)
            ->call('confirmDelete', $shop->id)
            ->assertSet('showDeleteModal', true)
            ->assertSee('Delete Me Shop')
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertSee('Shop deleted.');

        $this->assertDatabaseMissing('shops', [
            'id' => $shop->id,
        ]);
    }

    public function test_router_index_can_search_and_filter_by_online_status(): void
    {
        $shop = $this->shop();
        $onlineRouter = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'main-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
            'is_online' => true,
        ]);
        $offlineRouter = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Backup Router',
            'nas_identifier' => 'backup-router',
            'wireguard_internal_ip' => '10.8.0.20',
            'shared_secret' => 'radius-secret',
            'is_online' => false,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.routers.index', ['search' => 'main-router', 'status' => 'online']))
            ->assertOk()
            ->assertSee($onlineRouter->name)
            ->assertDontSee($offlineRouter->name);
    }

    public function test_livewire_router_index_creates_router_and_syncs_radius(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Livewire Router')
            ->set('nas_identifier', 'livewire-router')
            ->set('wireguard_internal_ip', '10.8.0.50')
            ->set('shared_secret', 'radius-secret')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Router created and synced to RADIUS nas.')
            ->assertSee('Livewire Router');

        $this->assertDatabaseHas('routers', [
            'shop_id' => $shop->id,
            'name' => 'Livewire Router',
            'nas_identifier' => 'livewire-router',
            'wireguard_internal_ip' => '10.8.0.50',
        ]);
        $this->assertDatabaseHas('nas', [
            'nasname' => '10.8.0.50',
            'shortname' => 'livewire-router',
            'secret' => 'radius-secret',
            'description' => 'Livewire Router (services: hotspot, ppp)',
        ]);
    }

    public function test_livewire_router_index_edits_router_and_keeps_blank_secret(): void
    {
        $shop = $this->shop();
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Old Router',
            'nas_identifier' => 'old-router',
            'wireguard_internal_ip' => '10.8.0.60',
            'shared_secret' => 'original-secret',
            'is_online' => false,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->assertSet('showFormModal', true)
            ->assertSet('name', 'Old Router')
            ->set('name', 'Updated Router')
            ->set('nas_identifier', 'updated-router')
            ->set('wireguard_internal_ip', '10.8.0.61')
            ->set('shared_secret', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Router updated and synced to RADIUS nas.');

        $router->refresh();

        $this->assertSame('Updated Router', $router->name);
        $this->assertSame('original-secret', $router->shared_secret);
        $this->assertDatabaseHas('nas', [
            'nasname' => '10.8.0.61',
            'shortname' => 'updated-router',
            'secret' => 'original-secret',
            'description' => 'Updated Router (services: hotspot, ppp)',
        ]);
    }

    public function test_livewire_router_index_sets_ip_preset_and_filters_without_reload(): void
    {
        $shop = $this->shop();
        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Online Router',
            'nas_identifier' => 'online-router',
            'wireguard_internal_ip' => '10.8.0.70',
            'shared_secret' => 'radius-secret',
            'is_online' => true,
        ]);
        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Offline Router',
            'nas_identifier' => 'offline-router',
            'wireguard_internal_ip' => '10.8.0.71',
            'shared_secret' => 'radius-secret',
            'is_online' => false,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('setPreset', 'wireguard_internal_ip', '10.8.0.12')
            ->assertSet('wireguard_internal_ip', '10.8.0.12')
            ->set('search', 'Online')
            ->set('status', 'online')
            ->assertSee('Online Router')
            ->assertDontSee('Offline Router')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('status', '')
            ->assertSee('Offline Router');
    }

    public function test_livewire_router_index_deletes_router_with_confirmation(): void
    {
        $shop = $this->shop();
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Delete Router',
            'nas_identifier' => 'delete-router',
            'wireguard_internal_ip' => '10.8.0.80',
            'shared_secret' => 'radius-secret',
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('confirmDelete', $router->id)
            ->assertSet('showDeleteModal', true)
            ->assertSee('Delete Router')
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertSee('Router deleted.');

        $this->assertDatabaseMissing('routers', [
            'id' => $router->id,
        ]);
    }

    public function test_package_index_can_search_and_filter_by_active_status(): void
    {
        $shop = $this->shop();
        $activePackage = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $inactivePackage = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Legacy Plan',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '1M/1M',
            'is_active' => false,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.packages.index', ['search' => 'Daily', 'status' => 'active']))
            ->assertOk()
            ->assertSee($activePackage->name)
            ->assertDontSee($inactivePackage->name);
    }

    public function test_package_index_can_filter_by_service_type(): void
    {
        $shop = $this->shop();
        $hotspotPackage = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Hotspot Daily',
            'service_type' => 'hotspot',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        $pppoePackage = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Home PPPoE',
            'service_type' => 'pppoe',
            'price' => 8000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '5M/10M',
            'is_active' => true,
        ]);
        $sharedPackage = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Hybrid Access',
            'service_type' => 'both',
            'price' => 3000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 604800,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.packages.index', ['service' => 'pppoe_capable']))
            ->assertOk()
            ->assertSee('PPPoE-capable')
            ->assertSee($pppoePackage->name)
            ->assertSee($sharedPackage->name)
            ->assertDontSee($hotspotPackage->name);
    }

    public function test_livewire_package_index_creates_package_and_syncs_radius(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(PackagesIndex::class)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Modal Weekly')
            ->set('price', '1500')
            ->set('currency', 'ngn')
            ->set('limit_uptime_seconds', '604800')
            ->set('data_limit_bytes', '')
            ->set('speed_limit_profile', '10M/10M')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Package created and synced to RADIUS profile.')
            ->assertSee('Modal Weekly');

        $package = Package::where('name', 'Modal Weekly')->firstOrFail();

        $this->assertSame('NGN', $package->currency);
        $this->assertNull($package->data_limit_bytes);
        $this->assertDatabaseHas('radgroupreply', [
            'groupname' => $package->radius_group_name,
            'attribute' => 'Mikrotik-Rate-Limit',
            'value' => '10M/10M',
        ]);
    }

    public function test_livewire_package_index_edits_package_from_flyout(): void
    {
        $shop = $this->shop();
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Old Daily',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(PackagesIndex::class)
            ->call('edit', $package->id)
            ->assertSet('showFormModal', true)
            ->assertSet('name', 'Old Daily')
            ->set('name', 'Updated Daily')
            ->set('price', '750')
            ->set('data_limit_bytes', '5368709120')
            ->set('speed_limit_profile', '8M/8M')
            ->set('fup_data_threshold_bytes', '2147483648')
            ->set('fup_speed_limit_profile', '1M/1M')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Package updated and synced to RADIUS profile.');

        $package->refresh();

        $this->assertSame('Updated Daily', $package->name);
        $this->assertSame('750.00', $package->price);
        $this->assertSame(5368709120, $package->data_limit_bytes);
        $this->assertSame('8M/8M', $package->speed_limit_profile);
        $this->assertSame(2147483648, $package->fup_data_threshold_bytes);
        $this->assertSame('1M/1M', $package->fup_speed_limit_profile);
        $this->assertFalse($package->is_active);
    }

    public function test_livewire_package_index_sets_presets_and_filters_without_reload(): void
    {
        $shop = $this->shop();
        Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'is_active' => true,
        ]);
        Package::create([
            'shop_id' => $shop->id,
            'name' => 'Legacy Plan',
            'service_type' => 'pppoe',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '1M/1M',
            'is_active' => false,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(PackagesIndex::class)
            ->call('setPreset', 'limit_uptime_seconds', '2592000')
            ->call('setPreset', 'data_limit_bytes', '5368709120')
            ->call('setPreset', 'speed_limit_profile', '20M/20M')
            ->assertSet('limit_uptime_seconds', '2592000')
            ->assertSet('data_limit_bytes', '5368709120')
            ->assertSet('speed_limit_profile', '20M/20M')
            ->set('search', 'Daily')
            ->set('status', 'active')
            ->set('service', 'hotspot_capable')
            ->assertSee('Daily 5GB')
            ->assertDontSee('Legacy Plan')
            ->call('filterBy', 'pppoe_capable', '')
            ->assertSet('service', 'pppoe_capable')
            ->assertSet('status', '')
            ->assertSet('search', '')
            ->assertSee('Legacy Plan')
            ->call('filterBy', '', 'active')
            ->assertSet('service', '')
            ->assertSet('status', 'active')
            ->assertSee('Daily 5GB')
            ->assertDontSee('Legacy Plan')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('status', '')
            ->assertSet('service', '')
            ->assertSee('Legacy Plan');
    }

    public function test_livewire_package_index_deletes_package_with_confirmation(): void
    {
        $shop = $this->shop();
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Delete Plan',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '1M/1M',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(PackagesIndex::class)
            ->call('confirmDelete', $package->id)
            ->assertSet('showDeleteModal', true)
            ->assertSee('Delete Plan')
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertSee('Package deleted.');

        $this->assertDatabaseMissing('packages', ['id' => $package->id]);
    }

    public function test_expense_category_index_can_search_and_filter_scope_status_and_budget(): void
    {
        $tenant = $this->tenant();
        $matchingCategory = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Generator fuel',
            'description' => 'Diesel for busy hotspot sites.',
            'monthly_budget' => 75000,
            'is_active' => true,
        ]);
        $inactiveCategory = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Dormant repairs',
            'monthly_budget' => 20000,
            'is_active' => false,
        ]);
        $platformCategory = ExpenseCategory::create([
            'tenant_id' => null,
            'name' => 'Platform licensing',
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.expense-categories.index', [
                'search' => 'Diesel',
                'scope' => 'tenant',
                'status' => 'active',
                'budget' => 'budgeted',
            ]))
            ->assertOk()
            ->assertSee($matchingCategory->name)
            ->assertDontSee($inactiveCategory->name)
            ->assertDontSee($platformCategory->name);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Demo Tenant',
            'owner_email' => fake()->unique()->safeEmail(),
        ]);
    }

    private function shop(): Shop
    {
        return Shop::create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'Demo Shop',
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
