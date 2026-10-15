<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoutersIndex;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-10-14, Phase 2 of the drag-and-drop port-mapping request: the visual
 * port mapper is an additive, alternative way to set the exact same
 * provisioning_settings fields the dropdown pickers already read/write --
 * covers RoutersIndex::portBucketAssignments()/unassignedPorts()/
 * assignPortToBucket()/unassignPort().
 */
class RouterPortMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_settings_place_the_four_singular_roles_in_their_buckets(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8);

        $assignments = $component->instance()->portBucketAssignments();

        $this->assertSame([1], $assignments['wan1']);
        $this->assertSame([2], $assignments['trunk']);
        $this->assertSame([3], $assignments['pi']);
        // wan2_port_number defaults to 8 regardless of enable_second_wan,
        // but enable_second_wan defaults to false -- confirmed live
        // 2026-10-15 ("always preload port i did not save"): that port
        // must stay empty here, not just have its bucket's label hidden in
        // the blade, or it vanishes from the unassigned-ports pool too.
        $this->assertSame([], $assignments['wan2']);
        $this->assertSame([], $assignments['extra_mgmt']);
    }

    public function test_wan2_bucket_only_holds_its_port_once_second_wan_is_enabled(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8)
            ->set('provisioning_settings.enable_second_wan', true);

        $assignments = $component->instance()->portBucketAssignments();

        $this->assertSame([8], $assignments['wan2']);
        // And port 8 is correctly excluded from "unassigned" once it's
        // genuinely claimed.
        $this->assertNotContains(8, $component->instance()->unassignedPorts());
    }

    public function test_wan2_port_is_available_in_the_unassigned_pool_while_second_wan_is_disabled(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8);

        $this->assertContains(8, $component->instance()->unassignedPorts());
    }

    public function test_unassigned_ports_excludes_every_currently_assigned_port(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 5);

        // Defaults: wan1=1, trunk=2, pi=3 -- ports 4 and 5 are free.
        $this->assertSame([4, 5], $component->instance()->unassignedPorts());
    }

    public function test_assigning_a_port_to_a_single_capacity_bucket_bumps_the_previous_occupant(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8)
            ->call('assignPortToBucket', 'wan1', 7)
            ->assertSet('provisioning_settings.wan1_port_number', 7);

        $assignments = $component->instance()->portBucketAssignments();

        $this->assertSame([7], $assignments['wan1']);
        // Port 1 (the old WAN1) is back in the unassigned pool, not vanished.
        $this->assertContains(1, $component->instance()->unassignedPorts());
    }

    public function test_assigning_a_port_to_a_multi_capacity_bucket_keeps_existing_members(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8)
            ->call('assignPortToBucket', 'trunk', 6)
            ->assertSet('provisioning_settings.trunk_port_number', 2)
            ->assertSet('provisioning_settings.extra_trunk_port_numbers', '6');

        $this->assertSame([2, 6], $component->instance()->portBucketAssignments()['trunk']);
    }

    public function test_moving_a_port_between_buckets_removes_it_from_the_old_one(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8)
            ->call('assignPortToBucket', 'extra_mgmt', 5)
            ->assertSet('provisioning_settings.extra_mgmt_port_numbers', '5')
            ->call('assignPortToBucket', 'extra_hotspot', 5)
            ->assertSet('provisioning_settings.extra_mgmt_port_numbers', '')
            ->assertSet('provisioning_settings.extra_hotspot_port_numbers', '5');
    }

    public function test_unassigning_a_port_removes_it_from_its_current_bucket(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8)
            ->call('unassignPort', 2)
            ->assertSet('provisioning_settings.trunk_port_number', null);
    }

    /**
     * 2026-10-15, live report: "i removed extra port for pos, staff and
     * mgmt but after saving it keeps preloading the old values."
     * unassignPort() used to only ever clear the numeric
     * extra_*_port_numbers field, never its raw-interface-name
     * extra_*_ports sibling -- so the stale string field survived the
     * save, and routerProvisioningSettings()'s reverse-derivation (which
     * reads the string field, not the saved number) resurrected the
     * "removed" port the very next time the edit wizard reopened. This
     * exercises the full real-world cycle: save with extra ports, remove
     * them via the mapper, save again, then reopen edit fresh and confirm
     * they genuinely don't come back.
     */
    public function test_unassigning_extra_ports_and_saving_does_not_resurrect_them_on_the_next_edit(): void
    {
        $shop = $this->shop();
        $admin = $this->superAdmin();

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Extra Ports Router',
            'nas_identifier' => 'extra-ports-router-2',
            'wireguard_internal_ip' => '10.8.0.95',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'wan1' => 'ether1',
                'trunk_port' => 'ether2',
                'pi_port' => 'ether3',
                'enable_staff' => true,
                'enable_pos' => true,
                'extra_mgmt_port_numbers' => '9',
                'extra_mgmt_ports' => 'ether9',
                'extra_staff_port_numbers' => '7',
                'extra_staff_ports' => 'ether7',
                'extra_pos_port_numbers' => '6',
                'extra_pos_ports' => 'ether6',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            // Confirm it actually opened pre-filled before removing anything.
            ->assertSet('provisioning_settings.extra_mgmt_port_numbers', '9')
            ->assertSet('provisioning_settings.extra_staff_port_numbers', '7')
            ->assertSet('provisioning_settings.extra_pos_port_numbers', '6')
            ->call('unassignPort', 9)
            ->call('unassignPort', 7)
            ->call('unassignPort', 6)
            ->call('save');

        $router->refresh();

        $this->assertSame('', (string) ($router->provisioning_settings['extra_mgmt_port_numbers'] ?? ''));
        $this->assertSame('', (string) ($router->provisioning_settings['extra_mgmt_ports'] ?? ''));
        $this->assertSame('', (string) ($router->provisioning_settings['extra_staff_port_numbers'] ?? ''));
        $this->assertSame('', (string) ($router->provisioning_settings['extra_staff_ports'] ?? ''));
        $this->assertSame('', (string) ($router->provisioning_settings['extra_pos_port_numbers'] ?? ''));
        $this->assertSame('', (string) ($router->provisioning_settings['extra_pos_ports'] ?? ''));

        // The actual regression: reopening the wizard fresh must not
        // reverse-derive the removed ports back from a stale string field.
        Livewire::actingAs($admin)
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->assertSet('provisioning_settings.extra_mgmt_port_numbers', '')
            ->assertSet('provisioning_settings.extra_staff_port_numbers', '')
            ->assertSet('provisioning_settings.extra_pos_port_numbers', '');
    }

    /**
     * 2026-10-15, direct follow-up live report ("port edit don't save on
     * the router wizard tab... it still preload with default values"): the
     * fix above only covered clearing a port via the drag-and-drop mapper
     * (writePortBucketAssignments()) -- but editing the plain
     * "extra_*_port_numbers" CSV text field directly (wire:model.live,
     * bypassing the mapper entirely) funnels through
     * RouterManagementService::normalizeProvisioningSettings() instead,
     * which had the identical stale-string-sibling bug independently.
     * Confirmed this test genuinely failed against the pre-fix service
     * code (reverted via git stash) before the save-pipeline reordering
     * fix (derivePortInterfaceNames() now runs before the blank-stripping
     * filter, and the plural roles re-derive on array_key_exists() rather
     * than !empty()) made it pass.
     */
    public function test_clearing_the_extra_port_text_field_directly_also_does_not_resurrect_it(): void
    {
        $shop = $this->shop();
        $admin = $this->superAdmin();

        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Text Field Router',
            'nas_identifier' => 'text-field-router',
            'wireguard_internal_ip' => '10.8.0.100',
            'shared_secret' => 'radius-secret',
            'provisioning_settings' => [
                'wan1' => 'ether1',
                'trunk_port' => 'ether2',
                'pi_port' => 'ether3',
                'enable_staff' => true,
                'extra_staff_port_numbers' => '7',
                'extra_staff_ports' => 'ether7',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->assertSet('provisioning_settings.extra_staff_port_numbers', '7')
            ->set('provisioning_settings.extra_staff_port_numbers', '')
            ->call('save');

        $router->refresh();

        $this->assertSame('', (string) ($router->provisioning_settings['extra_staff_port_numbers'] ?? ''));
        $this->assertSame('', (string) ($router->provisioning_settings['extra_staff_ports'] ?? ''));

        Livewire::actingAs($admin)
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->assertSet('provisioning_settings.extra_staff_port_numbers', '');
    }

    public function test_re_dropping_a_port_into_its_own_bucket_is_a_no_op(): void
    {
        $shop = $this->shop();

        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8)
            ->call('assignPortToBucket', 'trunk', 2)
            ->assertSet('provisioning_settings.trunk_port_number', 2);

        $this->assertSame([2], $component->instance()->portBucketAssignments()['trunk']);
    }

    public function test_assigning_to_an_unknown_bucket_is_ignored(): void
    {
        $shop = $this->shop();

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->set('shop_id', (string) $shop->id)
            ->set('provisioning_settings.port_count', 8)
            ->call('assignPortToBucket', 'not-a-real-bucket', 5)
            ->assertSet('provisioning_settings.wan1_port_number', 1)
            ->assertSet('provisioning_settings.trunk_port_number', 2);
    }

    /**
     * 2026-10-15, direct request: the router show page's read-only Port Map
     * tab has no editing controls of its own -- the actual drag-and-drop
     * mapper only lives on this wizard's Hardware step (step 2). The tab's
     * new "Edit ports" link passes ?step=2 alongside the existing
     * ?open_edit=, so the wizard opens directly on the Hardware step
     * instead of its default Identity step.
     */
    public function test_open_edit_with_a_step_query_param_opens_directly_on_that_step(): void
    {
        $shop = $this->shop();
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Deep Linked Router',
            'nas_identifier' => 'deep-linked-router',
            'wireguard_internal_ip' => '10.8.0.97',
            'shared_secret' => 'radius-secret',
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.routers.index', ['open_edit' => $router->id, 'step' => 2]));

        $response->assertSee('Visual port mapping');
        $response->assertDontSee('NAS identifier');
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
