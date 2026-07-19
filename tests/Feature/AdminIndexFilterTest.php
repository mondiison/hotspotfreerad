<?php

namespace Tests\Feature;

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
            'description' => 'Livewire Router',
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
            'description' => 'Updated Router',
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
