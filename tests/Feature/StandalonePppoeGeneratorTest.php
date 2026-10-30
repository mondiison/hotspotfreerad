<?php

namespace Tests\Feature;

use App\Livewire\Admin\StandalonePppoeGenerator;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StandalonePppoeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_pppoe_generator_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.tools.pppoe-generator'))
            ->assertOk()
            ->assertSee('PPPoE Bandwidth Generator');
    }

    public function test_tenant_admin_cannot_view_or_mount_the_pppoe_generator(): void
    {
        $tenant = Tenant::create(['company_name' => 'Tenant One', 'owner_email' => 'one@example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->actingAs($user)->get(route('admin.tools.pppoe-generator'))->assertForbidden();

        Livewire::test(StandalonePppoeGenerator::class)->assertForbidden();
    }

    public function test_super_admin_can_walk_through_the_wizard_and_generate_a_script(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePppoeGenerator::class)
            ->set('router_identity', 'Village Starlink')
            ->set('wan_download_mbps', '120')
            ->set('wan_upload_mbps', '20')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->set('port_roles.ether4', 'lan')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->assertSee('Per-device download')
            ->set('plans.0.name', 'Home 8M')
            ->set('plans.0.download_mbps', '8')
            ->set('plans.0.upload_mbps', '2')
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->assertSee('max-limit=18M/108M')
            ->assertSee('rate-limit=2M/8M')
            ->assertSee('/queue simple add name=lan-devices');
    }

    public function test_at_least_one_client_port_is_required(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(StandalonePppoeGenerator::class)
            ->set('step', 2)
            ->set('port_roles', ['ether2' => 'unused', 'ether3' => 'unused', 'ether4' => 'unused', 'ether5' => 'unused'])
            ->call('nextStep')
            ->assertHasErrors('port_roles')
            ->assertSet('step', 2);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
