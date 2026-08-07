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
}
