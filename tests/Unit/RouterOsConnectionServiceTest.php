<?php

namespace Tests\Unit;

use App\Models\Router;
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

    #[DataProvider('trapResponseProvider')]
    public function test_it_extracts_a_trap_message_from_a_raw_routeros_response(array $rawResponse, ?string $expected): void
    {
        $this->assertSame($expected, RouterOsConnectionService::extractTrapMessage($rawResponse));
    }

    public static function trapResponseProvider(): array
    {
        return [
            // The client library (evilfreelancer/routeros-api-php) parses !trap and
            // !done identically and never throws on its own -- this is what a
            // rejected write (e.g. insufficient policy permissions) actually looks
            // like on the wire, and what a caller must check for explicitly.
            'permission-denied trap' => [
                ['!trap', '=message=no such command or not enough permissions to run the command', '!done'],
                'no such command or not enough permissions to run the command',
            ],
            'trap with no message line' => [
                ['!trap', '=category=2', '!done'],
                'RouterOS rejected this command (insufficient permissions or invalid parameters).',
            ],
            'clean done with no return value' => [
                ['!done'],
                null,
            ],
            'clean done with a returned id' => [
                ['!done', '=ret=*A'],
                null,
            ],
            'a normal !re row is not a trap' => [
                ['!re', '=name=ether1', '!done'],
                null,
            ],
            'empty response' => [
                [],
                null,
            ],
        ];
    }

    #[DataProvider('missingWalledGardenEntriesProvider')]
    public function test_it_filters_walled_garden_entries_down_to_what_is_missing(array $entries, array $existingHosts, array $expected): void
    {
        $this->assertSame($expected, RouterOsConnectionService::missingWalledGardenEntries($entries, $existingHosts));
    }

    public static function missingWalledGardenEntriesProvider(): array
    {
        $entries = [
            'Add walled-garden entry (portal)' => 'portal.example.com',
            'Add walled-garden entry (*.squadco.com)' => '*.squadco.com',
            'Add walled-garden entry (*.cloudflare.com)' => '*.cloudflare.com',
        ];

        return [
            'nothing on the router yet -- everything is missing' => [
                $entries,
                [],
                $entries,
            ],
            'everything already present -- nothing is missing' => [
                $entries,
                ['portal.example.com', '*.squadco.com', '*.cloudflare.com'],
                [],
            ],
            'one entry already present is skipped, others still added' => [
                $entries,
                ['*.cloudflare.com'],
                [
                    'Add walled-garden entry (portal)' => 'portal.example.com',
                    'Add walled-garden entry (*.squadco.com)' => '*.squadco.com',
                ],
            ],
            'an unrelated existing host does not mask a missing one' => [
                $entries,
                ['*.some-other-gateway.com'],
                $entries,
            ],
        ];
    }

    public function test_it_maps_dhcp_lease_rows_into_the_expected_shape(): void
    {
        $rows = [
            [
                'mac-address' => 'AA:BB:CC:DD:EE:FF',
                'active-address' => '10.10.10.5',
                'address' => '10.10.10.5',
                'host-name' => 'staff-phone',
                'status' => 'bound',
                'last-seen' => '2m1s',
                'server' => 'dhcp-mgmt',
            ],
            [
                'mac-address' => '11:22:33:44:55:66',
                'address' => '10.10.10.6',
                'status' => 'waiting',
            ],
            ['status' => 'bound'],
        ];

        $leases = RouterOsConnectionService::mapLeaseRows($rows, 'dhcp-mgmt');

        $this->assertSame([
            [
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'ip_address' => '10.10.10.5',
                'hostname' => 'staff-phone',
                'status' => 'bound',
                'last_seen' => '2m1s',
                'server' => 'dhcp-mgmt',
            ],
            [
                'mac_address' => '11:22:33:44:55:66',
                'ip_address' => '10.10.10.6',
                'hostname' => null,
                'status' => 'waiting',
                'last_seen' => null,
                'server' => 'dhcp-mgmt',
            ],
        ], $leases);
    }

    public function test_it_maps_file_print_rows_down_to_directory_names(): void
    {
        $rows = [
            ['name' => 'flash', 'type' => 'disk'],
            ['name' => 'flash/hotspot', 'type' => 'directory'],
            ['name' => 'hotspot', 'type' => 'directory'],
            ['name' => 'flash/hotspot/login.html', 'type' => 'html'],
            ['name' => 'skins', 'type' => 'directory'],
            ['type' => 'directory'],
        ];

        $directories = RouterOsConnectionService::mapDirectoryRows($rows);

        $this->assertSame(['flash/hotspot', 'hotspot', 'skins'], $directories);
    }

    #[DataProvider('resolveHotspotDirectoryProvider')]
    public function test_it_resolves_the_hotspot_directory(?string $input, string $expected): void
    {
        $this->assertSame($expected, RouterOsConnectionService::resolveHotspotDirectory($input));
    }

    public static function resolveHotspotDirectoryProvider(): array
    {
        return [
            'null falls back to the default' => [null, 'flash/hotspot'],
            'a custom directory is used as-is' => ['hotspot1', 'hotspot1'],
            'leading and trailing slashes are trimmed' => ['/hotspot/', 'hotspot'],
            'a router without the flash prefix is preserved' => ['hotspot', 'hotspot'],
        ];
    }

    public function test_candidate_hosts_for_wireguard_only(): void
    {
        $router = new Router([
            'tunnel_mode' => 'wireguard',
            'wireguard_internal_ip' => '10.8.0.10',
            'zerotier_ip' => null,
        ]);

        $this->assertSame(['10.8.0.10'], RouterOsConnectionService::candidateHosts($router));
    }

    public function test_candidate_hosts_for_zerotier_only(): void
    {
        $router = new Router([
            'tunnel_mode' => 'zerotier',
            'wireguard_internal_ip' => '10.8.0.10',
            'zerotier_ip' => '10.9.0.10',
        ]);

        $this->assertSame(['10.9.0.10'], RouterOsConnectionService::candidateHosts($router));
    }

    public function test_candidate_hosts_for_dual_mode_tries_wireguard_first(): void
    {
        $router = new Router([
            'tunnel_mode' => 'wireguard_zerotier',
            'wireguard_internal_ip' => '10.8.0.10',
            'zerotier_ip' => '10.9.0.10',
        ]);

        $this->assertSame(['10.8.0.10', '10.9.0.10'], RouterOsConnectionService::candidateHosts($router));
    }

    public function test_candidate_hosts_for_dual_mode_without_a_zerotier_ip_yet(): void
    {
        $router = new Router([
            'tunnel_mode' => 'wireguard_zerotier',
            'wireguard_internal_ip' => '10.8.0.10',
            'zerotier_ip' => null,
        ]);

        $this->assertSame(['10.8.0.10'], RouterOsConnectionService::candidateHosts($router));
    }
}
