<?php

namespace Tests\Unit;

use App\Services\RouterOsConnectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RouterOsConnectionServiceTest extends TestCase
{
    #[DataProvider('uptimeProvider')]
    public function test_it_parses_routeros_uptime_strings(string $uptime, int $expectedSeconds): void
    {
        $this->assertSame($expectedSeconds, RouterOsConnectionService::parseRouterOsUptime($uptime));
    }

    public static function uptimeProvider(): array
    {
        return [
            'seconds only' => ['5s', 5],
            'minutes and seconds' => ['4m5s', 245],
            'hours minutes seconds' => ['3h4m5s', 11045],
            'days hours minutes seconds' => ['2d3h4m5s', 183_845],
            'weeks days hours minutes seconds' => ['4w2d3h4m5s', 2_603_045],
            'empty string' => ['', 0],
            'garbage input' => ['not-a-duration', 0],
        ];
    }

    #[DataProvider('readOnlyCommandProvider')]
    public function test_it_parses_read_only_commands(string $command, ?array $expected): void
    {
        $this->assertSame($expected, RouterOsConnectionService::parseReadOnlyCommand($command));
    }

    public static function readOnlyCommandProvider(): array
    {
        return [
            'simple path' => ['/interface print', ['path' => '/interface', 'filters' => []]],
            'multi-segment path' => ['/ip hotspot profile print', ['path' => '/ip/hotspot/profile', 'filters' => []]],
            'already-slashed path' => ['/system/resource print', ['path' => '/system/resource', 'filters' => []]],
            'single where filter' => ['/ip hotspot active print where server=hotspot1', ['path' => '/ip/hotspot/active', 'filters' => ['server' => 'hotspot1']]],
            'multiple where filters' => ['/ppp active print where service=pppoe and running=true', ['path' => '/ppp/active', 'filters' => ['service' => 'pppoe', 'running' => 'true']]],
            'quoted filter value' => ['/ppp active print detail where name="customer 001"', ['path' => '/ppp/active', 'filters' => ['name' => 'customer 001']]],
            'empty command' => ['', null],
            'no print verb' => ['/interface', null],
            'print as only token' => ['print', null],
            'add is rejected' => ['/ip firewall filter add chain=input action=drop', null],
            'set is rejected' => ['/ip hotspot profile print where name=x; /system reset-configuration', null],
            'remove is rejected' => ['/user remove admin', null],
            'monitor is rejected' => ['/interface monitor-traffic ether1', null],
        ];
    }

    #[DataProvider('readOnlyQueryPathProvider')]
    public function test_it_builds_the_full_print_command_path_from_a_bare_menu_path(string $menuPath, string $expected): void
    {
        $this->assertSame($expected, RouterOsConnectionService::readOnlyQueryPath($menuPath));
    }

    public static function readOnlyQueryPathProvider(): array
    {
        return [
            // Regression: parseReadOnlyCommand() intentionally returns the bare menu
            // path (e.g. "/system/resource") for display, but the actual RouterOS API
            // command needs the trailing verb -- "/system/resource" alone is not
            // executable and RouterOS reports "no such command" for it.
            'simple path' => ['/interface', '/interface/print'],
            'multi-segment path' => ['/ip/hotspot/profile', '/ip/hotspot/profile/print'],
            'trailing slash is not doubled' => ['/system/resource/', '/system/resource/print'],
        ];
    }
}
