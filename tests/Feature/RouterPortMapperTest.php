<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoutersIndex;
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
