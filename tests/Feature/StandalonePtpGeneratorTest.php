<?php

namespace Tests\Feature;

use App\Livewire\Admin\StandalonePtpGenerator;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StandalonePtpGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_ptp_generator_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.tools.ptp-generator'))
            ->assertOk()
            ->assertSee('PTP Radio Generator');
    }

    public function test_tenant_admin_cannot_view_the_ptp_generator_page(): void
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
            ->get(route('admin.tools.ptp-generator'))
            ->assertForbidden();
    }

    public function test_tenant_staff_cannot_view_the_ptp_generator_page(): void
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
            ->get(route('admin.tools.ptp-generator'))
            ->assertForbidden();
    }

    public function test_mounting_the_component_directly_is_also_forbidden_for_a_tenant_admin(): void
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

        $this->actingAs($user);

        Livewire::test(StandalonePtpGenerator::class)
            ->assertForbidden();
    }

    public function test_super_admin_can_walk_through_the_wizard_and_generate_both_scripts(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->assertSet('step', 1)
            ->set('link_name', 'Tower A to Tower B')
            ->set('radio_a.identity', 'Tower A')
            ->set('radio_a.ros_version', '7')
            ->set('radio_b.identity', 'Tower B')
            ->set('radio_b.ros_version', '7')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('ap_end', 'radio_a')
            ->set('link_mode', 'routed')
            ->set('radio_a.wireless_interface', 'wifi1')
            ->set('radio_b.wireless_interface', 'wifi1')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->set('frequency_mhz', 5745)
            ->set('channel_width_mhz', 20)
            ->set('ssid', 'ptp-link-1')
            ->set('security_mode', 'wpa2')
            ->set('psk', 'supersecretpsk')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->set('distance_km', 5)
            ->set('ptp_subnet', '10.20.30.0/30')
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('step', 5)
            ->assertSee('Tower A')
            ->assertSee('Tower B');
    }

    public function test_generated_scripts_contain_both_ends_addressing_and_matching_ssid(): void
    {
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(StandalonePtpGenerator::class)
            ->set('link_name', 'Tower A to Tower B')
            ->set('radio_a.identity', 'Tower A')
            ->set('radio_b.identity', 'Tower B')
            ->set('ap_end', 'radio_a')
            ->set('link_mode', 'routed')
            ->set('ssid', 'ptp-link-1')
            ->set('psk', 'supersecretpsk')
            ->set('ptp_subnet', '10.20.30.0/30')
            ->call('generate')
            ->assertHasNoErrors();

        $scripts = $component->get('generatedScripts');

        $this->assertStringContainsString('ssid="ptp-link-1"', $scripts['script_a']);
        $this->assertStringContainsString('ssid="ptp-link-1"', $scripts['script_b']);
        $this->assertStringContainsString('address=10.20.30.1/30', $scripts['script_a']);
        $this->assertStringContainsString('address=10.20.30.2/30', $scripts['script_b']);
    }

    public function test_review_page_can_go_back_to_change_settings_without_losing_entered_data(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('link_name', 'Tower A to Tower B')
            ->set('radio_a.identity', 'Tower A')
            ->set('psk', 'supersecretpsk')
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('step', 5)
            ->call('previousStep')
            ->assertSet('step', 4)
            ->assertSet('link_name', 'Tower A to Tower B')
            ->assertSet('radio_a.identity', 'Tower A');
    }

    public function test_changing_ros_version_resets_the_wireless_interface_default(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->assertSet('radio_a.wireless_interface', 'wifi1')
            ->set('radio_a.ros_version', '6')
            ->assertSet('radio_a.wireless_interface', 'wlan1')
            ->set('radio_a.ros_version', '7')
            ->assertSet('radio_a.wireless_interface', 'wifi1');
    }

    public function test_a_cpe_only_radio_can_be_the_ap_end_in_plain_point_to_point_topology(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->assertSet('link_topology', 'ptp')
            ->set('radio_a.cpe_only', true)
            ->assertSet('ap_end', 'radio_a')
            ->set('ap_end', 'radio_a')
            ->call('nextStep') // step 1 -> 2
            ->call('nextStep')
            ->assertHasNoErrors();
    }

    public function test_marking_the_current_ap_end_as_cpe_only_flips_the_ap_role_in_ptmp_topology(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('link_topology', 'ptmp')
            ->assertSet('ap_end', 'radio_a')
            ->set('radio_a.cpe_only', true)
            ->assertSet('ap_end', 'radio_b');
    }

    public function test_marking_both_radios_cpe_only_in_ptmp_topology_does_not_flip_to_an_equally_invalid_radio(): void
    {
        $this->actingAs($this->superAdmin());

        // Once both ends are CPE-only there's no valid AP end left to flip to --
        // ap_end should just stay put (validation, tested separately, catches
        // this combination as an error rather than the auto-flip silently
        // picking a radio that's equally unable to run ap-bridge).
        Livewire::test(StandalonePtpGenerator::class)
            ->set('link_topology', 'ptmp')
            ->set('radio_a.cpe_only', true)
            ->assertSet('ap_end', 'radio_b')
            ->set('radio_b.cpe_only', true)
            ->assertSet('ap_end', 'radio_b');
    }

    public function test_switching_to_ptmp_topology_flips_the_ap_role_away_from_an_already_cpe_only_radio(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('radio_a.cpe_only', true)
            ->assertSet('ap_end', 'radio_a')
            ->set('link_topology', 'ptmp')
            ->assertSet('ap_end', 'radio_b');
    }

    public function test_selecting_a_cpe_only_radio_as_the_ap_end_is_rejected_in_ptmp_topology(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('link_topology', 'ptmp')
            ->set('radio_a.cpe_only', true)
            ->set('ap_end', 'radio_a')
            ->call('nextStep') // step 1 -> 2
            ->call('nextStep')
            ->assertHasErrors('ap_end');
    }

    public function test_both_radios_cpe_only_is_rejected_in_ptmp_topology(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('link_topology', 'ptmp')
            ->set('radio_a.cpe_only', true)
            ->set('radio_b.cpe_only', true)
            ->call('nextStep') // step 1 -> 2
            ->call('nextStep')
            ->assertHasErrors('radio_b.cpe_only');
    }

    public function test_ptmp_topology_generates_an_ap_bridge_script(): void
    {
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(StandalonePtpGenerator::class)
            ->set('link_name', 'Hub to Site 1')
            ->set('radio_a.identity', 'Hub')
            ->set('radio_a.ros_version', '6')
            ->set('radio_a.wireless_interface', 'wlan1')
            ->set('radio_b.identity', 'Site 1')
            ->set('radio_b.ros_version', '6')
            ->set('radio_b.wireless_interface', 'wlan1')
            ->set('link_topology', 'ptmp')
            ->set('ap_end', 'radio_a')
            ->set('psk', 'supersecretpsk')
            ->call('generate')
            ->assertHasNoErrors();

        $scripts = $component->get('generatedScripts');

        $this->assertStringContainsString('mode=ap-bridge', $scripts['script_a']);
        $this->assertStringContainsString('mode=station', $scripts['script_b']);
    }

    public function test_mixed_wpa2_wpa3_is_rejected_unless_both_radios_are_ros7(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('radio_a.ros_version', '7')
            ->set('radio_b.ros_version', '6')
            ->set('security_mode', 'wpa2_wpa3')
            ->set('psk', 'supersecretpsk')
            ->call('nextStep') // step 1 -> 2
            ->call('nextStep') // step 2 -> 3
            ->call('nextStep')
            ->assertHasErrors('security_mode');
    }

    public function test_remote_network_is_required_when_the_route_toggle_is_enabled(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('psk', 'supersecretpsk')
            ->set('ptp_subnet', '10.20.30.0/30')
            ->set('radio_a.add_remote_route', true)
            ->set('radio_a.remote_network', '')
            ->call('generate')
            ->assertHasErrors('radio_a.remote_network');
    }

    public function test_wireless_interface_must_look_like_a_real_interface_name(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePtpGenerator::class)
            ->set('radio_a.wireless_interface', 'not a valid interface')
            ->call('nextStep') // step 1 -> 2
            ->call('nextStep')
            ->assertHasErrors('radio_a.wireless_interface');
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
