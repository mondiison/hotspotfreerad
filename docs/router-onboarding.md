# Router Onboarding Guide

This guide explains the values needed when adding a MikroTik router in HotspotFreeRAD.

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

If the Raspberry Pi is connected to the same MikroTik router you are configuring, the Pi may show two IP addresses:

```text
192.168.190.244 10.8.0.1
```

In this case:

- `192.168.190.244` is the Pi's LAN IP on the MikroTik network.
- `10.8.0.1` is the Pi's WireGuard tunnel IP.

For this first local MikroTik router, use the Pi LAN IP as the WireGuard peer endpoint:

```routeros
/interface wireguard peers set peer1 endpoint-address=192.168.190.244 endpoint-port=13231
```

Then test:

```routeros
/ping 10.8.0.1 interface=wg-saas
```

Using the public IP from inside the same MikroTik network can fail if the router does not support NAT loopback/hairpin for UDP traffic.

For remote tenant routers outside this LAN, use the public IP or Dynamic DNS hostname:

```routeros
/interface wireguard peers set peer1 endpoint-address=YOUR_PUBLIC_IP_OR_DDNS endpoint-port=13231
```

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

## First Router Checklist

1. Add a tenant in HotspotFreeRAD.
2. Add a shop for that tenant.
3. Add a router with:
   - NAS identifier: `demo-shop-01`
   - WireGuard internal IP: `10.8.0.10`
   - RADIUS shared secret: generated random value
4. Open the router's Script page.
5. Review `RADIUS_SERVER_IP`, `WIREGUARD_ENDPOINT_HOST`, and `WIREGUARD_PUBLIC_KEY` in `.env`.
6. Paste the generated script into MikroTik RouterOS terminal.
7. Confirm FreeRADIUS sees the router in the `nas` table.
8. Test authentication with FreeRADIUS debug mode:

```bash
sudo freeradius -X
```

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

Then replace the MikroTik hotspot `login.html` with:

```html
<!doctype html>
<html>
<head>
    <meta http-equiv="refresh" content="0; url=http://192.168.190.244/hotspot/portal?mac=$(mac)&nasid=$(identity)&link-login=$(link-login)&link-orig=$(link-orig)">
</head>
<body>
    <script>
        window.location.href = "http://192.168.190.244/hotspot/portal?mac=$(mac)&nasid=$(identity)&link-login=$(link-login)&link-orig=$(link-orig)";
    </script>
</body>
</html>
```

If the portal shows "Router not registered", compare the received NAS ID with:

```routeros
/system identity print
```

Then create or update the router in HotspotFreeRAD so its NAS identifier exactly matches that value.

## Important Notes

- Keep RADIUS ports `1812` and `1813` reachable only through the WireGuard tunnel or trusted LAN.
- Do not expose MySQL publicly unless you fully understand the security risk.
- If the app runs directly on the Raspberry Pi, `RADIUS_SERVER_IP` can usually be `127.0.0.1` or the WireGuard server IP `10.8.0.1`.
- If the app runs elsewhere and talks to the Pi database, still keep router RADIUS traffic pointed at the FreeRADIUS server, not necessarily the web app.
