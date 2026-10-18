<?php

namespace Tests\Unit;

use App\Services\StandaloneHotspotScriptGenerator;
use Tests\TestCase;

class StandaloneHotspotScriptGeneratorTest extends TestCase
{
    public function test_it_never_emits_wireguard_or_platform_api_user_lines(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config());

        $this->assertStringNotContainsString('/interface wireguard', $result['script']);
        $this->assertStringNotContainsString('mmsradius-api-group', $result['script']);
    }

    /**
     * 2026-10-18, confirmed live: hotspot login failed with "radius server
     * not responding" even with User Manager fully configured correctly --
     * the hotspot service's RADIUS requests to User Manager (127.0.0.1)
     * travel over the loopback interface, which the catch-all "!WAN drop"
     * rule also matches (loopback traffic isn't exempt from RouterOS's
     * input chain). Without an explicit accept rule ahead of it, every
     * voucher login attempt was silently dropped before ever reaching User
     * Manager.
     */
    public function test_loopback_traffic_is_accepted_before_the_catch_all_drop(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config());

        $loopbackPos = strpos($result['script'], 'chain=input in-interface=lo action=accept');
        $catchAllDropPos = strpos($result['script'], 'in-interface-list=!WAN action=drop');
        $this->assertNotFalse($loopbackPos);
        $this->assertNotFalse($catchAllDropPos);
        $this->assertLessThan($catchAllDropPos, $loopbackPos);
    }

    public function test_ros6_never_emits_radius_at_all(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config(['ros_version' => '6']));

        $this->assertStringNotContainsString('/radius add', $result['script']);
    }

    /**
     * 2026-10-18, confirmed live: every /user-manager line failed with "bad
     * command name user-manager" on a real router that simply never had the
     * separate User Manager package installed -- RouterOS 7's base install
     * doesn't include it. Since nothing in a pasted script can make a missing
     * package appear, the fix is a loud warning telling the admin to check/
     * install it first, surfaced both in the script's own header (read before
     * anything else) and again right above the User Manager section itself.
     */
    public function test_ros7_warns_that_user_manager_is_a_separate_package(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config(['ros_version' => '7']));

        $this->assertStringContainsString('REQUIRES the separate "user-manager" package', $result['script']);
        $this->assertStringContainsString('/system package print', $result['script']);
        $this->assertStringContainsString('bad command name user-manager', $result['script']);
    }

    public function test_ros6_never_mentions_the_user_manager_package(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config(['ros_version' => '6']));

        $this->assertStringNotContainsString('user-manager', $result['script']);
    }

    public function test_ros7_uses_user_manager_as_a_self_hosted_radius_server_only(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config(['ros_version' => '7']));

        $this->assertStringContainsString('/user-manager set enabled=yes', $result['script']);
        // use-profiles=yes is the documented precondition for limitation/profile/
        // profile-limitation objects to actually be enforced at all -- without it
        // every voucher would authenticate with no rate limit or validity window.
        $this->assertStringContainsString('/user-manager set enabled=yes use-profiles=yes', $result['script']);
        $this->assertStringContainsString('/user-manager router add address=127.0.0.1', $result['script']);
        $this->assertStringContainsString('/radius add address=127.0.0.1', $result['script']);
        $this->assertStringContainsString('use-radius=yes', $result['script']);
    }

    public function test_ros7_profiles_start_validity_at_first_login(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 10, 'upload_mbps' => 2, 'session_minutes' => 1440, 'shared_users' => 2, 'voucher_count' => 0, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertStringContainsString('/user-manager limitation add name="daily-limit" rate-limit-rx=2M rate-limit-tx=10M', $result['script']);
        $this->assertStringContainsString('/user-manager profile add name="daily" validity=1440m starts-when=first-auth', $result['script']);
        $this->assertStringContainsString('/user-manager profile-limitation add profile="daily" limitation="daily-limit"', $result['script']);
    }

    public function test_ros7_vouchers_become_user_manager_users(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 3, 'voucher_count' => 2, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertStringContainsString('/user-manager user add name="'.$voucher['username'].'" password="'.$voucher['password'].'" shared-users=3 comment="voucher-batch:daily"', $result['script']);
            $this->assertStringContainsString('/user-manager user-profile add user="'.$voucher['username'].'" profile="daily"', $result['script']);
        }

        // The same comment used on every user above is reused here to let
        // MikroTik's own built-in generate-voucher action pull exactly this
        // batch (one native printable sheet per profile, no re-typing names).
        $this->assertStringContainsString(
            '/user-manager user generate-voucher voucher-template=printable_vouchers.html [find comment="voucher-batch:daily"]',
            $result['script']
        );

        // 2026-10-18, confirmed live: the generated voucher sheet is served at a
        // fixed path on the router's own web server, overwritten on every run.
        $this->assertStringContainsString(
            'http://10.10.10.1/um/PRIVATE/GENERATED/vouchers/gen_printable_vouchers.html',
            $result['script']
        );
    }

    /**
     * 2026-10-18, direct request: generating a later voucher batch, or a new
     * tier entirely, shouldn't need a trip back to this wizard every time --
     * two small, persistent /system script objects are left on the router so
     * an admin can just edit one variable and re-run.
     */
    public function test_ros7_leaves_reusable_scripts_for_future_batches_and_profiles(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 10, 'upload_mbps' => 2, 'session_minutes' => 1440, 'shared_users' => 1, 'voucher_count' => 1, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertStringContainsString('/system script add name=generate-voucher-sheet source={', $result['script']);
        $this->assertStringContainsString(':local voucherComment "voucher-batch:daily"', $result['script']);
        $this->assertStringContainsString('/user-manager user generate-voucher voucher-template=printable_vouchers.html [find comment=$voucherComment]', $result['script']);
        $this->assertStringContainsString('http://10.10.10.1/um/PRIVATE/GENERATED/vouchers/gen_printable_vouchers.html', $result['script']);

        $this->assertStringContainsString('/system script add name=create-voucher-profile source={', $result['script']);
        $this->assertStringContainsString(':local profileName "daily"', $result['script']);
        $this->assertStringContainsString(':local downloadMbps 10', $result['script']);
        $this->assertStringContainsString(':local uploadMbps 2', $result['script']);
        $this->assertStringContainsString(':local validityMinutes 1440', $result['script']);
        $this->assertStringContainsString('/user-manager limitation add name=($profileName."-limit") rate-limit-rx=($uploadMbps."M") rate-limit-tx=($downloadMbps."M")', $result['script']);
        $this->assertStringContainsString('/user-manager profile add name=$profileName validity=($validityMinutes."m") starts-when=first-auth', $result['script']);
        $this->assertStringContainsString('/user-manager profile-limitation add profile=$profileName limitation=($profileName."-limit")', $result['script']);
    }

    public function test_ros7_reusable_scripts_use_generic_defaults_with_no_profiles_or_vouchers(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'profiles' => [],
        ]));

        $this->assertStringContainsString(':local voucherComment "voucher-batch:change-me"', $result['script']);
        $this->assertStringContainsString(':local profileName "new-profile"', $result['script']);
        $this->assertStringContainsString(':local downloadMbps 5', $result['script']);
        $this->assertStringContainsString(':local uploadMbps 5', $result['script']);
        $this->assertStringContainsString(':local validityMinutes 60', $result['script']);
    }

    public function test_ros6_never_gets_the_reusable_user_manager_scripts(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config(['ros_version' => '6']));

        $this->assertStringNotContainsString('generate-voucher-sheet', $result['script']);
        $this->assertStringNotContainsString('create-voucher-profile', $result['script']);
    }

    public function test_ros7_vouchers_from_different_profiles_get_separate_generate_voucher_batches(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 1, 'voucher_prefix' => '', 'voucher_code_length' => 8],
                ['name' => 'Weekly', 'download_mbps' => 10, 'upload_mbps' => 10, 'session_minutes' => 120, 'shared_users' => 1, 'voucher_count' => 1, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertStringContainsString(
            '/user-manager user generate-voucher voucher-template=printable_vouchers.html [find comment="voucher-batch:daily"]',
            $result['script']
        );
        $this->assertStringContainsString(
            '/user-manager user generate-voucher voucher-template=printable_vouchers.html [find comment="voucher-batch:weekly"]',
            $result['script']
        );
    }

    public function test_ros6_classic_hotspot_gets_an_on_login_expiry_script(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '6',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 10, 'upload_mbps' => 2, 'session_minutes' => 1440, 'shared_users' => 2, 'voucher_count' => 0, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertStringContainsString('/ip hotspot profile set hotspot-profile on-login=', $result['script']);
        $this->assertStringContainsString('/system scheduler add name=$sn interval=$st', $result['script']);
        $this->assertStringContainsString(
            '/ip hotspot user profile add name="daily" rate-limit=2M/10M session-timeout=86400s shared-users=2',
            $result['script']
        );
        $this->assertStringNotContainsString('starts-when', $result['script']);
    }

    public function test_ros6_vouchers_become_classic_hotspot_users(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '6',
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 2, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertStringContainsString('/ip hotspot user add name="'.$voucher['username'].'" password="'.$voucher['password'].'" profile="daily"', $result['script']);
        }
    }

    public function test_ros7_wireless_uses_wifiwave2_syntax(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'has_wireless' => true,
            'wireless_interface' => 'wifi1',
        ]));

        $this->assertStringContainsString('/interface wifi security add name=hotspot-open', $result['script']);
        $this->assertStringContainsString('[find default-name=wifi1]', $result['script']);
        $this->assertStringNotContainsString('/interface wireless ', $result['script']);

        // 2026-10-18, confirmed live: a factory-default wireless interface is
        // administratively disabled, and RouterOS can reject the configuration=
        // change below on a still-disabled interface even with disabled=no in the
        // same `set` -- most routers are reset before this script is ever pasted,
        // so this has to be its own command, before the `set` line, not relied on
        // via the inline disabled=no alone.
        $enablePos = strpos($result['script'], '/interface wifi enable [find default-name=wifi1]');
        $setPos = strpos($result['script'], '/interface wifi set [find default-name=wifi1] configuration=hotspot-open-cfg');
        $this->assertNotFalse($enablePos);
        $this->assertNotFalse($setPos);
        $this->assertLessThan($setPos, $enablePos);

        // 2026-10-18, confirmed live on two different physical routers: even with
        // the enable+set above both succeeding with no error, the very next
        // command referencing this interface by its literal name still failed --
        // enabling a radio isn't instantaneous, RouterOS returns control before
        // the driver has actually finished bringing it up. A fixed delay (the
        // same fix already used elsewhere in this codebase for an identical
        // ZeroTier interface-registration race) has to sit between the set line
        // and the bridge-port-add that references the interface directly.
        $delayPos = strpos($result['script'], ':delay 3s');
        $bridgePortPos = strpos($result['script'], '/interface bridge port add bridge=bridge-hotspot interface=wifi1');
        $this->assertNotFalse($delayPos);
        $this->assertNotFalse($bridgePortPos);
        $this->assertGreaterThan($setPos, $delayPos);
        $this->assertLessThan($bridgePortPos, $delayPos);
    }

    /**
     * 2026-10-18, confirmed live on an RB951Ui (Atheros AR9300 chip): the
     * newer wifi/wifiwave2 package only runs on specific ARM chips, so this
     * router's "wifi1" never existed under any package -- /interface
     * wireless print confirmed the real interface was "wlan1" under the
     * legacy driver all along, despite running RouterOS 7. ros_version
     * alone can't capture this -- wireless_driver is a separate, explicit
     * choice.
     */
    public function test_ros7_with_legacy_wireless_driver_uses_legacy_syntax(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '7',
            'has_wireless' => true,
            'wireless_driver' => 'legacy',
            'wireless_interface' => 'wlan1',
        ]));

        $this->assertStringContainsString('/interface wireless security-profiles add name=hotspot-open mode=none', $result['script']);
        $this->assertStringContainsString('/interface wireless set wlan1 mode=ap-bridge', $result['script']);
        $this->assertStringContainsString('/interface wireless enable wlan1', $result['script']);
        $this->assertStringNotContainsString('/interface wifi ', $result['script']);

        // The User Manager section is still RouterOS-7-specific regardless of
        // which wireless driver this hardware uses -- the two are independent.
        $this->assertStringContainsString('/user-manager set enabled=yes use-profiles=yes', $result['script']);
    }

    public function test_ros6_wireless_uses_legacy_syntax(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'ros_version' => '6',
            'has_wireless' => true,
            'wireless_interface' => 'wlan1',
        ]));

        $this->assertStringContainsString('/interface wireless security-profiles add name=hotspot-open mode=none', $result['script']);
        $this->assertStringContainsString('/interface wireless set wlan1 mode=ap-bridge', $result['script']);
        $this->assertStringNotContainsString('/interface wifi ', $result['script']);

        $enablePos = strpos($result['script'], '/interface wireless enable wlan1');
        $setPos = strpos($result['script'], '/interface wireless set wlan1 mode=ap-bridge');
        $this->assertNotFalse($enablePos);
        $this->assertNotFalse($setPos);
        $this->assertLessThan($setPos, $enablePos);

        $delayPos = strpos($result['script'], ':delay 3s');
        $bridgePortPos = strpos($result['script'], '/interface bridge port add bridge=bridge-hotspot interface=wlan1');
        $this->assertNotFalse($delayPos);
        $this->assertNotFalse($bridgePortPos);
        $this->assertGreaterThan($setPos, $delayPos);
        $this->assertLessThan($bridgePortPos, $delayPos);
    }

    public function test_wired_only_router_skips_wireless_and_notes_external_ap(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'has_wireless' => false,
        ]));

        $this->assertStringNotContainsString('/interface wifi', $result['script']);
        $this->assertStringNotContainsString('/interface wireless', $result['script']);
        $this->assertStringContainsString('external access point', $result['script']);
    }

    public function test_ethernet_port_count_bridges_every_port_except_wan(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'wan_port' => 'ether3',
            'ethernet_port_count' => 5,
        ]));

        foreach (['ether1', 'ether2', 'ether4', 'ether5'] as $port) {
            $this->assertStringContainsString('/interface bridge port add bridge=bridge-hotspot interface='.$port, $result['script']);
        }
        $this->assertStringNotContainsString('/interface bridge port add bridge=bridge-hotspot interface=ether3', $result['script']);
    }

    public function test_router_identity_and_hotspot_name_are_independent(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'router_identity' => 'Bebeji-Router01',
            'hotspot_name' => 'Bebeji Free Wi-Fi',
        ]));

        $this->assertStringContainsString('/system identity set name="Bebeji-Router01"', $result['script']);
        $this->assertStringContainsString('ssid="Bebeji Free Wi-Fi"', $result['script']);
        $this->assertStringNotContainsString('/system identity set name="Bebeji Free Wi-Fi"', $result['script']);
    }

    public function test_management_network_adds_isolated_bridge_and_wpa2_ssid_when_wireless(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'has_wireless' => true,
            'ros_version' => '7',
            'wireless_interface' => 'wifi1',
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
            'mgmt_password' => 'super-secret-1',
        ]));

        $this->assertStringContainsString('bridge-mgmt', $result['script']);
        $this->assertStringContainsString('passphrase="super-secret-1"', $result['script']);
        $this->assertStringContainsString('Hotspot clients cannot reach the management network', $result['script']);

        // 2026-10-18, confirmed live: src-address takes an IP/CIDR, never an
        // interface name -- RouterOS rejected the original src-address=bridge-hotspot
        // outright. in-interface is the correct property for "traffic that
        // entered on this bridge."
        $this->assertStringContainsString(
            '/ip firewall filter add chain=forward in-interface=bridge-hotspot dst-address=10.10.20.0/24 action=drop',
            $result['script']
        );
        $this->assertStringNotContainsString('src-address=bridge-hotspot', $result['script']);

        // 2026-10-18, confirmed live: RouterOS evaluates filter rules top to
        // bottom and action=drop stops processing right there -- the mgmt
        // accept rule has to come BEFORE the catch-all "!WAN drop" rule, or
        // it's dead code, never actually reached. The original ordering
        // silently dropped every management-network request at the firewall
        // before it ever reached the www service -- indistinguishable in a
        // browser from the router being completely unreachable.
        $mgmtAcceptPos = strpos($result['script'], 'Allow the management network to reach the router');
        $catchAllDropPos = strpos($result['script'], 'in-interface-list=!WAN action=drop');
        $this->assertNotFalse($mgmtAcceptPos);
        $this->assertNotFalse($catchAllDropPos);
        $this->assertLessThan($catchAllDropPos, $mgmtAcceptPos);

        // The virtual management SSID attaches to the physical radio as its
        // master-interface= -- that radio has to be enabled first too, same
        // factory-default-disabled reasoning as the hotspot SSID's own interface.
        $this->assertStringContainsString('/interface wifi enable [find default-name=wifi1]', $result['script']);

        // 2026-10-18, confirmed live: master-interface=wifi1 below is also a
        // literal-name reference needing the same registration-race delay as
        // the hotspot section's own bridge-port-add -- this method adds its own
        // rather than trusting wirelessLines() always ran recently enough.
        $firstEnablePos = strpos($result['script'], '/interface wifi enable [find default-name=wifi1]');
        $secondEnablePos = strpos($result['script'], '/interface wifi enable [find default-name=wifi1]', $firstEnablePos + 1);
        $masterInterfacePos = strpos($result['script'], 'master-interface=wifi1');
        $this->assertNotFalse($secondEnablePos);
        $this->assertNotFalse($masterInterfacePos);
        $delayBetween = strpos($result['script'], ':delay 3s', $secondEnablePos);
        $this->assertNotFalse($delayBetween);
        $this->assertGreaterThan($secondEnablePos, $delayBetween);
        $this->assertLessThan($masterInterfacePos, $delayBetween);
    }

    public function test_management_network_locks_router_admin_services_to_its_subnet(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
        ]));

        $this->assertStringContainsString('/ip service set www address=10.10.20.0/24', $result['script']);
        $this->assertStringContainsString('/ip service set www-ssl address=10.10.20.0/24', $result['script']);
        $this->assertStringContainsString('/ip service set winbox address=10.10.20.0/24', $result['script']);
        $this->assertStringContainsString('/ip service set ssh address=10.10.20.0/24', $result['script']);
    }

    public function test_management_network_disabled_omits_mgmt_bridge_and_service_lockdown(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'enable_mgmt_network' => false,
        ]));

        $this->assertStringNotContainsString('bridge-mgmt', $result['script']);
        $this->assertStringNotContainsString('/ip service set www', $result['script']);
        $this->assertStringContainsString('Management network is disabled', $result['script']);
    }

    public function test_management_network_on_wired_only_router_skips_ssid_and_notes_dedicated_port(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'has_wireless' => false,
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
            'mgmt_password' => '',
        ]));

        $this->assertStringContainsString('bridge-mgmt', $result['script']);
        $this->assertStringContainsString('Wire a dedicated port to bridge-mgmt', $result['script']);
        $this->assertStringNotContainsString('security-profile=mgmt-sec', $result['script']);
        $this->assertStringNotContainsString('security=mgmt-sec', $result['script']);
    }

    public function test_voucher_codes_are_generated_per_profile_and_unique(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 5, 'voucher_prefix' => '', 'voucher_code_length' => 8],
                ['name' => 'Weekly', 'download_mbps' => 8, 'upload_mbps' => 8, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 3, 'voucher_prefix' => '', 'voucher_code_length' => 8],
            ],
        ]));

        $this->assertCount(8, $result['vouchers']);
        $this->assertCount(5, array_filter($result['vouchers'], fn ($v) => $v['profile'] === 'Daily'));
        $this->assertCount(3, array_filter($result['vouchers'], fn ($v) => $v['profile'] === 'Weekly'));

        $usernames = array_column($result['vouchers'], 'username');
        $this->assertSame($usernames, array_unique($usernames));
    }

    public function test_voucher_prefix_and_code_length_are_respected(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 4, 'voucher_prefix' => 'DLY-', 'voucher_code_length' => 6],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertStringStartsWith('DLY-', $voucher['username']);
            $this->assertSame(10, strlen($voucher['username']));
        }
    }

    public function test_username_and_password_are_the_same_by_default(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 3, 'voucher_prefix' => '', 'voucher_code_length' => 8, 'voucher_username_password_same' => true],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertSame($voucher['username'], $voucher['password']);
        }
    }

    public function test_username_and_password_can_be_generated_independently(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 5, 'voucher_prefix' => '', 'voucher_code_length' => 8, 'voucher_username_password_same' => false],
            ],
        ]));

        $this->assertNotEmpty($result['vouchers']);

        foreach ($result['vouchers'] as $voucher) {
            $this->assertNotSame($voucher['username'], $voucher['password']);
            $this->assertSame(8, strlen($voucher['password']));
        }
    }

    public function test_voucher_character_set_numeric_only_produces_digits(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 5, 'voucher_prefix' => '', 'voucher_code_length' => 8, 'voucher_character_set' => 'numeric', 'voucher_username_password_same' => false],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertMatchesRegularExpression('/^\d+$/', $voucher['username']);
            $this->assertMatchesRegularExpression('/^\d+$/', $voucher['password']);
        }
    }

    public function test_voucher_character_set_alpha_only_produces_letters(): void
    {
        $result = (new StandaloneHotspotScriptGenerator)->generate($this->config([
            'profiles' => [
                ['name' => 'Daily', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 5, 'voucher_prefix' => '', 'voucher_code_length' => 8, 'voucher_character_set' => 'alpha_safe'],
            ],
        ]));

        foreach ($result['vouchers'] as $voucher) {
            $this->assertMatchesRegularExpression('/^[A-Z]+$/', $voucher['username']);
        }
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'router_identity' => 'Test Router',
            'hotspot_name' => 'Test Hotspot',
            'ros_version' => '7',
            'has_wireless' => true,
            'wan_port' => 'ether1',
            'ethernet_port_count' => 5,
            'wireless_interface' => 'wifi1',
            'hotspot_network' => '10.10.10.1/24',
            'enable_mgmt_network' => true,
            'mgmt_network' => '10.10.20.1/24',
            'mgmt_password' => 'password123',
            'profiles' => [
                ['name' => '1 Hour', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 2, 'voucher_prefix' => '', 'voucher_code_length' => 8, 'voucher_username_password_same' => true, 'voucher_character_set' => 'alnum_safe'],
            ],
        ], $overrides);
    }
}
