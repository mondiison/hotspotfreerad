<?php

namespace App\Services;

/**
 * Generates a self-contained MikroTik RouterOS hotspot configuration that
 * does not depend on this platform at all -- no WireGuard, no HotspotFreeRAD
 * RADIUS server, no Router/Shop/Tenant record. Voucher validity is designed
 * to start counting from a customer's FIRST login, not from when the voucher
 * was created:
 *
 *  - RouterOS 7 uses the built-in User Manager (`/user-manager`) as a
 *    self-hosted local RADIUS server (pointed at 127.0.0.1 -- never leaves
 *    the router), whose `profile.starts-when=first-auth` is the officially
 *    documented way to do this. This is a materially different, richer
 *    object model than classic hotspot users (profile / limitation /
 *    profile-limitation / user-profile / user are five distinct objects),
 *    and it's what RouterOS 7 is designed for.
 *  - RouterOS 6 uses its own User Manager package (`/tool user-manager`) as
 *    the local RADIUS server too, but the script only connects the hotspot
 *    to it (owner, router entry, /radius). Users, profiles and limitations
 *    are created by the operator in the /userman web interface, so the
 *    wizard's profiles and vouchers don't apply on v6.
 *
 * Meant for the standalone "Script Generator" tool (super admin only) so
 * operators can configure any MikroTik -- with or without built-in
 * wireless, RouterOS 6 or 7 -- for a small hotspot business that isn't (or
 * isn't yet) a HotspotFreeRAD tenant.
 */
class StandaloneHotspotScriptGenerator
{
    /**
     * Character sets a voucher's username/password can be drawn from, mirroring
     * MikroTik's own batch-user dialog (username-characters/password-characters).
     * "safe" excludes characters easily confused when handwritten or misread off
     * a receipt (0/O, 1/I/l).
     */
    public const CHARACTER_SETS = [
        'alnum_safe' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'numeric' => '0123456789',
        'alpha_safe' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'alnum_full' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
    ];

    /**
     * 2026-10-18, confirmed live: generate-voucher's output filename is
     * "gen_" plus the template's own filename, not a fixed name -- a prior
     * version of this generator hardcoded "gen_tickets.html" from an earlier,
     * unverified report; checking the actual generated file on a real router
     * found it named "gen_printable_vouchers.html" under "um5files/PRIVATE/
     * GENERATED/vouchers/" instead. Kept as one constant so the template name
     * and the derived output filename/URL can never drift apart again.
     */
    public const VOUCHER_TEMPLATE = 'printable_vouchers.html';

    /**
     * 2026-10-18, direct request: a per-profile total-transfer cap (RouterOS
     * 7 User Manager's /user-manager limitation `transfer-limit`, bytes),
     * offered as fixed presets rather than a free-typed byte value -- "left
     * blank it should be unlimited." Byte values use this codebase's own
     * existing binary-unit convention (docs/roles-and-product-direction.md:
     * 1GB = 1073741824), not decimal MB/GB, for consistency with the main
     * app's own data-cap presets elsewhere. "Unlimited" maps to `null`, which
     * both the wizard's own <select> (blank option value) and
     * userManagerLines()/voucherUtilityScriptLines() below treat identically
     * to 0 -- transfer-limit's own documented default -- rather than ever
     * omitting the property conditionally: MikroTik's docs confirm the
     * default is 0 but don't separately state what an explicit 0 does, so
     * this emits it as a literal, always-present value in both the main
     * generated lines and the reusable create-voucher-profile script (which,
     * being a static RouterOS script rather than PHP, would need real
     * conditional-string-building to omit a property dynamically) --
     * deliberately not confirmed against real hardware yet, flagged in both
     * call sites' own comments.
     */
    /**
     * Binary MB -> bytes, matching this file's own existing byte-math
     * convention (1GB = 1073741824, docs/roles-and-product-direction.md).
     * Used to convert create-voucher-profile's user-friendly megabyte
     * variable to the raw bytes RouterOS's own transfer-limit= expects.
     */
    public const BYTES_PER_MB = 1048576;

    public const DATA_LIMIT_PRESETS = [
        'Unlimited' => null,
        '200 MB' => 209715200,
        '300 MB' => 314572800,
        '500 MB' => 524288000,
        '800 MB' => 838860800,
        '1 GB' => 1073741824,
        '1.5 GB' => 1610612736,
        '2 GB' => 2147483648,
        '3 GB' => 3221225472,
        '5 GB' => 5368709120,
        '10 GB' => 10737418240,
    ];

    /**
     * @param  array{
     *     router_identity: string,
     *     hotspot_name: string,
     *     ros_version: string,
     *     has_wireless: bool,
     *     wan_port: string,
     *     ethernet_port_count: int,
     *     wireless_interface: string,
     *     wireless_driver?: string,
     *     hotspot_network: string,
     *     enable_mgmt_network: bool,
     *     mgmt_network: string,
     *     mgmt_password: string,
     *     profiles: list<array{name: string, download_mbps: int, upload_mbps: int, session_minutes: int, shared_users: int, data_limit_bytes?: ?int, voucher_count: int, voucher_prefix: string, voucher_code_length: int, voucher_username_password_same: bool, voucher_character_set: string}>,
     * } $config
     * @return array{script: string, vouchers: list<array{profile: string, username: string, password: string}>, user_manager_voucher_template: ?string}
     */
    public function generate(array $config): array
    {
        // RouterOS 6 users, profiles and limitations are created by the operator in
        // the User Manager web interface (/userman), so no vouchers are generated.
        $vouchers = $config['ros_version'] === '7' ? $this->generateVoucherCodes($config['profiles']) : [];

        $lines = array_merge(
            $this->headerLines($config),
            $this->identityAndBridgeLines($config),
            $this->wanLines($config),
            $this->hotspotNetworkLines($config),
            $this->wirelessLines($config),
            $this->managementNetworkLines($config),
            $this->firewallLines($config),
            $this->userSystemLines($config, $vouchers),
        );

        return [
            'script' => implode("\n", array_filter($lines, fn ($line) => $line !== null)),
            'vouchers' => $vouchers,
            'user_manager_voucher_template' => $config['ros_version'] === '6' ? $this->userManagerV6VoucherTemplate($config['hotspot_name']) : null,
        ];
    }

    /**
     * The operator's standard RouterOS 6 User Manager voucher template, with
     * the vendor name set to the hotspot SSID. It is pasted into /userman
     * (Settings > Templates) by hand -- v6 User Manager has no command-line
     * way to set a voucher template. %u_...% are User Manager's own placeholders.
     */
    public function userManagerV6VoucherTemplate(string $ssid): string
    {
        $vendor = htmlspecialchars(trim($ssid), ENT_QUOTES, 'UTF-8');

        return '<table style="display: inline-block; width: 240px; border: 1px solid #ccc; line-height: 110%; font-family: arial; font-size: 9px; margin: 1px;">'
            .'<tbody>'
            .'<tr><td style="text-align: center; color: red; font-size: 12px; border-bottom: 1px #ccc solid;"><b>'.$vendor.' | %u_actualProfileName%</b></td></tr>'
            .'<tr><td><table style="text-align: center; width: 100%; background-color: #fff; line-height: 110%; font-size: 8px;"><tbody><tr style="background-color: #eee;"></tr></tbody></table></td></tr>'
            .'<tr><td><table style="text-align: center; width: 240px; background-color: #fff; line-height: 110%; font-size: 14px; border-top: 1px solid #ccc;"><tbody>'
            .'<tr style="background-color: #fff;"><td style="color: #888; border: 2px #ccc solid;">%u_username%</td><td style="color: #888; border: 2px #ccc solid;">%u_password%</td></tr>'
            .'</tbody></table></td></tr>'
            .'</tbody>'
            .'</table>';
    }

    /**
     * @return list<string>
     */
    private function headerLines(array $config): array
    {
        $lines = [
            '# Standalone MikroTik hotspot script -- generated by HotspotFreeRAD Script Generator',
            '# RouterOS '.$config['ros_version'].' / '.($config['has_wireless'] ? 'built-in wireless' : 'wired-only').' router',
            '# This script is self-contained: it does not use this platform\'s RADIUS, WireGuard, or any HotspotFreeRAD account.',
            '# Review port names (WAN/LAN) against your actual router before pasting.',
        ];

        if ($config['ros_version'] === '7') {
            // 2026-10-18, confirmed live: "bad command name user-manager" on every
            // single /user-manager line -- User Manager is a SEPARATE, OPTIONAL
            // RouterOS 7 package (user-manager-<version>-<arch>.npk), not part of
            // the base install. Run "/system package print" first to check; if
            // it's missing, download the matching package for your RouterOS
            // version/architecture from mikrotik.com/download, upload it via
            // Winbox > Files, then reboot before pasting the User Manager section
            // below -- otherwise every /user-manager line in this script fails.
            $lines[] = '# RouterOS 7 User Manager voucher section below REQUIRES the separate "user-manager" package to be installed first --';
            $lines[] = '# check with "/system package print"; if it is missing, install it from mikrotik.com/download and reboot before pasting.';
        } else {
            // RouterOS 6 User Manager is also a separate package
            // (user-manager-<version>-<arch>.npk); /tool user-manager lines fail
            // with "bad command name" without it.
            $lines[] = '# Requires RouterOS 6.40 or newer -- check with "/system resource print" and upgrade older routers first.';
            $lines[] = '# RouterOS 6 User Manager section below REQUIRES the separate "user-manager" package to be installed first --';
            $lines[] = '# check with "/system package print"; if it is missing, install it from mikrotik.com/download and reboot before pasting.';
            $lines[] = '# This script creates the User Manager profiles and limitations. Add users afterwards in the User Manager web interface: http://<router-ip>/userman';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function identityAndBridgeLines(array $config): array
    {
        $lines = [
            '/system identity set name="'.$this->quote($config['router_identity']).'"',
            '/interface bridge add name=bridge-hotspot comment="HotspotFreeRAD standalone hotspot bridge"',
        ];

        foreach ($this->lanPorts($config['wan_port'], (int) $config['ethernet_port_count']) as $port) {
            $lines[] = '/interface bridge port add bridge=bridge-hotspot interface='.$port;
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wanLines(array $config): array
    {
        return [
            '/interface list add name=WAN comment="Internet uplink"',
            '/interface list member add list=WAN interface='.$config['wan_port'],
            '/ip dhcp-client remove [find interface='.$config['wan_port'].']',
            '/ip dhcp-client add interface='.$config['wan_port'].' add-default-route=yes disabled=no',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function hotspotNetworkLines(array $config): array
    {
        $network = $this->networkParts($config['hotspot_network']);

        return [
            '/ip address add address='.$network['gateway'].'/24 interface=bridge-hotspot',
            '/ip pool add name=pool-hotspot ranges='.$network['range'],
            '/ip dhcp-server add name=dhcp-hotspot interface=bridge-hotspot address-pool=pool-hotspot lease-time=30m disabled=no',
            '/ip dhcp-server network add address='.$network['cidr'].' gateway='.$network['host'].' dns-server=1.1.1.1,8.8.8.8',
            '/ip dns set allow-remote-requests=yes servers=1.1.1.1,8.8.8.8',
            '/ip hotspot profile add name=hotspot-profile hotspot-address='.$network['host'].' dns-name=hotspot.local login-by=http-chap,http-pap,cookie use-radius=yes',
            '/ip hotspot add name=hotspot1 interface=bridge-hotspot address-pool=pool-hotspot profile=hotspot-profile disabled=no',
            '/ip firewall nat add chain=srcnat out-interface='.$config['wan_port'].' action=masquerade',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function wirelessLines(array $config): array
    {
        if (! $config['has_wireless']) {
            return [
                '# No built-in wireless -- attach an external access point to a LAN port broadcasting an open SSID for the hotspot.',
                '',
            ];
        }

        $interface = $config['wireless_interface'];
        $ssid = $this->quote($config['hotspot_name']);

        if ($this->usesLegacyWireless($config)) {
            return [
                '/interface wireless security-profiles add name=hotspot-open mode=none',
                // 2026-10-18, confirmed live: a factory-default wireless interface is
                // administratively disabled, and RouterOS can reject the mode=/ssid=
                // change below on a still-disabled interface even with disabled=no
                // in the same `set` command -- the same fix StandalonePtpScriptGenerator
                // already needed for the identical reason. Enable it as its own
                // command first; most routers are reset before this script is ever
                // pasted, so this interface starts disabled far more often than not.
                '/interface wireless enable '.$interface,
                '/interface wireless set '.$interface.' mode=ap-bridge ssid="'.$ssid.'" security-profile=hotspot-open disabled=no',
                // Harmless defensive wait, kept from an earlier (disproven, for the
                // legacy driver specifically) theory that this was purely a
                // registration race -- see usesLegacyWireless()'s own docblock for
                // what the real RB951Ui failure turned out to be instead.
                ':delay 3s',
                '/interface bridge port add bridge=bridge-hotspot interface='.$interface,
                '',
            ];
        }

        return [
            '/interface wifi security add name=hotspot-open authentication-types=""',
            '/interface wifi configuration add name=hotspot-open-cfg mode=ap ssid="'.$ssid.'" security=hotspot-open',
            // Same reasoning as the RouterOS 6 branch above.
            '/interface wifi enable [find default-name='.$interface.']',
            '/interface wifi set [find default-name='.$interface.'] configuration=hotspot-open-cfg disabled=no',
            // Same registration-race reasoning as the RouterOS 6 branch above.
            ':delay 3s',
            '/interface bridge port add bridge=bridge-hotspot interface='.$interface,
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function managementNetworkLines(array $config): array
    {
        if (! $config['enable_mgmt_network']) {
            return [
                '# Management network is disabled -- administer this router over its main LAN/WAN address only.',
                '',
            ];
        }

        $network = $this->networkParts($config['mgmt_network']);
        $lines = [
            '/interface bridge add name=bridge-mgmt comment="HotspotFreeRAD standalone management bridge, isolated from hotspot clients"',
            '/ip address add address='.$network['gateway'].'/24 interface=bridge-mgmt',
            '/ip pool add name=pool-mgmt ranges='.$network['range'],
            '/ip dhcp-server add name=dhcp-mgmt interface=bridge-mgmt address-pool=pool-mgmt lease-time=8h disabled=no',
            '/ip dhcp-server network add address='.$network['cidr'].' gateway='.$network['host'].' dns-server=1.1.1.1,8.8.8.8',
        ];

        if (! $config['has_wireless']) {
            $lines[] = '# Wire a dedicated port to bridge-mgmt for management access (this router has no built-in wireless for a management SSID).';
        } else {
            $interface = $config['wireless_interface'].'-mgmt';
            $password = $this->quote($config['mgmt_password']);

            // 2026-10-18: the master physical radio itself has to be enabled before
            // a virtual AP interface can attach to it as master-interface= -- this
            // already happens earlier in wirelessLines() (so re-enabling here is
            // normally a harmless no-op), but repeated defensively since a factory-
            // reset router starts with it disabled and this method shouldn't silently
            // depend on another private method's execution order to work correctly.
            // The :delay lines are a harmless defensive wait kept from an earlier
            // theory -- see usesLegacyWireless()'s own docblock.
            if ($this->usesLegacyWireless($config)) {
                $lines[] = '/interface wireless enable '.$config['wireless_interface'];
                $lines[] = ':delay 3s';
                $lines[] = '/interface wireless security-profiles add name=mgmt-sec mode=dynamic-keys authentication-types=wpa2-psk wpa2-pre-shared-key="'.$password.'"';
                $lines[] = '/interface wireless add name='.$interface.' master-interface='.$config['wireless_interface'].' ssid="'.$this->quote($config['hotspot_name'].' Mgmt').'" security-profile=mgmt-sec disabled=no';
            } else {
                $lines[] = '/interface wifi enable [find default-name='.$config['wireless_interface'].']';
                $lines[] = ':delay 3s';
                $lines[] = '/interface wifi security add name=mgmt-sec authentication-types=wpa2-psk,wpa3-psk passphrase="'.$password.'"';
                $lines[] = '/interface wifi configuration add name=mgmt-cfg mode=ap ssid="'.$this->quote($config['hotspot_name'].' Mgmt').'" security=mgmt-sec';
                $lines[] = '/interface wifi add name='.$interface.' master-interface='.$config['wireless_interface'].' configuration=mgmt-cfg disabled=no';
            }

            $lines[] = '/interface bridge port add bridge=bridge-mgmt interface='.$interface;
        }

        $lines[] = '# Lock the router\'s own admin services to the management network only.';
        $lines[] = '/ip service set www address='.$network['cidr'];
        $lines[] = '/ip service set www-ssl address='.$network['cidr'];
        $lines[] = '/ip service set winbox address='.$network['cidr'];
        $lines[] = '/ip service set ssh address='.$network['cidr'];
        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function firewallLines(array $config): array
    {
        $lines = [
            '/ip firewall filter add chain=input connection-state=established,related action=accept',
            // 2026-10-18, confirmed live: the catch-all "!WAN drop" rule below
            // also matches the router's own loopback interface ("lo") -- RouterOS
            // filters loopback traffic through the same input chain, it's not
            // exempt. On RouterOS 7, the hotspot service's RADIUS requests to
            // User Manager (127.0.0.1) travel over "lo", so without this accept
            // rule they were being silently dropped before ever reaching User
            // Manager -- the hotspot login just saw "radius server not
            // responding," indistinguishable from User Manager actually being
            // down. Kept unconditional (not gated to RouterOS 7/User Manager
            // specifically) since loopback traffic is inherently trusted
            // (it originates from the router itself) and any other local-only
            // service added later would hit the identical gap otherwise.
            // 2026-10-28: matched by address rather than in-interface=lo -- RouterOS 6
            // has no "lo" interface, so that rule failed there and a v6 /import
            // stopped at it, never reaching the rest of the script.
            '/ip firewall filter add chain=input src-address=127.0.0.1 dst-address=127.0.0.1 action=accept comment="Allow local loopback traffic (e.g. User Manager RADIUS)"',
            '/ip firewall filter add chain=input connection-state=invalid action=drop',
            '/ip firewall filter add chain=input in-interface=bridge-hotspot protocol=tcp dst-port=80,443,8080 action=accept comment="Hotspot captive portal ports"',
        ];

        // 2026-10-18, confirmed live: this accept rule has to come BEFORE the
        // catch-all "!WAN drop" rule below it, not after -- RouterOS evaluates
        // filter rules top-to-bottom and action=drop stops processing right
        // there, so an accept rule placed after a matching drop rule is dead
        // code, never actually reached. The original ordering silently
        // dropped every management-network request at the firewall before it
        // ever reached the www service -- indistinguishable in a browser from
        // the router being completely unreachable ("This site can't be
        // reached"), not an HTTP-level error, since the packet never got a
        // response at all.
        if ($config['enable_mgmt_network']) {
            $lines[] = '/ip firewall filter add chain=input in-interface=bridge-mgmt action=accept comment="Allow the management network to reach the router"';
        }

        $lines[] = '/ip firewall filter add chain=input in-interface-list=!WAN action=drop comment="Block other router access from hotspot clients"';

        if ($config['enable_mgmt_network']) {
            $mgmtNetwork = $this->networkParts($config['mgmt_network']);
            // 2026-10-18, confirmed live: src-address takes an IP/CIDR, never an
            // interface name -- the original "src-address=bridge-hotspot" was
            // rejected outright ("value of range must have ip address before '-'",
            // RouterOS trying and failing to parse the interface name as an IP
            // range). in-interface is the correct property for matching traffic
            // by the interface it entered on.
            $lines[] = '/ip firewall filter add chain=forward in-interface=bridge-hotspot dst-address='.$mgmtNetwork['cidr'].' action=drop comment="Hotspot clients cannot reach the management network"';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * Branches on RouterOS version: v7 gets User Manager with the wizard's
     * profiles and vouchers (profile.starts-when = first-auth makes validity
     * start on first login); v6 only connects the hotspot to User Manager,
     * since its users, profiles and limitations are created in /userman.
     *
     * @param  list<array{profile: string, username: string, password: string}>  $vouchers
     * @return list<string>
     */
    private function userSystemLines(array $config, array $vouchers): array
    {
        return $config['ros_version'] === '7'
            ? $this->userManagerLines($config['profiles'], $vouchers, $this->networkParts($config['hotspot_network'])['host'])
            : $this->userManagerV6Lines($config['profiles'], (string) ($config['mgmt_password'] ?? ''));
    }

    /**
     * RouterOS 6 User Manager (/tool user-manager) as a local RADIUS server on
     * 127.0.0.1. The hotspot's RADIUS requests come from 127.0.0.1, so the
     * User Manager router entry must use that address and the same secret as
     * /radius, or User Manager drops the request and the hotspot reports
     * "radius server not responding". Targets RouterOS 6.40+ (customer=);
     * older routers are upgraded first rather than supported here.
     *
     * Profiles and their limitations come from the wizard and are written
     * set-if-exists/add-if-missing, so re-running a script updates them
     * instead of failing. Users are still created in /userman.
     *
     * @param  list<array{name: string, download_mbps: int, upload_mbps: int, session_minutes: int, shared_users: int, data_limit_bytes?: ?int}>  $profiles
     * @return list<string>
     */
    private function userManagerV6Lines(array $profiles, string $mgmtPassword): array
    {
        $secret = bin2hex(random_bytes(12));
        // The User Manager admin login uses the management password, so one password opens the router's admin side.
        $ownerPassword = trim($mgmtPassword) !== '' ? $this->quote($mgmtPassword) : bin2hex(random_bytes(6));
        $passwordNote = trim($mgmtPassword) !== ''
            ? '# User Manager login: admin with the management network password.'
            : '# User Manager login: admin / '.$ownerPassword.' (no management password was set) -- change it after first login.';

        $lines = [
            '# RouterOS 6 User Manager -- a local, self-hosted RADIUS server (127.0.0.1 only, never leaves the router).',
            '# If every line below fails with "bad command name user-manager", the User Manager package is not installed -- see the note at the top of this script.',
            $passwordNote,
            ':if ([:len [/tool user-manager customer find login="admin"]] > 0) do={ /tool user-manager customer set [find login="admin"] password="'.$ownerPassword.'" } else={ /tool user-manager customer add login="admin" password="'.$ownerPassword.'" permissions=owner }',
            // 2026-10-28, confirmed live: User Manager's database survives a router
            // configuration reset, so a 127.0.0.1 router entry can already exist
            // ("failure: ip address is already used") with an old secret that no
            // longer matches /radius. Update it in place instead of adding.
            ':if ([:len [/tool user-manager router find ip-address=127.0.0.1]] > 0) do={ /tool user-manager router set [find ip-address=127.0.0.1] shared-secret="'.$secret.'" customer=admin } else={ /tool user-manager router add name=this-router ip-address=127.0.0.1 shared-secret="'.$secret.'" customer=admin }',
            // Replace any older 127.0.0.1 RADIUS client so re-running the script never leaves a stale secret behind.
            '/radius remove [find address=127.0.0.1]',
            // The default 300ms RADIUS timeout is often too short for User Manager on small v6 routers.
            '/radius add address=127.0.0.1 secret="'.$secret.'" service=hotspot timeout=3s',
            // Lets User Manager disconnect a session when its time or data runs out.
            '/radius incoming set accept=yes',
            '',
        ];

        if ($profiles !== []) {
            $lines[] = '# Profiles and limitations. Validity starts at the first login (starts-at=logon).';
        }

        foreach ($profiles as $profile) {
            // v6 vouchers print the real profile name (%u_actualProfileName%), so keep it readable rather than slugged.
            $name = $this->quote(trim($profile['name']));
            $limitation = $this->quote(trim($profile['name']).' limit');
            $limitationValues = 'rate-limit-rx='.$profile['upload_mbps'].'M rate-limit-tx='.$profile['download_mbps'].'M';
            $dataLimitBytes = (int) ($profile['data_limit_bytes'] ?? 0);
            if ($dataLimitBytes > 0) {
                $limitationValues .= ' transfer-limit='.$dataLimitBytes;
            }
            $profileValues = 'validity='.max(1, (int) $profile['session_minutes']).'m starts-at=logon override-shared-users='.max(1, (int) $profile['shared_users']);

            $lines[] = ':if ([:len [/tool user-manager profile limitation find name="'.$limitation.'"]] > 0) do={ /tool user-manager profile limitation set [find name="'.$limitation.'"] '.$limitationValues.' } else={ /tool user-manager profile limitation add name="'.$limitation.'" owner=admin '.$limitationValues.' }';
            $lines[] = ':if ([:len [/tool user-manager profile find name="'.$name.'"]] > 0) do={ /tool user-manager profile set [find name="'.$name.'"] '.$profileValues.' } else={ /tool user-manager profile add name="'.$name.'" name-for-users="'.$name.'" owner=admin '.$profileValues.' }';
            // Explicit all-day, every-day window so the limitation always applies, whatever this build's defaults are.
            $lines[] = ':if ([:len [/tool user-manager profile profile-limitation find profile="'.$name.'" limitation="'.$limitation.'"]] = 0) do={ /tool user-manager profile profile-limitation add profile="'.$name.'" limitation="'.$limitation.'" from-time=0s till-time=23h59m59s weekdays=sunday,monday,tuesday,wednesday,thursday,friday,saturday }';
        }

        $lines[] = '';
        $lines[] = '# Next: open http://<router-ip>/userman, log in as admin, and add users. Give each user one of the profiles above, or their logins are rejected.';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  list<array{name: string, download_mbps: int, upload_mbps: int, shared_users: int}>  $profiles
     * @param  list<array{profile: string, username: string, password: string}>  $vouchers
     * @return list<string>
     */
    private function userManagerLines(array $profiles, array $vouchers, string $hotspotHost): array
    {
        $secret = bin2hex(random_bytes(12));
        $sharedUsersByProfile = collect($profiles)->pluck('shared_users', 'name');

        $lines = [
            '# RouterOS 7 User Manager -- a local, self-hosted RADIUS server (127.0.0.1 only, never leaves the router).',
            '# starts-when=first-auth means voucher validity counts from first login, not from creation.',
            // 2026-10-18, confirmed live: every line below fails with "bad command
            // name user-manager" if the separate User Manager package isn't
            // installed -- see the matching warning in headerLines(). Checked here
            // too since this section is sometimes copied/pasted on its own,
            // separate from the rest of the script.
            '# If every line below fails with "bad command name user-manager", the User Manager package is not installed -- see the note at the top of this script.',
            // 2026-10-18: use-profiles=yes is the documented precondition for every
            // limitation/profile/profile-limitation/user-profile object below to
            // actually be enforced at all (help.mikrotik.com/docs/spaces/ROS/pages/
            // 2555940/User+Manager -- "Profiles and limitations only apply when
            // /user-manager use-profiles is set to yes") -- without it, a voucher
            // could authenticate but never actually get its rate limit or validity
            // window applied, since User Manager would ignore every profile object.
            '/user-manager set enabled=yes use-profiles=yes',
            '/user-manager router add address=127.0.0.1 name=this-router shared-secret="'.$secret.'"',
            '/radius add address=127.0.0.1 secret="'.$secret.'" service=hotspot',
            // Lets User Manager disconnect a session when its time or data runs out.
            '/radius incoming set accept=yes',
            '',
        ];

        foreach ($profiles as $profile) {
            $slug = $this->profileIdentifier($profile['name']);
            $validityMinutes = max(1, (int) $profile['session_minutes']);
            // See DATA_LIMIT_PRESETS's own docblock: blank/null ("Unlimited")
            // is treated the same as 0, transfer-limit's own documented
            // default -- not yet confirmed live that an explicit 0 behaves
            // identically to never setting the property at all.
            $dataLimitBytes = (int) ($profile['data_limit_bytes'] ?? 0);

            // Three distinct objects, all required for a voucher to actually be
            // rate-limited and time-boxed: Limitation (the rate caps),
            // Profile (validity/starts-when), and Profile-Limitation (the link
            // between the two -- MikroTik's docs note a profile with no
            // profile-limitation entry simply has no limitation applied at all).
            $lines[] = '/user-manager limitation add name="'.$slug.'-limit" rate-limit-rx='.$profile['upload_mbps'].'M rate-limit-tx='.$profile['download_mbps'].'M transfer-limit='.$dataLimitBytes;
            $lines[] = '/user-manager profile add name="'.$slug.'" validity='.$validityMinutes.'m starts-when=first-auth';
            $lines[] = '/user-manager profile-limitation add profile="'.$slug.'" limitation="'.$slug.'-limit"';
        }

        if ($vouchers !== []) {
            $lines[] = '';
            $lines[] = '# Voucher codes -- also listed on the printable sheet. Each user below carries';
            $lines[] = '# a comment identifying its batch -- the same comment is reused further down to';
            $lines[] = '# generate MikroTik\'s own native printable voucher sheet for that batch directly';
            $lines[] = '# on the router (User Manager\'s built-in generate-voucher action), the same';
            $lines[] = '# comment-then-filter workflow used when creating these by hand in WinBox.';

            foreach ($vouchers as $voucher) {
                $slug = $this->profileIdentifier($voucher['profile']);
                $sharedUsers = max(1, (int) ($sharedUsersByProfile[$voucher['profile']] ?? 1));
                $comment = $this->voucherBatchComment($slug);

                $lines[] = '/user-manager user add name="'.$voucher['username'].'" password="'.$voucher['password'].'" shared-users='.$sharedUsers.' comment="'.$comment.'"';
                $lines[] = '/user-manager user-profile add user="'.$voucher['username'].'" profile="'.$slug.'"';
            }

            $lines[] = '';
            $lines[] = '# Generates a print-ready voucher sheet on the router itself for each batch --';
            $lines[] = '# '.self::VOUCHER_TEMPLATE.' ships with User Manager by default, per MikroTik\'s own';
            $lines[] = '# documented example (no upload needed).';
            // 2026-10-18, confirmed live: generate-voucher writes to a FIXED path,
            // served over the router's own web server -- overwritten on every run,
            // not a new uniquely-named file per batch. Download/print before
            // generating a different batch, or it's gone. The filename itself is
            // "gen_" plus the template's own name -- not a universally fixed
            // "gen_tickets.html" as an earlier, unverified version of this comment
            // assumed; confirmed live with VOUCHER_TEMPLATE's own current value.
            $lines[] = '# Download/print at: '.$this->voucherSheetUrl($hotspotHost).' (overwritten on every run -- grab it before generating a different batch).';

            foreach (collect($vouchers)->pluck('profile')->unique() as $profileName) {
                $comment = $this->voucherBatchComment($this->profileIdentifier($profileName));
                $lines[] = '/user-manager user generate-voucher voucher-template='.self::VOUCHER_TEMPLATE.' [find comment="'.$comment.'"]';
            }
        }

        $lines = array_merge($lines, $this->voucherUtilityScriptLines($profiles, $vouchers, $hotspotHost));

        return $lines;
    }

    /**
     * Two small, persistent /system script objects left on the router after
     * pasting -- so generating a later batch (a new voucher print run, or a
     * new tier entirely) never again needs a trip back to this wizard and a
     * full re-paste. Each one's "parameters" are just :local variables at the
     * top of its own source, since RouterOS scripts don't take named
     * arguments the way a function call would -- the intended workflow is
     * editing that one value (Winbox > System > Scripts > double-click >
     * Source, or "/system script edit <name> source"), then
     * "/system script run <name>".
     *
     * @param  list<array{name: string, download_mbps: int, upload_mbps: int, session_minutes: int}>  $profiles
     * @param  list<array{profile: string, username: string, password: string}>  $vouchers
     * @return list<string>
     */
    private function voucherUtilityScriptLines(array $profiles, array $vouchers, string $hotspotHost): array
    {
        $defaultComment = $vouchers !== []
            ? $this->voucherBatchComment($this->profileIdentifier($vouchers[0]['profile']))
            : 'voucher-batch:change-me';

        $defaultProfile = $profiles[0] ?? ['name' => 'new-profile', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'data_limit_bytes' => null];
        $defaultProfileName = $this->profileIdentifier($defaultProfile['name']);
        // 2026-10-18, direct request: a human editing this script by hand
        // shouldn't have to compute/type a raw byte count -- every preset in
        // DATA_LIMIT_PRESETS is already a whole-MB multiple, so this always
        // divides back cleanly. The script itself converts back to bytes
        // (see the :local dataLimitBytes line below) so transfer-limit= still
        // gets what RouterOS actually expects.
        $defaultDataLimitMegabyte = (int) round(((int) ($defaultProfile['data_limit_bytes'] ?? 0)) / self::BYTES_PER_MB);

        return [
            '',
            '# Two reusable scripts, saved on the router -- edit the variable(s) at the top',
            '# (Winbox > System > Scripts > double-click > Source), then run with',
            '# "/system script run <name>". No need to come back to this generator for',
            '# every new voucher batch or tier.',
            '/system script add name=generate-voucher-sheet source={',
            '    :local voucherComment "'.$defaultComment.'"',
            '    /user-manager user generate-voucher voucher-template='.self::VOUCHER_TEMPLATE.' [find comment=$voucherComment]',
            '    :put ("Voucher sheet generated for batch: ".$voucherComment)',
            '    :put "Download/print at: '.$this->voucherSheetUrl($hotspotHost).'"',
            '    :put "Note: this file is overwritten every run -- download or print before generating a different batch."',
            '}',
            '',
            '/system script add name=create-voucher-profile source={',
            '    :local profileName "'.$defaultProfileName.'"',
            '    :local downloadMbps '.(int) $defaultProfile['download_mbps'],
            '    :local uploadMbps '.(int) $defaultProfile['upload_mbps'],
            '    :local validityMinutes '.max(1, (int) $defaultProfile['session_minutes']),
            '    :local dataLimitMegabyte '.$defaultDataLimitMegabyte.' ;# total transfer cap in MB, 0 = unlimited -- edit this, not a raw byte count',
            '    :local dataLimitBytes ($dataLimitMegabyte * '.self::BYTES_PER_MB.')',
            '    /user-manager limitation add name=($profileName."-limit") rate-limit-rx=($uploadMbps."M") rate-limit-tx=($downloadMbps."M") transfer-limit=$dataLimitBytes',
            '    /user-manager profile add name=$profileName validity=($validityMinutes."m") starts-when=first-auth',
            '    /user-manager profile-limitation add profile=$profileName limitation=($profileName."-limit")',
            '    :put ("Created profile: ".$profileName." (".$downloadMbps."M down / ".$uploadMbps."M up, ".$validityMinutes." min validity, data cap ".$dataLimitMegabyte."MB -- 0 = unlimited)")',
            '}',
            '',
        ];
    }

    /**
     * A single, deterministic comment per profile slug -- shared between every
     * voucher /user-manager user add line for that profile and the
     * generate-voucher [find comment=...] filter below it, so MikroTik's
     * own native voucher-printing action picks up exactly this run's batch.
     * Not timestamped/unique-per-run deliberately: this tool is stateless and
     * meant to be pasted once on a router that doesn't already have an older
     * same-profile batch on it (the same "paste once" assumption the rest of
     * this generator's non-idempotent sections already make).
     */
    private function voucherBatchComment(string $slug): string
    {
        return 'voucher-batch:'.$slug;
    }

    /**
     * 2026-10-18, confirmed live: this used to branch purely on ros_version
     * (6 => legacy /interface wireless, 7 => the newer /interface wifi
     * package) -- but that's not actually how RouterOS decides which driver
     * to use. The new wifi/wifiwave2 package only runs on specific ARM chips
     * (IPQ4019/QCA9984-class); older MIPS-based boards (confirmed live on an
     * RB951Ui, an Atheros AR9300 chip) stay on the legacy driver forever,
     * REGARDLESS of RouterOS version. The symptom on that router was
     * identical across two attempts, including one with a 3-second :delay
     * added specifically to rule out a timing race: /interface wifi
     * enable/set against "wifi1" always silently matched zero rows (find
     * against an empty table is not an error), so the later literal
     * `interface=wifi1` bridge-port-add always failed outright -- "wifi1"
     * never existed anywhere on that router under any package.
     * `/interface wireless print` on the same router confirmed the real
     * interface was "wlan1" under the legacy driver all along.
     *
     * Deliberately a capability toggle (wireless_driver), not a router-model
     * catalog -- every MikroTik board falls into exactly one of these two
     * camps, so this one binary choice gives full precision without ever
     * needing updates when a new board ships, the same "no hardcoded model
     * database" principle StandalonePtpScriptGenerator's wireless handling
     * already documents. RouterOS 6 has no choice at all (the new package
     * doesn't exist on it), so it's always legacy regardless of what's
     * passed -- wireless_driver only matters when ros_version is 7.
     */
    private function usesLegacyWireless(array $config): bool
    {
        return $config['ros_version'] === '6' || ($config['wireless_driver'] ?? 'wifiwave2') === 'legacy';
    }

    /**
     * 2026-10-18, confirmed live against a real router: the FILESYSTEM path
     * generate-voucher actually writes to (what "/file print" shows) is
     * "um5files/PRIVATE/GENERATED/vouchers/gen_<template>.html" -- but that's
     * not the same as the URL a browser uses to fetch it. Per MikroTik's own
     * documentation, the User Manager web interface's URL namespace is always
     * "/um/" regardless of the on-disk folder's literal name ("um5files" is
     * an internal storage path, a naming holdover from User Manager's earlier
     * v5 incarnation) -- RouterOS's web server maps that whole storage area
     * under the "/um/" URL prefix, not the on-disk "um5files" one. The
     * filename itself is "gen_" plus the template's own name
     * (VOUCHER_TEMPLATE), confirmed the same day against the earlier,
     * unverified "gen_tickets.html" assumption. Served over the router's own
     * built-in web server on whatever port it listens on (plain http, port 80
     * by default) -- this assumes that default; a router with www moved to a
     * non-standard port needs that port appended manually. Also note: this
     * whole directory lives under "PRIVATE", so the www service will prompt
     * for the router's own admin credentials before serving it -- and www
     * itself may be address-restricted to a management subnet (this
     * generator's own managementNetworkLines() does exactly that), in which
     * case the browsing device has to actually be on that subnet first.
     */
    private function voucherSheetUrl(string $hotspotHost): string
    {
        return 'http://'.$hotspotHost.'/um/PRIVATE/GENERATED/vouchers/gen_'.self::VOUCHER_TEMPLATE;
    }

    /**
     * @param  list<array{name: string, voucher_count: int, voucher_prefix: string, voucher_code_length: int, voucher_username_password_same: bool, voucher_character_set: string}>  $profiles
     * @return list<array{profile: string, username: string, password: string}>
     */
    private function generateVoucherCodes(array $profiles): array
    {
        $vouchers = [];
        $seenUsernames = [];

        foreach ($profiles as $profile) {
            $prefix = (string) ($profile['voucher_prefix'] ?? '');
            $codeLength = max(4, (int) ($profile['voucher_code_length'] ?? 8));
            $alphabet = self::CHARACTER_SETS[$profile['voucher_character_set'] ?? 'alnum_safe'] ?? self::CHARACTER_SETS['alnum_safe'];
            $samePassword = (bool) ($profile['voucher_username_password_same'] ?? true);

            for ($i = 0; $i < max(0, (int) $profile['voucher_count']); $i++) {
                do {
                    $username = $prefix.$this->randomVoucherCode($codeLength, $alphabet);
                } while (isset($seenUsernames[$username]));

                $seenUsernames[$username] = true;

                $vouchers[] = [
                    'profile' => $profile['name'],
                    'username' => $username,
                    'password' => $samePassword ? $username : $this->randomVoucherCode($codeLength, $alphabet),
                ];
            }
        }

        return $vouchers;
    }

    private function randomVoucherCode(int $length, string $alphabet): string
    {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function lanPorts(string $wanPort, int $totalPorts): array
    {
        $totalPorts = max(1, $totalPorts);

        if (! preg_match('/^(.*?)(\d+)$/', $wanPort, $matches)) {
            return array_map(fn (int $n) => 'ether'.$n, range(2, max(2, $totalPorts)));
        }

        [, $prefix, $wanNumber] = $matches;
        $wanNumber = (int) $wanNumber;
        $ports = [];

        for ($n = 1; $n <= $totalPorts; $n++) {
            if ($n === $wanNumber) {
                continue;
            }

            $ports[] = $prefix.$n;
        }

        return $ports;
    }

    /**
     * @return array{host: string, gateway: string, cidr: string, range: string}
     */
    private function networkParts(string $baseCidr): array
    {
        $host = str($baseCidr)->before('/')->toString();
        $parts = explode('.', $host);
        $base = implode('.', array_slice($parts, 0, 3));

        return [
            'host' => $host,
            'gateway' => $host,
            'cidr' => $base.'.0/24',
            'range' => $base.'.10-'.$base.'.254',
        ];
    }

    private function profileIdentifier(string $name): string
    {
        return (string) str($name)->slug('-');
    }

    /**
     * Escapes a value for a RouterOS double-quoted string, where \, " and $ are all special.
     */
    private function quote(?string $value): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\"', '\$'], (string) $value);
    }
}
