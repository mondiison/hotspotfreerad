# Router Onboarding Guide

This guide explains the values needed when adding a MikroTik router in HotspotFreeRAD.

Connecting a router to the Pi's WireGuard tunnel is automatic: HotspotFreeRAD generates a WireGuard keypair for each router, bakes the private key into its generated script, and a scheduled command registers the router as a peer on the Pi within a few minutes. The one manual step is a one-time server bootstrap on the Pi itself — see [WireGuard server setup](wireguard-server-setup.md) if that hasn't been done yet.

For the password-protected Staff/Management Wi-Fi SSIDs, see [Staff & management Wi-Fi access](staff-wifi-access.md) — registering trusted devices by MAC address means the password alone isn't enough to join those networks.

## Field Examples

| Field | Example | Where it comes from |
| --- | --- | --- |
| Router name | Main Shop Router | A friendly name for the admin dashboard only. |
| NAS identifier | lagos-shop-01 | A unique identity for this MikroTik router. Use lowercase words, numbers, and hyphens. |
| WireGuard internal IP | 10.8.0.10 | The private VPN IP assigned to this router. Each router must have a different IP. |
| RADIUS shared secret | Generate a long random value | A private password shared between MikroTik and FreeRADIUS. |

## NAS Identifier

The NAS identifier is the router identity used to match MikroTik, FreeRADIUS accounting, and HotspotFreeRAD.

Recommended format:

```text
tenant-or-shop-name-router-number
```

Examples:

```text
lagos-shop-01
ikeja-cafe-main
tenant3-branch2-r1
```

You can set it on MikroTik with:

```routeros
/system identity set name="lagos-shop-01"
```

The generated script does this automatically.

## WireGuard Internal IP

This is the router's private IP inside the WireGuard tunnel.

Recommended starter plan:

```text
Raspberry Pi / server: 10.8.0.1
Router 1:              10.8.0.10
Router 2:              10.8.0.11
Router 3:              10.8.0.12
```

Do not reuse the same WireGuard IP for two routers.

The generated script applies it with:

```routeros
/ip address add address=10.8.0.10/24 interface=wg-saas
```

## Local Pi Behind The Same MikroTik

If the Raspberry Pi is physically connected to the same MikroTik router you are configuring (e.g. plugged into one of its LAN ports), that router should dial the Pi's **LAN IP**, not the public endpoint — using the public IP from inside the same network commonly fails silently, because it requires NAT hairpin/loopback support for UDP that many routers (MikroTik included) don't have. The symptom looks identical either way: the router's peer entry looks correct, but `sudo wg show wg-saas` on the Pi never shows a handshake, and `wg show` never records an endpoint for that peer at all (nothing arrives).

**Set this on the router in HotspotFreeRAD** — the router's create/edit form has a **"WireGuard endpoint override"** field, blank by default. Only fill it in for a router that's on the same local network as the Pi: enter the Pi's LAN IP there (and the port, if it's not the standard `13231`). The generated bootstrap/hotspot/PPPoE/fresh-infrastructure scripts then use that address instead of the app-wide public endpoint for this one router — no manual RouterOS editing needed, and it's re-applied automatically every time the script is regenerated. Leave it blank for every other (remote) router, which is the common case.

If you've already pasted a script with the public endpoint baked in and need to fix it by hand before regenerating, the equivalent RouterOS command is:

```routeros
/interface wireguard peers set [find] endpoint-address=<Pi's LAN IP> endpoint-port=13231
```

Then test:

```routeros
/ping <Pi's LAN IP>
/ping 10.8.0.1 interface=wg-saas
```

The first confirms basic LAN reachability; the second confirms the tunnel itself is up once a handshake has formed.

Remote routers also require the internet router to forward UDP `13231` to the Pi LAN IP.

## RADIUS Shared Secret

The RADIUS shared secret is a private password used by MikroTik and FreeRADIUS to trust each other.

Use a different secret for every router.

Good example:

```text
QF9mX7vC2pL8nR4sT6wY1zA5
```

Avoid weak values like:

```text
secret
123456
password
```

On Linux, you can generate one with:

```bash
openssl rand -base64 24
```

On Windows PowerShell, you can generate one with:

```powershell
-join ((48..57 + 65..90 + 97..122) | Get-Random -Count 32 | ForEach-Object {[char]$_})
```

HotspotFreeRAD stores the secret encrypted in the application table, and syncs it into the FreeRADIUS `nas.secret` column.

If your Raspberry Pi FreeRADIUS is using static client files instead of SQL NAS
loading, enable the managed clients include file on the Pi:

```bash
sudo mkdir -p /etc/freeradius/3.0/clients.d
sudo touch /etc/freeradius/3.0/clients.d/hotspotfreerad.conf
sudo chown www-data:www-data /etc/freeradius/3.0/clients.d/hotspotfreerad.conf
sudo chmod 664 /etc/freeradius/3.0/clients.d/hotspotfreerad.conf
```

Make sure `/etc/freeradius/3.0/clients.conf` includes the directory:

```conf
$INCLUDE clients.d/*.conf
```

Then set these in `.env`:

```env
RADIUS_MANAGE_CLIENTS=true
RADIUS_CLIENTS_FILE=/etc/freeradius/3.0/clients.d/hotspotfreerad.conf
```

After creating or editing routers, the scheduler runs:

```bash
php artisan hotspot:sync-radius-clients --reload
```

You can also run it manually. It writes entries like:

```conf
client bebeji-router01 {
    ipaddr = 10.8.0.11
    secret = "router-shared-secret"
    shortname = "bebeji-router01"
}
```

## First Router Checklist

1. Add a tenant in HotspotFreeRAD.
2. Add a shop for that tenant.
3. Add a router with:
   - NAS identifier: `demo-shop-01`
   - WireGuard internal IP: `10.8.0.10`
   - RADIUS shared secret: generated random value
4. Open the router's Script page.
5. Review `RADIUS_SERVER_IP`, `WIREGUARD_ENDPOINT_HOST`, and `WIREGUARD_PUBLIC_KEY` in `.env`.
6. Paste the generated script into MikroTik RouterOS terminal. Its WireGuard section already includes a private key HotspotFreeRAD generated for this router — you don't need to run `/interface wireguard print` to find one. On a genuinely new router, also run MikroTik's `/ip hotspot setup` wizard (before or after pasting the script, order doesn't matter) — the generated script wires RADIUS into whatever hotspot server already exists, but it doesn't create one from scratch. If you skip the wizard, the script's `/ip hotspot set [find] profile=saas-prof` line is a harmless no-op and customers won't see a captive portal until you run it.
7. Wait up to 5 minutes (or run `php artisan hotspot:sync-wireguard-peers` on the Pi) for the router to appear as a peer in `sudo wg show wg-saas`. No manual peer registration needed.
8. Confirm FreeRADIUS sees the router in the `nas` table.
9. Test authentication with FreeRADIUS debug mode:

```bash
sudo freeradius -X
```

## Built-In Wi-Fi Testing (e.g. MikroTik L009UiGS)

Built-in Wi-Fi is no longer a named preset tied to one board — it's an independent "This router has built-in Wi-Fi" toggle on the Hardware step of the router wizard (`admin/routers`), usable with any bandwidth/VLAN template. Testing MMS Radius on hardware with an onboard radio (e.g. an L009UiGS) without external APs first:

Recommended values for that step:

| Setting | Value |
| --- | --- |
| WAN 1 | `ether1` |
| WAN 2 (if used) | `ether7` |
| AP/switch trunk | `ether2` |
| Built-in Wi-Fi interface | `wifi1` |
| Hotspot gateway | `10.5.50.1/24` |
| Hotspot network | `10.5.50.0/24` |
| Hotspot pool | `10.5.50.10-10.5.50.250` |

Enter the total Ethernet port count on the Hardware step and pick these as the WAN 1/trunk/Pi ports from the dropdowns (or use "Advanced: type interface names manually" if your board doesn't use plain `etherN` naming). The generated script will:

- create the normal WireGuard and RADIUS setup;
- create VLANs on the LAN bridge;
- configure `wifi1` as an open `MMS Hotspot` SSID;
- place `wifi1` into the hotspot VLAN as an untagged access interface;
- keep `ether2` as a tagged AP/switch trunk for later expansion.

For production plaza coverage, still prefer external business APs with VLAN-per-SSID support. Built-in Wi-Fi is best for lab testing, office testing, or a very small pilot zone.

## Phone Login Test

The first successful milestone is a phone authenticating through:

```text
Phone -> access point -> MikroTik -> WireGuard -> FreeRADIUS
```

For a username/password test, create a temporary FreeRADIUS user:

```sql
USE radius;

INSERT INTO radcheck (username, attribute, op, value)
VALUES ('test', 'Cleartext-Password', ':=', 'test');
```

Then connect the phone to the access point and log in with:

```text
Username: test
Password: test
```

In `sudo freeradius -X`, a working path shows `Access-Accept`.

## Captive Portal Redirect

After RADIUS authentication works, configure MikroTik to send unauthenticated users to the Laravel portal.

Recommended redirect shape:

```text
https://your-app-domain.test/hotspot/portal?mac=$(mac)&nasid=$(identity)&link-login=$(link-login)&link-orig=$(link-orig)
```

The portal uses:

- `mac` to identify the customer device.
- `nasid` to find the router and shop.
- `link-login` for later MikroTik login handoff.
- `link-orig` to return the user to their original destination after login.

## Local Captive Redirect Test

When testing with the Laravel app on the Raspberry Pi LAN IP, use:

```text
http://192.168.190.244/hotspot/portal?mac=$(mac)&nasid=$(identity)&link-login=$(link-login)&link-orig=$(link-orig)
```

Allow the Pi before login:

```routeros
/ip hotspot walled-garden ip add dst-address=192.168.190.244 action=accept
```

Then replace the MikroTik hotspot `login.html` with this visible fallback template. It encodes MikroTik variables before redirecting, which prevents blank pages when the original URL contains its own query string.

```html
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opening hotspot portal</title>
</head>
<body style="font-family: system-ui, sans-serif; padding: 24px;">
    <h1>Opening internet access</h1>
    <p>If nothing happens, use the button below.</p>
    <p><a id="portal-link" href="#">Continue to internet packages</a></p>

    <script>
        var portal = 'http://192.168.190.244/hotspot/portal'
            + '?mac=' + encodeURIComponent('$(mac)')
            + '&nasid=' + encodeURIComponent('$(identity)')
            + '&link-login=' + encodeURIComponent('$(link-login)')
            + '&link-orig=' + encodeURIComponent('$(link-orig)');

        document.getElementById('portal-link').href = portal;
        window.location.replace(portal);
    </script>
</body>
</html>
```

If the portal shows "Router not registered", compare the received NAS ID with:

```routeros
/system identity print
```

Then create or update the router in HotspotFreeRAD so its NAS identifier exactly matches that value.

## Temporary Free Access Test

Before payment is added, the portal has a test button:

```text
Start free access
```

It creates:

- a customer row for the device MAC;
- a subscription row for the selected package;
- a `radcheck` row using the MAC as username;
- a `radusergroup` row binding the MAC to the package profile.

The temporary RADIUS password is:

```text
authenticated_device_pass
```

For the handoff page to post the phone back into MikroTik automatically, the Hotspot profile should allow PAP during this test phase:

```routeros
/ip hotspot profile set saas-prof login-by=http-pap,http-chap,cookie,mac-cookie
```

After pressing `Start free access`, the app posts back to `$(link-login)` with:

```text
username = device MAC
password = authenticated_device_pass
```

When real payment is added, this test path will be replaced with:

```text
Select package -> pay -> verified webhook -> provision access
```

## Important Notes

- Keep RADIUS ports `1812` and `1813` reachable only through the WireGuard tunnel or trusted LAN.
- Do not expose MySQL publicly unless you fully understand the security risk.
- If the app runs directly on the Raspberry Pi, `RADIUS_SERVER_IP` can usually be `127.0.0.1` or the WireGuard server IP `10.8.0.1`.
- If the app runs elsewhere and talks to the Pi database, still keep router RADIUS traffic pointed at the FreeRADIUS server, not necessarily the web app.
