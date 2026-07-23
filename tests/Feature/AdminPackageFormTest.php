<?php

namespace Tests\Feature;

use App\Livewire\Admin\PackageForm;
use App\Livewire\Admin\PackagesIndex;
use App\Models\Package;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPackageFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_form_shows_guided_plan_controls(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.packages.create'))
            ->assertOk()
            ->assertSee($shop->name)
            ->assertSee('Plan Shape')
            ->assertSee('Service type')
            ->assertSee('PPPoE subscriber')
            ->assertSee('Bandwidth / RADIUS rate limit')
            ->assertSee('Mikrotik-Rate-Limit')
            ->assertSee('Unlimited')
            ->assertSee('30 days')
            ->assertSee('20GB')
            ->assertSee('512K/512K');
    }

    public function test_fup_threshold_requires_fup_speed(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.packages.store'), [
                'shop_id' => $shop->id,
                'name' => 'Fair Use Plan',
                'price' => 1000,
                'currency' => 'ngn',
                'limit_uptime_seconds' => 86400,
                'speed_limit_profile' => '5M/5M',
                'fup_data_threshold_bytes' => 5368709120,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('fup_speed_limit_profile');
    }

    public function test_package_price_must_meet_flutterwave_minimum(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.packages.store'), [
                'shop_id' => $shop->id,
                'name' => 'Trial Micro Plan',
                'price' => 0,
                'currency' => 'ngn',
                'limit_uptime_seconds' => 3600,
                'speed_limit_profile' => '5M/5M',
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('price');
    }

    public function test_livewire_package_form_updates_plan_presets(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin());

        Livewire::test(PackageForm::class, [
            'package' => new Package,
            'shops' => collect([$shop->load('tenant')]),
            'billingUsage' => null,
        ])
            ->call('setPreset', 'limit_uptime_seconds', '604800')
            ->call('setPreset', 'data_limit_bytes', '5368709120')
            ->call('setPreset', 'speed_limit_profile', '10M/10M')
            ->assertSet('limit_uptime_seconds', '604800')
            ->assertSet('data_limit_bytes', '5368709120')
            ->assertSet('speed_limit_profile', '10M/10M');
    }

    public function test_livewire_package_form_saves_and_syncs_radius_profile(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin());

        Livewire::test(PackageForm::class, [
            'package' => new Package,
            'shops' => collect([$shop->load('tenant')]),
            'billingUsage' => null,
        ])
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Livewire Weekly')
            ->set('service_type', 'both')
            ->set('price', '1500')
            ->set('currency', 'ngn')
            ->set('limit_uptime_seconds', '604800')
            ->set('data_limit_bytes', '')
            ->set('speed_limit_profile', '10M/10M')
            ->set('is_active', true)
            ->call('save')
            ->assertRedirect(route('admin.packages.index'));

        $this->assertDatabaseHas('packages', [
            'shop_id' => $shop->id,
            'name' => 'Livewire Weekly',
            'service_type' => 'both',
            'currency' => 'NGN',
            'data_limit_bytes' => null,
        ]);

        $groupName = DB::table('packages')->where('name', 'Livewire Weekly')->value('radius_group_name');

        $this->assertNotEmpty($groupName);
        $this->assertDatabaseHas('radgroupreply', [
            'groupname' => $groupName,
            'attribute' => 'Mikrotik-Rate-Limit',
            'value' => '10M/10M',
        ]);
    }

    public function test_package_modal_saves_pppoe_bandwidth_to_radius_profile(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin());

        Livewire::test(PackagesIndex::class)
            ->call('create')
            ->assertSee('Service type')
            ->assertSee('Bandwidth / RADIUS rate limit')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Home PPPoE 10M')
            ->set('service_type', 'pppoe')
            ->assertSee('PPPoE bandwidth')
            ->set('price', '8000')
            ->set('currency', 'ngn')
            ->set('limit_uptime_seconds', '2592000')
            ->set('data_limit_bytes', '')
            ->set('speed_limit_profile', '5M/10M')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Package created and synced to RADIUS profile.');

        $package = Package::where('name', 'Home PPPoE 10M')->firstOrFail();

        $this->assertSame('pppoe', $package->service_type);
        $this->assertDatabaseHas('radgroupreply', [
            'groupname' => $package->radius_group_name,
            'attribute' => 'Mikrotik-Rate-Limit',
            'value' => '5M/10M',
        ]);
    }

    public function test_package_modal_defaults_service_type_from_current_filter(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->superAdmin());

        Livewire::test(PackagesIndex::class, [
            'filters' => ['service' => 'pppoe_capable'],
        ])
            ->call('create')
            ->assertSet('showFormModal', true)
            ->assertSet('service_type', 'pppoe')
            ->assertSee('PPPoE bandwidth');

        Livewire::test(PackagesIndex::class, [
            'filters' => ['service' => 'both'],
        ])
            ->call('create')
            ->assertSet('showFormModal', true)
            ->assertSet('service_type', 'both')
            ->assertSee('Shared bandwidth');

        Livewire::test(PackagesIndex::class, [
            'filters' => ['service' => 'hotspot_capable'],
        ])
            ->call('create')
            ->assertSet('showFormModal', true)
            ->assertSet('service_type', 'hotspot')
            ->assertSee($shop->name);
    }

    private function shop(): Shop
    {
        $tenant = Tenant::create([
            'company_name' => 'Demo Tenant',
            'owner_email' => fake()->unique()->safeEmail(),
        ]);

        return Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Shop',
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
