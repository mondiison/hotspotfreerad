<?php

namespace Tests\Unit;

use App\Services\StandalonePppoeScriptGenerator;
use Tests\TestCase;

class StandalonePppoeScriptGeneratorTest extends TestCase
{
    public function test_starlink_uplink_gets_dhcp_nat_and_a_wan_list(): void
    {
        $script = $this->generate();

        $this->assertStringContainsString('/interface list member add list=WAN interface=ether1', $script);
        $this->assertStringContainsString('/ip dhcp-client add interface=ether1 add-default-route=yes use-peer-dns=no disabled=no', $script);
        $this->assertStringContainsString('/ip firewall nat add chain=srcnat out-interface=ether1 action=masquerade', $script);
    }

    /**
     * The parent queue caps all client traffic at 90% of the Starlink speeds
     * so queueing happens on the router, not in Starlink's own buffers.
     */
    public function test_parent_queue_caps_clients_at_ninety_percent_of_starlink(): void
    {
        $script = $this->generate(['wan_download_mbps' => 100, 'wan_upload_mbps' => 15]);

        $this->assertStringContainsString('/queue simple add name=starlink-total target=10.20.0.0/24 max-limit=13500k/90M', $script);
    }

    /**
     * Each plan is a PPP profile whose rate-limit becomes the client's own
     * queue under starlink-total: capped at the plan, shared evenly when full.
     */
    public function test_plans_become_ppp_profiles_under_the_parent_queue(): void
    {
        $script = $this->generate();

        $this->assertStringContainsString(
            '/ppp profile add name="Basic 5M" local-address=10.20.0.1 remote-address=pool-pppoe dns-server=10.20.0.1 rate-limit=2M/5M parent-queue=starlink-total only-one=yes change-tcp-mss=yes',
            $script
        );
        $this->assertLessThan(strpos($script, '/ppp profile add'), strpos($script, '/queue simple add name=starlink-total'));
    }

    public function test_pppoe_server_runs_on_a_bridge_of_the_pppoe_ports_with_the_first_plan_as_default(): void
    {
        $script = $this->generate();

        $this->assertStringContainsString('/interface bridge port add bridge=bridge-pppoe interface=ether2', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=bridge-pppoe interface=ether3', $script);
        $this->assertStringContainsString('/ip pool add name=pool-pppoe ranges=10.20.0.10-10.20.0.254', $script);
        $this->assertStringContainsString(
            '/interface pppoe-server server add service-name="internet" interface=bridge-pppoe default-profile="Basic 5M" authentication=pap,chap,mschap1,mschap2 one-session-per-host=yes keepalive-timeout=60 disabled=no',
            $script
        );
        $this->assertStringContainsString('# /ppp secret add name=client1 password=ChangeMe service=pppoe profile="Basic 5M"', $script);
    }

    public function test_fasttrack_is_removed_so_traffic_cannot_skip_the_queues(): void
    {
        $this->assertStringContainsString('/ip firewall filter remove [find action=fasttrack-connection]', $this->generate());
    }

    /**
     * Devices on plain LAN ports (e.g. a client's own router) get a per-device
     * PCQ cap and an equal share, inside the same Starlink parent queue.
     */
    public function test_plain_lan_devices_share_fairly_with_a_per_device_cap(): void
    {
        $script = $this->generate([
            'port_roles' => ['ether2' => 'pppoe', 'ether3' => 'lan', 'ether4' => 'lan', 'ether5' => 'mgmt'],
            'lan_device_download_mbps' => 10,
            'lan_device_upload_mbps' => 2.5,
        ]);

        $this->assertStringContainsString('/interface bridge port add bridge=bridge-lan interface=ether3', $script);
        $this->assertStringContainsString('/queue type add name=pcq-lan-download kind=pcq pcq-classifier=dst-address pcq-rate=10M', $script);
        $this->assertStringContainsString('/queue type add name=pcq-lan-upload kind=pcq pcq-classifier=src-address pcq-rate=2500k', $script);
        $this->assertStringContainsString('/queue simple add name=lan-devices parent=starlink-total target=192.168.88.0/24 queue=pcq-lan-upload/pcq-lan-download', $script);
        $this->assertStringContainsString('target=10.20.0.0/24,192.168.88.0/24', $script);
    }

    public function test_management_port_locks_admin_services_and_is_unreachable_from_clients(): void
    {
        $script = $this->generate();

        $this->assertStringContainsString('/interface bridge port add bridge=bridge-mgmt interface=ether5', $script);
        $this->assertStringContainsString('/ip service set winbox address=10.10.20.0/24', $script);
        $this->assertStringContainsString('/ip firewall filter add chain=forward src-address=10.20.0.0/24 dst-address=10.10.20.0/24 action=drop', $script);
        $this->assertStringNotContainsString('No management port', $script);
    }

    public function test_without_a_management_port_admin_is_allowed_from_client_networks_only(): void
    {
        $script = $this->generate(['port_roles' => ['ether2' => 'pppoe', 'ether3' => 'pppoe', 'ether4' => 'pppoe', 'ether5' => 'pppoe']]);

        $this->assertStringNotContainsString('bridge-mgmt', $script);
        $this->assertStringNotContainsString('/ip service set', $script);
        $this->assertStringContainsString('/ip firewall filter add chain=input in-interface-list=!WAN action=accept comment="No management port', $script);
    }

    public function test_router_drops_other_input_and_unsolicited_starlink_traffic(): void
    {
        $script = $this->generate();

        $this->assertStringContainsString('/ip firewall filter add chain=input src-address=10.20.0.0/24 protocol=udp dst-port=53 action=accept', $script);
        $this->assertStringContainsString('/ip firewall filter add chain=input action=drop comment="Drop all other traffic to the router"', $script);
        $this->assertStringContainsString('/ip firewall filter add chain=forward in-interface-list=WAN connection-nat-state=!dstnat action=drop', $script);
        $this->assertLessThan(
            strpos($script, 'chain=input action=drop comment="Drop all other'),
            strpos($script, 'chain=input src-address=10.20.0.0/24 protocol=udp dst-port=53 action=accept')
        );
    }

    public function test_unused_ports_are_left_alone(): void
    {
        $script = $this->generate(['port_roles' => ['ether2' => 'pppoe', 'ether3' => 'unused', 'ether4' => 'unused', 'ether5' => 'mgmt']]);

        $this->assertStringNotContainsString('interface=ether3', $script);
        $this->assertStringNotContainsString('interface=ether4', $script);
    }

    private function generate(array $overrides = []): string
    {
        return (new StandalonePppoeScriptGenerator)->generate(array_merge([
            'router_identity' => 'Village Starlink',
            'wan_port' => 'ether1',
            'wan_download_mbps' => 100,
            'wan_upload_mbps' => 15,
            'port_roles' => ['ether2' => 'pppoe', 'ether3' => 'pppoe', 'ether4' => 'pppoe', 'ether5' => 'mgmt'],
            'pppoe_network' => '10.20.0.1/24',
            'pppoe_service_name' => 'internet',
            'lan_network' => '192.168.88.1/24',
            'lan_device_download_mbps' => 10,
            'lan_device_upload_mbps' => 3,
            'mgmt_network' => '10.10.20.1/24',
            'plans' => [
                ['name' => 'Basic 5M', 'download_mbps' => 5, 'upload_mbps' => 2],
                ['name' => 'Standard 10M', 'download_mbps' => 10, 'upload_mbps' => 3],
            ],
        ], $overrides));
    }
}
