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
            ->set('hotspot_name', 'Corner Shop Wi-Fi')
            ->set('ros_version', '7')
            ->set('has_wireless', true)
            ->set('wan_port', 'ether1')
            ->set('lan_port', 'ether2')
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
            ->set('hotspot_name', 'Corner Shop Wi-Fi')
            ->set('lan_port', 'ether2')
            ->set('hotspot_network', '10.10.10.1/24')
            ->set('mgmt_network', '10.10.20.1/24')
            ->set('mgmt_password', 'a-strong-password')
            ->set('profiles.0.voucher_count', 4)
            ->call('generate')
            ->assertSet('generatedVouchers', function ($vouchers) {
                return count($vouchers) === 4;
            });
    }

    public function test_wizard_step_one_requires_different_wan_and_lan_ports(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandaloneScriptGenerator::class)
            ->set('wan_port', 'ether1')
            ->set('lan_port', 'ether1')
            ->call('nextStep')
            ->assertHasErrors('lan_port');
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
