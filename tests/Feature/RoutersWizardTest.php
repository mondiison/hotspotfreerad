<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoutersIndex;
use App\Models\BillingPlan;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoutersWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_walk_through_every_step_and_create_a_wireless_router(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->assertSet('step', 1)
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Wizard Router')
            ->set('nas_identifier', 'wizard-router')
            ->set('wireguard_internal_ip', '10.8.0.80')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('provisioning_settings.enable_builtin_wifi', true)
            ->set('provisioning_settings.port_count', 8)
            ->set('provisioning_settings.wan1_port_number', 1)
            ->set('provisioning_settings.trunk_port_number', 2)
            ->set('provisioning_settings.pi_port_number', 3)
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->set('provisioning_settings.builtin_wifi_interface', 'wifi1')
            ->set('provisioning_settings.mgmt_wifi_password', 'MmsMgmt2026!')
            ->set('provisioning_settings.staff_wifi_password', 'MmsStaff2026!')
            ->set('provisioning_settings.pos_wifi_password', 'MmsPos2026!')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Router created and synced to RADIUS nas.');

        $router = Router::where('nas_identifier', 'wizard-router')->firstOrFail();

        $this->assertSame('ether1', $router->provisioning_settings['wan1']);
        $this->assertSame('ether2', $router->provisioning_settings['trunk_port']);
        $this->assertSame('ether3', $router->provisioning_settings['pi_port']);
        $this->assertTrue($router->provisioning_settings['enable_builtin_wifi']);
    }

    public function test_wireless_only_fields_are_hidden_until_built_in_wifi_is_enabled(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Wired Router')
            ->set('nas_identifier', 'wired-router')
            ->set('wireguard_internal_ip', '10.8.0.81')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->call('nextStep');

        $component
            ->assertDontSee('Built-in Wi-Fi credentials')
            ->assertDontSee('Management Wi-Fi password')
            ->assertSee('No built-in Wi-Fi selected');

        $component
            ->call('previousStep')
            ->set('provisioning_settings.enable_builtin_wifi', true)
            ->call('nextStep')
            ->assertSee('Built-in Wi-Fi credentials')
            ->assertSee('Management Wi-Fi password');
    }

    public function test_builtin_wifi_credentials_are_required_when_wireless_is_enabled(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Wireless Router')
            ->set('nas_identifier', 'wireless-router')
            ->set('wireguard_internal_ip', '10.8.0.82')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->set('provisioning_settings.enable_builtin_wifi', true)
            ->call('nextStep')
            ->set('provisioning_settings.mgmt_wifi_password', '')
            ->call('nextStep')
            ->assertHasErrors('provisioning_settings.mgmt_wifi_password');
    }

    public function test_pos_and_pppoe_network_fields_are_hidden_when_disabled(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'No Pos Router')
            ->set('nas_identifier', 'no-pos-router')
            ->set('wireguard_internal_ip', '10.8.0.83')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->call('nextStep')
            ->set('provisioning_settings.enable_pos', false)
            ->set('provisioning_settings.enable_pppoe', false)
            ->call('nextStep')
            ->assertDontSee('POS gateway')
            ->assertDontSee('POS DHCP pool')
            ->assertDontSee('PPPoE gateway');
    }

    public function test_port_role_collision_fails_validation(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Collision Router')
            ->set('nas_identifier', 'collision-router')
            ->set('wireguard_internal_ip', '10.8.0.84')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->set('provisioning_settings.port_count', 8)
            ->set('provisioning_settings.wan1_port_number', 1)
            ->set('provisioning_settings.trunk_port_number', 1)
            ->call('nextStep')
            ->assertHasErrors('provisioning_settings.port_count');
    }

    public function test_port_number_higher_than_port_count_fails_validation(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Out Of Range Router')
            ->set('nas_identifier', 'out-of-range-router')
            ->set('wireguard_internal_ip', '10.8.0.85')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->set('provisioning_settings.port_count', 4)
            ->set('provisioning_settings.trunk_port_number', 6)
            ->call('nextStep')
            ->assertHasErrors('provisioning_settings.port_count');
    }

    public function test_advanced_mode_accepts_raw_interface_names_without_port_count_bounds(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('name', 'Advanced Router')
            ->set('nas_identifier', 'advanced-router')
            ->set('wireguard_internal_ip', '10.8.0.86')
            ->set('shared_secret', 'radius-secret')
            ->call('nextStep')
            ->set('provisioning_settings.ports_advanced_mode', true)
            ->set('provisioning_settings.wan1', 'sfp-sfpplus1')
            ->set('provisioning_settings.trunk_port', 'bridge1')
            ->set('provisioning_settings.pi_port', 'ether3')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->call('nextStep')
            ->call('save')
            ->assertHasNoErrors();

        $router = Router::where('nas_identifier', 'advanced-router')->firstOrFail();

        $this->assertSame('sfp-sfpplus1', $router->provisioning_settings['wan1']);
        $this->assertSame('bridge1', $router->provisioning_settings['trunk_port']);
    }

    public function test_editing_a_router_with_legacy_ethern_values_pre_fills_the_port_pickers(): void
    {
        $shop = $this->shop();
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Legacy Router',
            'nas_identifier' => 'legacy-ethern-router',
            'wireguard_internal_ip' => '10.8.0.87',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'wan1' => 'ether1',
                'wan2' => 'ether8',
                'trunk_port' => 'ether2',
                'pi_port' => 'ether3',
            ],
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->assertSet('provisioning_settings.ports_advanced_mode', false)
            ->assertSet('provisioning_settings.wan1_port_number', 1)
            ->assertSet('provisioning_settings.trunk_port_number', 2)
            ->assertSet('provisioning_settings.pi_port_number', 3);
    }

    public function test_editing_a_router_with_a_non_standard_interface_name_opens_in_advanced_mode(): void
    {
        $shop = $this->shop();
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Odd Interface Router',
            'nas_identifier' => 'odd-interface-router',
            'wireguard_internal_ip' => '10.8.0.88',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'wan1' => 'ether1',
                'trunk_port' => 'sfp-sfpplus1',
                'pi_port' => 'ether3',
            ],
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->assertSet('provisioning_settings.ports_advanced_mode', true)
            ->assertSet('provisioning_settings.trunk_port', 'sfp-sfpplus1');
    }

    public function test_editing_a_router_without_touching_anything_saves_byte_identical_settings(): void
    {
        $shop = $this->shop();
        // Saved with an explicit provisioning_settings array, matching how every real
        // router is actually created (via RouterManagementService, which always fully
        // normalizes) rather than a bare Eloquent create() that would leave the column
        // null and make this comparison meaningless.
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Untouched Router',
            'nas_identifier' => 'untouched-router',
            'wireguard_internal_ip' => '10.8.0.89',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'profile' => 'pppoe_isp',
                'wan1' => 'ether1',
                'wan2' => 'ether8',
                'trunk_port' => 'ether2',
                'pi_port' => 'ether3',
            ],
        ]);
        $before = $router->fresh()->provisioning_settings;

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->call('save')
            ->assertHasNoErrors();

        $after = $router->fresh()->provisioning_settings;

        $this->assertSame($before['wan1'], $after['wan1']);
        $this->assertSame($before['wan2'], $after['wan2']);
        $this->assertSame($before['trunk_port'], $after['trunk_port']);
        $this->assertSame($before['pi_port'], $after['pi_port']);
        $this->assertSame($before['profile'], $after['profile']);
    }

    public function test_billing_allowance_banner_is_visible_in_the_create_wizard(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Wizard Tenant',
            'owner_email' => 'wizard-tenant@example.com',
        ]);
        $plan = BillingPlan::create([
            'name' => 'Starter',
            'slug' => 'starter-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
            'shop_limit' => 2,
            'router_limit' => 3,
            'package_limit' => 4,
            'is_active' => true,
        ]);
        TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Wizard Shop']);
        Router::create([
            'shop_id' => $shop->id,
            'name' => 'Existing Router',
            'nas_identifier' => 'existing-router-billing',
            'wireguard_internal_ip' => '10.8.0.90',
            'shared_secret' => 'radius-secret',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(RoutersIndex::class)
            ->call('create')
            ->assertSee('Platform allowance')
            ->assertSee('1 routers')
            ->assertSee('3');
    }

    private function shop(): Shop
    {
        return Shop::create([
            'tenant_id' => Tenant::create([
                'company_name' => 'Demo Tenant',
                'owner_email' => fake()->unique()->safeEmail(),
            ])->id,
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
