<?php

namespace Tests\Feature;

use App\Livewire\Admin\StandaloneScriptGenerator;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StandaloneScriptGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_script_generator_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.tools.script-generator'))
            ->assertOk()
            ->assertSee('Script Generator');
    }

    public function test_tenant_admin_cannot_view_the_script_generator_page(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.tools.script-generator'))
            ->assertForbidden();
    }

    public function test_tenant_staff_cannot_view_the_script_generator_page(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Tenant One',
            'owner_email' => 'one@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.tools.script-generator'))
            ->assertForbidden();
    }

    public function test_super_admin_can_walk_through_the_wizard_and_generate_a_script(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->assertSet('step', 1)
            ->set('router_identity', 'Corner Shop Router')
            ->set('hotspot_name', 'Corner Shop Wi-Fi')
            ->set('ros_version', '7')
            ->set('has_wireless', true)
            ->set('wan_port', 'ether1')
            ->set('ethernet_port_count', 5)
            ->set('wireless_interface', 'wifi1')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->set('enable_mgmt_network', true)
            ->set('mgmt_network', '10.10.20.1/24')
            ->set('mgmt_password', 'a-strong-password')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->set('hotspot_network', '10.10.10.1/24')
            ->set('profiles.0.name', 'Daily')
            ->set('profiles.0.download_mbps', 10)
            ->set('profiles.0.upload_mbps', 5)
            ->set('profiles.0.session_minutes', 1440)
            ->set('profiles.0.shared_users', 1)
            ->set('profiles.0.voucher_count', 4)
            ->call('nextStep')
            ->assertSet('step', 4)
            ->set('voucher_template', 'grid')
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('step', 5)
            ->assertSee('Corner Shop Wi-Fi')
            ->assertSee('4 voucher');

        Livewire::test(StandaloneScriptGenerator::class)
            ->set('router_identity', 'Corner Shop Router')
            ->set('hotspot_name', 'Corner Shop Wi-Fi')
            ->set('hotspot_network', '10.10.10.1/24')
            ->set('mgmt_network', '10.10.20.1/24')
            ->set('mgmt_password', 'a-strong-password')
            ->set('profiles.0.voucher_count', 4)
            ->call('generate')
            ->assertSet('generatedVouchers', function ($vouchers) {
                return count($vouchers) === 4;
            });
    }

    public function test_super_admin_can_walk_through_the_wizard_for_a_second_wired_only_router(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->assertSet('step', 1)
            ->assertSet('has_wireless', true)
            ->set('router_identity', 'Market-Router02')
            ->set('hotspot_name', 'Market Free Wi-Fi')
            ->set('ros_version', '7')
            ->set('has_wireless', false)
            ->set('wan_port', 'ether1')
            ->set('ethernet_port_count', 5)
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->assertSet('wireless_interface', '')
            ->set('enable_mgmt_network', true)
            ->set('mgmt_network', '10.20.20.1/24')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->set('hotspot_network', '10.20.10.1/24')
            ->set('profiles.0.name', '1 Hour')
            ->set('profiles.0.voucher_count', 3)
            ->call('addProfile')
            ->set('profiles.1.name', 'Daily')
            ->set('profiles.1.download_mbps', 10)
            ->set('profiles.1.upload_mbps', 3)
            ->set('profiles.1.session_minutes', 1440)
            ->set('profiles.1.shared_users', 1)
            ->set('profiles.1.voucher_count', 2)
            ->set('profiles.1.voucher_prefix', 'D-')
            ->set('profiles.1.voucher_username_password_same', false)
            ->set('profiles.1.voucher_character_set', 'numeric')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('step', 5)
            ->assertSee('Market Free Wi-Fi')
            ->assertSee('5 voucher');

        $component = Livewire::test(StandaloneScriptGenerator::class)
            ->set('router_identity', 'Market-Router02')
            ->set('hotspot_name', 'Market Free Wi-Fi')
            ->set('has_wireless', false)
            ->set('hotspot_network', '10.20.10.1/24')
            ->set('enable_mgmt_network', true)
            ->set('mgmt_network', '10.20.20.1/24')
            ->set('profiles.0.voucher_count', 3)
            ->call('generate')
            ->assertHasNoErrors();

        $script = $component->get('generatedScript');

        $this->assertStringNotContainsString('/interface wifi', $script);
        $this->assertStringNotContainsString('/interface wireless', $script);
        $this->assertStringContainsString('external access point', $script);
        $this->assertStringContainsString('Wire a dedicated port to bridge-mgmt', $script);
        $this->assertStringContainsString('/ip service set winbox address=10.20.20.0/24', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-hotspot interface=ether2', $script);
        $this->assertStringNotContainsString('/interface bridge port add bridge=bridge-hotspot interface=ether1', $script);
    }

    public function test_review_page_can_go_back_to_change_settings_without_losing_entered_data(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->set('router_identity', 'Corner Shop Router')
            ->set('hotspot_name', 'Corner Shop Wi-Fi')
            ->set('hotspot_network', '10.10.10.1/24')
            ->set('mgmt_network', '10.10.20.1/24')
            ->set('mgmt_password', 'a-strong-password')
            ->set('profiles.0.name', 'Daily')
            ->set('profiles.0.voucher_count', 4)
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('step', 5)
            ->call('previousStep')
            ->assertSet('step', 4)
            ->assertSet('router_identity', 'Corner Shop Router')
            ->assertSet('profiles.0.name', 'Daily')
            ->assertSet('profiles.0.voucher_count', 4);
    }

    public function test_wizard_can_generate_independent_usernames_and_passwords_with_a_chosen_character_set(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->set('router_identity', 'Corner Shop Router')
            ->set('hotspot_name', 'Corner Shop Wi-Fi')
            ->set('hotspot_network', '10.10.10.1/24')
            ->set('mgmt_network', '10.10.20.1/24')
            ->set('mgmt_password', 'a-strong-password')
            ->set('profiles.0.voucher_count', 3)
            ->set('profiles.0.voucher_username_password_same', false)
            ->set('profiles.0.voucher_character_set', 'numeric')
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('generatedVouchers', function ($vouchers) {
                foreach ($vouchers as $voucher) {
                    if ($voucher['username'] === $voucher['password']) {
                        return false;
                    }

                    if (! preg_match('/^\d+$/', $voucher['username']) || ! preg_match('/^\d+$/', $voucher['password'])) {
                        return false;
                    }
                }

                return count($vouchers) === 3;
            })
            ->assertSee('User:')
            ->assertSee('Pass:');
    }

    public function test_wizard_step_one_requires_wan_port_within_ethernet_port_count(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->set('wan_port', 'ether6')
            ->set('ethernet_port_count', 5)
            ->call('nextStep')
            ->assertHasErrors('ethernet_port_count');
    }

    public function test_wizard_requires_wireless_interface_when_router_has_wireless(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->set('has_wireless', true)
            ->set('wireless_interface', '')
            ->call('nextStep')
            ->assertHasErrors('wireless_interface');
    }

    public function test_wizard_requires_a_management_password_only_when_mgmt_network_and_wireless_are_both_enabled(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->call('nextStep')
            ->set('enable_mgmt_network', true)
            ->set('mgmt_network', '10.10.20.1/24')
            ->set('mgmt_password', '')
            ->call('nextStep')
            ->assertHasErrors('mgmt_password');

        Livewire::test(StandaloneScriptGenerator::class)
            ->set('has_wireless', false)
            ->call('nextStep')
            ->set('enable_mgmt_network', true)
            ->set('mgmt_network', '10.10.20.1/24')
            ->set('mgmt_password', '')
            ->call('nextStep')
            ->assertHasNoErrors();
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
