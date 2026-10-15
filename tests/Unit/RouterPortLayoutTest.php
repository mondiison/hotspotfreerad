<?php

namespace Tests\Unit;

use App\Support\RouterPortLayout;
use Tests\TestCase;

class RouterPortLayoutTest extends TestCase
{
    public function test_interface_name_builds_ethern_strings(): void
    {
        $this->assertSame('ether1', RouterPortLayout::interfaceName(1));
        $this->assertSame('ether8', RouterPortLayout::interfaceName(8));
        $this->assertSame('ether1', RouterPortLayout::interfaceName(0));
    }

    public function test_port_options_lists_every_port(): void
    {
        $options = RouterPortLayout::portOptions(3);

        $this->assertSame([
            1 => 'Port 1 (ether1)',
            2 => 'Port 2 (ether2)',
            3 => 'Port 3 (ether3)',
        ], $options);
    }

    public function test_port_options_clamps_to_at_least_one_port(): void
    {
        $this->assertSame([1 => 'Port 1 (ether1)'], RouterPortLayout::portOptions(0));
    }

    public function test_port_number_from_interface_name_parses_ethern(): void
    {
        $this->assertSame(3, RouterPortLayout::portNumberFromInterfaceName('ether3'));
        $this->assertSame(12, RouterPortLayout::portNumberFromInterfaceName('ether12'));
    }

    public function test_port_number_from_interface_name_returns_null_for_unparseable_names(): void
    {
        $this->assertNull(RouterPortLayout::portNumberFromInterfaceName('sfp-sfpplus1'));
        $this->assertNull(RouterPortLayout::portNumberFromInterfaceName('bridge1'));
        $this->assertNull(RouterPortLayout::portNumberFromInterfaceName(null));
        $this->assertNull(RouterPortLayout::portNumberFromInterfaceName(''));
        $this->assertNull(RouterPortLayout::portNumberFromInterfaceName('wifi1'));
    }

    public function test_conflicting_roles_detects_shared_port_numbers(): void
    {
        $conflicts = RouterPortLayout::conflictingRoles([
            'WAN 1' => 1,
            'Trunk' => 1,
            'Pi port' => 3,
        ]);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('WAN 1', $conflicts[0]);
        $this->assertStringContainsString('Trunk', $conflicts[0]);
    }

    public function test_conflicting_roles_ignores_null_and_distinct_ports(): void
    {
        $conflicts = RouterPortLayout::conflictingRoles([
            'WAN 1' => 1,
            'WAN 2' => null,
            'Trunk' => 2,
            'Pi port' => 3,
        ]);

        $this->assertSame([], $conflicts);
    }

    public function test_interface_names_from_number_list_parses_csv(): void
    {
        $this->assertSame(['ether5', 'ether6', 'ether7'], RouterPortLayout::interfaceNamesFromNumberList('5,6,7'));
        $this->assertSame(['ether2'], RouterPortLayout::interfaceNamesFromNumberList('2'));
        // Whitespace around entries is tolerated.
        $this->assertSame(['ether5', 'ether6'], RouterPortLayout::interfaceNamesFromNumberList('5, 6'));
    }

    public function test_interface_names_from_number_list_returns_empty_for_blank_input(): void
    {
        $this->assertSame([], RouterPortLayout::interfaceNamesFromNumberList(null));
        $this->assertSame([], RouterPortLayout::interfaceNamesFromNumberList(''));
    }

    public function test_port_numbers_from_interface_list_parses_csv(): void
    {
        $this->assertSame([5, 6, 7], RouterPortLayout::portNumbersFromInterfaceList('ether5,ether6,ether7'));
        $this->assertSame([2], RouterPortLayout::portNumbersFromInterfaceList('ether2'));
    }

    public function test_port_numbers_from_interface_list_returns_empty_array_for_blank_input(): void
    {
        // Distinct from the "unparseable" null case -- blank input is a valid "no extra
        // ports configured" state, not an error.
        $this->assertSame([], RouterPortLayout::portNumbersFromInterfaceList(null));
        $this->assertSame([], RouterPortLayout::portNumbersFromInterfaceList(''));
    }

    public function test_port_numbers_from_interface_list_returns_null_when_any_entry_is_unparseable(): void
    {
        $this->assertNull(RouterPortLayout::portNumbersFromInterfaceList('ether5,sfp-sfpplus1'));
        $this->assertNull(RouterPortLayout::portNumbersFromInterfaceList('bridge1'));
    }

    /**
     * 2026-10-15, extracted from RoutersIndex::portBucketAssignments() for
     * the new read-only Port Map tab -- simple-mode numeric fields take
     * priority when present, matching the wizard's own existing behavior
     * exactly (confirmed by RouterPortMapperTest passing unmodified).
     */
    public function test_bucket_assignments_from_simple_mode_numeric_fields(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'wan1_port_number' => 1,
            'trunk_port_number' => 2,
            'pi_port_number' => 3,
            'extra_trunk_port_numbers' => '4',
            'extra_mgmt_port_numbers' => '5',
        ]);

        $this->assertSame([1], $assignments['wan1']);
        $this->assertSame([2, 4], $assignments['trunk']);
        $this->assertSame([3], $assignments['pi']);
        $this->assertSame([5], $assignments['extra_mgmt']);
    }

    /**
     * 2026-10-15, confirmed necessary live: a router saved entirely in
     * advanced mode (no *_port_number fields at all, raw interface-name
     * strings instead) is exactly the kind of router that motivated this
     * whole feature -- it still needs a correct port map, derived here via
     * portNumberFromInterfaceName() against the standard "etherN"
     * convention.
     */
    public function test_bucket_assignments_falls_back_to_advanced_mode_interface_names(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'trunk_port' => 'ether3',
            'pi_port' => 'ether2',
            'extra_trunk_ports' => 'ether4',
            'extra_mgmt_ports' => 'ether5,ether6',
        ]);

        $this->assertSame([3, 4], $assignments['trunk']);
        $this->assertSame([2], $assignments['pi']);
        $this->assertSame([5, 6], $assignments['extra_mgmt']);
    }

    public function test_bucket_assignments_omits_a_non_standard_interface_name_from_the_numbered_grid(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'trunk_port' => 'sfp-sfpplus1',
            'pi_port' => 'ether3',
        ]);

        $this->assertSame([], $assignments['trunk']);
        $this->assertSame([3], $assignments['pi']);
    }

    /**
     * 2026-10-15, live report: "the visual mapper always preload[s] port i
     * did not save" -- wan2_port_number defaults to 8 regardless of
     * enable_second_wan (RouterManagementService::defaultProvisioningSettings()),
     * unlike wan1/trunk/pi, which are always-present roles. A router that
     * never enabled/saved a second WAN must not show that default port as
     * occupied here, or it vanishes from the unassigned-ports pool too.
     */
    public function test_wan2_bucket_is_empty_when_second_wan_is_not_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'wan2_port_number' => 8,
            'enable_second_wan' => false,
        ]);

        $this->assertSame([], $assignments['wan2']);
    }

    public function test_wan2_bucket_holds_its_port_once_second_wan_is_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'wan2_port_number' => 8,
            'enable_second_wan' => true,
        ]);

        $this->assertSame([8], $assignments['wan2']);
    }

    /**
     * Same bug class as wan2 above, for all three: extra_staff/extra_pos/
     * extra_pppoe's defaults are blank, but an already-saved router can
     * still hold a non-blank value while its feature is off (a legacy row,
     * or data from before Rule::prohibitedIf existed) -- that port must
     * not show as occupied here, mirroring the gate RouterManagementService's
     * own conflict-check already applies at its own read site.
     */
    public function test_extra_staff_bucket_is_empty_when_staff_is_not_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'extra_staff_port_numbers' => '5',
            'enable_staff' => false,
        ]);

        $this->assertSame([], $assignments['extra_staff']);
    }

    public function test_extra_staff_bucket_holds_its_port_once_staff_is_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'extra_staff_port_numbers' => '5',
            'enable_staff' => true,
        ]);

        $this->assertSame([5], $assignments['extra_staff']);
    }

    public function test_extra_pos_bucket_is_empty_when_pos_is_not_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'extra_pos_port_numbers' => '5',
            'enable_pos' => false,
        ]);

        $this->assertSame([], $assignments['extra_pos']);
    }

    public function test_extra_pos_bucket_holds_its_port_once_pos_is_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'extra_pos_port_numbers' => '5',
            'enable_pos' => true,
        ]);

        $this->assertSame([5], $assignments['extra_pos']);
    }

    public function test_extra_pppoe_bucket_is_empty_when_pppoe_is_not_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'extra_pppoe_port_numbers' => '5',
            'enable_pppoe' => false,
        ]);

        $this->assertSame([], $assignments['extra_pppoe']);
    }

    public function test_extra_pppoe_bucket_holds_its_port_once_pppoe_is_enabled(): void
    {
        $assignments = RouterPortLayout::bucketAssignments([
            'extra_pppoe_port_numbers' => '5',
            'enable_pppoe' => true,
        ]);

        $this->assertSame([5], $assignments['extra_pppoe']);
    }
}
