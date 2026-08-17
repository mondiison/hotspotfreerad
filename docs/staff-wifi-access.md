# Staff & Management Wi-Fi Access

The Staff and Management SSIDs are WPA2/WPA3-PSK, not open like the customer hotspot. A shared password on its own has a real weakness: anyone who knows it can share it (a phone's built-in "share Wi-Fi password" feature, a screenshot, a coworker just reading it out) and there is no way to stop that while the network is protected by a single shared string. There is no way to close that gap completely without moving to per-person credentials (WPA2/3-Enterprise with 802.1X/EAP against FreeRADIUS) — that is a larger project, not yet built.

**Trusted Wi-Fi Devices** (`admin/trusted-wifi-devices`) is the practical middle ground shipped now: register each staff/management device by MAC address, and only registered devices can join those SSIDs — even with the correct password — everywhere the mechanism below is actually wired up. This does not replace the Wi-Fi password; it adds a second check.

## How enforcement actually works today

There are two different SSID hosting situations in this app, and the mechanism differs between them.

### MikroTik built-in Wi-Fi (the "This router has built-in Wi-Fi" toggle)

This is the one case where HotspotFreeRAD directly controls the SSID. The router's generated script (Script page → Fresh Infrastructure Script) now includes, for each of the Staff and Management SSIDs:

```routeros
/interface wifi access-list add interface=$staffWifiInterface mac-address=AA:BB:CC:DD:EE:FF action=accept comment="..."
/interface wifi access-list add interface=$staffWifiInterface action=reject comment="Default-deny: only registered MMS Staff devices may join"
```

One `accept` line per active, non-expired device registered for that shop and network, followed by a catch-all `reject`. If **no** devices are registered for a network yet, the reject line is omitted entirely and a comment explains why — so a brand-new setup doesn't accidentally lock out the admin's own device before anything has been registered.

**This list is generated fresh every time you view/copy the script — it is not pushed to the router automatically.** After adding, editing, or removing a trusted device, re-open the router's Script page and re-run at least the WireGuard/Wi-Fi section on the physical router to pick up the change. There is no live sync to the router today (unlike the WireGuard peer sync, which does update automatically — see `docs/wireguard-server-setup.md`).

`/interface/wifi/access-list` is a real RouterOS 7 wifiwave2 feature, but its exact behavior has evolved across RouterOS releases. **Verify it behaves as expected on your specific RouterOS version before relying on it in production** — test with a device that is not on the accept list and confirm it's actually rejected.

### External APs (the recommended production setup)

For real deployments, `docs/router-onboarding.md` recommends external business APs (Ruijie/Reyee, TP-Link Omada, etc.) rather than the MikroTik's own radio — the built-in Wi-Fi profile above is for lab/pilot testing. HotspotFreeRAD's script generator does not configure third-party AP hardware, so the local access-list above does not apply there.

The vendor-agnostic equivalent is **RADIUS MAC authentication**, which most decent AP controllers support natively: the AP asks the RADIUS server "is this MAC allowed?" before letting a device associate, independent of the PSK. Registering a device under Trusted Wi-Fi Devices already writes it into FreeRADIUS (`radcheck`, username and password both set to the device's MAC) — so if you configure your AP controller's RADIUS MAC-auth to point at the same RADIUS server (`RADIUS_SERVER_IP`, plus a shared secret configured on your AP controller and in FreeRADIUS's `clients.conf`) using `service=wireless`, it can use exactly this same device list.

**This gap is now closable:** an AP sitting on the router's LAN (e.g. the management VLAN) reaching the Pi's RADIUS server means its traffic has to cross the WireGuard tunnel — by default, the automatic WireGuard peer sync (`hotspot:sync-wireguard-peers`) only allows each router's own tunnel IP (`allowed-ips = <ip>/32`) through, not the LAN/VLAN subnets behind it. The router wizard's Network plan step has a **"Route this router's management/staff networks through the WireGuard tunnel"** toggle (`provisioning_settings.route_lan_through_tunnel`) that opens exactly that path — see `docs/wireguard-server-setup.md`'s "Optional: route a router's LAN through the tunnel" section for the one-time Pi-side setup this needs first (`WIREGUARD_MANAGE_ROUTES=true` plus a few commands; it's independent of and in addition to the base WireGuard setup). Two things worth knowing before flipping it on:

- **Not retroactive.** It only takes effect for routers that have the toggle enabled *and* the Pi bootstrap completed — a router created before either exists still has the old `/32`-only behavior until you go back and enable it.
- **Subnet collisions are actively checked, not just possible.** Every router's mgmt/staff network defaults to the exact same literal subnet regardless of profile — saving a router with this toggle on now validates its `mgmt_network`/`staff_network` against every *other* router that also has it enabled, and refuses to save if they overlap (WireGuard's `AllowedIPs` must be unique per peer on a given interface). If you hit that error, change one router's subnet before enabling the toggle on both — this only blocks routers that both actually use this feature, routers left on defaults without it enabled are unaffected either way.

## Managing devices

- **Admin → Trusted Wi-Fi** lists, adds, edits, and removes devices per shop, per network (`staff` or `mgmt`).
- Devices can have an optional expiry (useful for contractors/temporary access) and an active/inactive toggle — either one revokes RADIUS access immediately (`radcheck` row removed) without deleting the record.
- Every save re-syncs RADIUS via `App\Services\RadiusProvisioningService::provisionTrustedWifiDevice()` / `revokeTrustedWifiDevice()`.

## Seeing what's actually connected

Registering a device above requires already knowing its MAC address — until 2026-08-09 there was no way to see what's actually joined a network first. A router's Script page → **Live** tab → "Connected Devices" now shows live DHCP leases per network with a one-click "Register as trusted" action; see `docs/router-monitoring.md`'s Connected Devices section for how it works. **It is visibility only** — it does not change anything about the built-in Wi-Fi access-list, which still isn't auto-pushed to the router. External-AP RADIUS MAC-auth's WireGuard LAN-routing gap is now closable via the `route_lan_through_tunnel` toggle described above, but visibility and enforcement are still two different things — a device showing up connected doesn't mean it's actually being *restricted* by the allowlist, whichever mechanism you're using.
