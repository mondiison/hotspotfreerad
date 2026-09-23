# Staff & Management Wi-Fi Access

The Staff and Management SSIDs are WPA2/WPA3-PSK, not open like the customer hotspot. A shared password on its own has a real weakness: anyone who knows it can share it (a phone's built-in "share Wi-Fi password" feature, a screenshot, a coworker just reading it out) and there is no way to stop that while the network is protected by a single shared string. There is no way to close that gap completely without moving to per-person credentials (WPA2/3-Enterprise with 802.1X/EAP against FreeRADIUS) — that is a larger project, not yet built.

**Trusted Wi-Fi Devices** (`admin/trusted-wifi-devices`) is the practical middle ground shipped now: register each staff/management device by MAC address, and only registered devices can join those SSIDs — even with the correct password — everywhere the mechanism below is actually wired up. This does not replace the Wi-Fi password; it adds a second check.

## How enforcement actually works today

Two independent mechanisms exist, and (since 2026-09-23) one of them works regardless of whether the router has built-in Wi-Fi at all.

### MAC-auth hotspot (works with or without built-in Wi-Fi — the primary mechanism)

`App\Services\MikroTikProvisioningService::generateStaffScript()`'s "Staff Script" tab binds a RouterOS `/ip hotspot` server (`login-by=mac`) directly to `vlan-staff`/`vlan-mgmt`, the exact same mechanism POS devices already use — it enforces on the VLAN itself, independent of how a device physically reaches it: MikroTik's own built-in radio, an external AP bridged into an extra Staff/Management access port, or a directly wired connection. This is unconditional whenever Staff is enabled (Management's VLAN is core infrastructure, so its hotspot is always created) — it does **not** require "This router has built-in Wi-Fi" to be on.

```routeros
/ip hotspot profile add name=mms-staff-profile use-radius=yes login-by=mac mac-auth-password="..." radius-accounting=yes
/ip hotspot add name=mms-staff interface=vlan-staff address-pool=pool-staff profile=mms-staff-profile disabled=no
```

Registering a device under Trusted Wi-Fi Devices writes it into FreeRADIUS's `radcheck` (username = the device's MAC, password = the fixed `RadiusProvisioningService::TRUSTED_WIFI_MAC_AUTH_PASSWORD` value) — an unregistered or expired/inactive MAC is rejected outright, even with the correct Wi-Fi password or physical wire access. `mac-auth-password` is set to that same fixed value explicitly on the hotspot profile, not left blank — RouterOS's "defaults to the client's MAC" blank behavior does not actually send a CHAP-hashable password matching a MAC stored in `radcheck`, the same issue confirmed live for POS's own MAC-auth hotspot.

Live over the API, `App\Services\RouterOsConnectionService::provisionStaffWifi()`'s "Provision via API" button (and the scheduled `hotspot:auto-provision-routers` cycle) keeps this hotspot's profile/server in sync automatically, the same way POS's does — no manual re-paste needed for the MAC-auth mechanism itself. Not yet confirmed against real hardware — in particular, that adding a hotspot server on `vlan-mgmt` doesn't interfere with the router's own Winbox/SSH/API management access on that same VLAN; verify before relying on this in production for a router you also manage over that VLAN.

### MikroTik built-in Wi-Fi access-list (a second, additional layer — wireless radio only)

When "This router has built-in Wi-Fi" is on, the Staff Script also manages a local `/interface wifi access-list` per SSID as a second, wireless-specific layer on top of the MAC-auth hotspot above:

```routeros
/interface wifi access-list add interface=wifi-staff mac-address=AA:BB:CC:DD:EE:FF action=accept comment="..."
/interface wifi access-list add interface=wifi-staff action=reject comment="Default-deny: only registered MMS Staff devices may join"
```

One `accept` line per active, non-expired device registered for that shop and network, followed by a catch-all `reject`. If **no** devices are registered for a network yet, the reject line is omitted entirely and a comment explains why — so a brand-new setup doesn't accidentally lock out the admin's own device before anything has been registered.

This mechanism is genuinely wireless-radio-only — RouterOS has no equivalent for a wired port or an external AP, which is exactly why the MAC-auth hotspot above exists as the primary, always-available mechanism. As of 2026-09-23, this list is also kept in sync live over the API (`RouterOsConnectionService::provisionStaffWifi()`'s access-list sync step, and the scheduled auto-provisioning cycle) — it is no longer copy-paste-only the way it used to be, though a fresh SSID still needs its wireless interface created by hand (or via Fresh Infrastructure Script) at least once before the live sync can find it to manage.

`/interface/wifi/access-list` is a real RouterOS 7 wifiwave2 feature, but its exact behavior has evolved across RouterOS releases. **Verify it behaves as expected on your specific RouterOS version before relying on it in production** — test with a device that is not on the accept list and confirm it's actually rejected.

### External APs (the recommended production setup)

For real deployments, `docs/router-onboarding.md` recommends external business APs (Ruijie/Reyee, TP-Link Omada, etc.) rather than the MikroTik's own radio. HotspotFreeRAD's script generator does not configure third-party AP hardware directly, but an external AP bridged into an extra Staff/Management access port is already covered by the MAC-auth hotspot above — no extra AP-side RADIUS configuration is needed for that path, since enforcement happens at the VLAN/router level, not the AP.

If you'd rather have the AP itself do the MAC check (e.g. to reject a device before it even associates, not just before it gets network access), most decent AP controllers also support **RADIUS MAC authentication** natively: the AP asks the RADIUS server "is this MAC allowed?" before letting a device associate, independent of the PSK. This can point at the same FreeRADIUS server (`RADIUS_SERVER_IP`, plus a shared secret configured on your AP controller and in FreeRADIUS's `clients.conf`) using `service=wireless` and the same Trusted Wi-Fi Devices list — but configure your AP controller to send the fixed password `RadiusProvisioningService::TRUSTED_WIFI_MAC_AUTH_PASSWORD` for MAC-auth requests, not the device's own MAC as the password (a common AP-controller default) — `radcheck` only holds one password per device, and it now has to satisfy the MAC-auth hotspot's CHAP exchange above too.

**This gap is now closable:** an AP sitting on the router's LAN (e.g. the management VLAN) reaching the Pi's RADIUS server means its traffic has to cross the WireGuard tunnel — by default, the automatic WireGuard peer sync (`hotspot:sync-wireguard-peers`) only allows each router's own tunnel IP (`allowed-ips = <ip>/32`) through, not the LAN/VLAN subnets behind it. The router wizard's Network plan step has a **"Route this router's management/staff networks through the WireGuard tunnel"** toggle (`provisioning_settings.route_lan_through_tunnel`) that opens exactly that path — see `docs/wireguard-server-setup.md`'s "Optional: route a router's LAN through the tunnel" section for the one-time Pi-side setup this needs first (`WIREGUARD_MANAGE_ROUTES=true` plus a few commands; it's independent of and in addition to the base WireGuard setup). Two things worth knowing before flipping it on:

- **Not retroactive.** It only takes effect for routers that have the toggle enabled *and* the Pi bootstrap completed — a router created before either exists still has the old `/32`-only behavior until you go back and enable it.
- **Subnet collisions are actively checked, not just possible.** Every router's mgmt/staff network defaults to the exact same literal subnet regardless of profile — saving a router with this toggle on now validates its `mgmt_network`/`staff_network` against every *other* router that also has it enabled, and refuses to save if they overlap (WireGuard's `AllowedIPs` must be unique per peer on a given interface). If you hit that error, change one router's subnet before enabling the toggle on both — this only blocks routers that both actually use this feature, routers left on defaults without it enabled are unaffected either way.

## Managing devices

- **Admin → Trusted Wi-Fi** lists, adds, edits, and removes devices per shop, per network (`staff` or `mgmt`).
- Devices can have an optional expiry (useful for contractors/temporary access) and an active/inactive toggle — either one revokes RADIUS access immediately (`radcheck` row removed) without deleting the record.
- Every save re-syncs RADIUS via `App\Services\RadiusProvisioningService::provisionTrustedWifiDevice()` / `revokeTrustedWifiDevice()`.

## Seeing what's actually connected

Registering a device above requires already knowing its MAC address — until 2026-08-09 there was no way to see what's actually joined a network first. A router's Script page → **Live** tab → "Connected Devices" now shows live DHCP leases per network with a one-click "Register as trusted" action; see `docs/router-monitoring.md`'s Connected Devices section for how it works. **It is visibility only** — it does not itself enforce anything (the MAC-auth hotspot and built-in Wi-Fi access-list above do that, both now kept in sync live). External-AP RADIUS MAC-auth's WireGuard LAN-routing gap is now closable via the `route_lan_through_tunnel` toggle described above, but visibility and enforcement are still two different things — a device showing up connected doesn't mean it's actually being *restricted*, whichever mechanism you're using.
