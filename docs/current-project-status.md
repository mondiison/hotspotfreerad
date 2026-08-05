# HotspotFreeRAD Handover

Last updated: 2026-08-05

## Project

HotspotFreeRAD / MMS Radius is a Laravel 12, Livewire 4, Flux Pro SaaS for MikroTik + FreeRADIUS hotspot, voucher, PPPoE, POS-device, tenant billing, payments, and reporting.

Local path:

```text
C:\xampp\htdocs\HotspotFreeRAD
```

GitHub:

```text
https://github.com/mondiison/hotspotfreerad.git
```

Production/Pi path:

```text
/var/www/hotspotfreerad
```

Public domain:

```text
https://mmsradius.com
```

## Stack

- Laravel 12
- Livewire 4
- Flux + Flux Pro
- MySQL/MariaDB
- FreeRADIUS 3
- MikroTik RouterOS
- Flutterwave v4 for OPay/transfer and v3 secret key for card checkout
- Cloudflare Tunnel for public access from the Raspberry Pi

## Local Development

Use port `8001` for this project because other local projects may use `8000`.

```bash
cd C:\xampp\htdocs\HotspotFreeRAD
npm run dev
php artisan serve --host=127.0.0.1 --port=8001
```

Open:

```text
http://127.0.0.1:8001/login
```

If the browser keeps loading forever, check MySQL first. On 2026-08-03, local XAMPP MariaDB was hanging because the internal `mysql.db` table crashed. It was repaired with `aria_chk`, then MySQL was restarted.

Useful recovery commands:

```bash
php artisan optimize:clear
php artisan migrate
npm run build
```

## Deployment To Pi

After pushing changes locally:

```bash
cd /var/www/hotspotfreerad
git pull origin main
php artisan migrate
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Use `php artisan migrate` whenever new migrations are added.

## Current Features

- Multi-tenant admin and super-admin structure
- Tenant public slug/site and branding
- Tenant payment settings with Flutterwave
- Platform billing plans and platform payment settings
- Commission-based tenant billing support
- Captive portal package purchase flow
- Voucher generation, sale tracking, printable vouchers, voucher payment tracking
- Packages with uptime, bandwidth, total data, and FUP fields
- Router onboarding and MikroTik script generation
- Automatic router-to-Pi WireGuard connection: each router gets an app-generated keypair baked into its script, and a scheduled command (`hotspot:sync-wireguard-peers`) registers it as a Pi-side peer with no manual SSH step. See `docs/wireguard-server-setup.md` for the one-time Pi bootstrap this depends on.
- Trusted Wi-Fi Devices: MAC-based allowlisting for the Staff/Management SSIDs so the shared PSK alone isn't enough to join, synced to RADIUS and (for the MikroTik built-in Wi-Fi test profile) baked into the generated access-list. See `docs/staff-wifi-access.md`, including current limitations for external APs.
- Router monitoring: per-router historical bandwidth graphs (from existing RADIUS accounting), live bandwidth polling and on-demand Wi-Fi scanning (both over an auto-provisioned read-only RouterOS API user baked into the generated scripts), CPU/RAM/disk/hardware-health snapshots plus 5-minute-sampled CPU/RAM/latency history, and a tenant/shop/router topology view. See `docs/router-monitoring.md`, including honest caveats on Wi-Fi scan verification, hardware-health field variability, and the topology view's scope.
- Flexible router provisioning settings:
  - WAN 1 / WAN 2
  - AP/switch trunk
  - VLAN IDs
  - hotspot/staff/POS/PPPoE networks
  - Starlink-friendly PCQ limits
  - realtime voice/video QoS
  - optional second WAN
- PPPoE subscribers with RADIUS sync, renewal, expiry cleanup, and reporting
- POS device registry:
  - MAC address registration
  - package/renewal
  - RADIUS MAC-auth provisioning
  - expiry cleanup
  - dashboard POS Access Desk
- Security/profile:
  - 2FA
  - QR setup
  - passkeys
  - avatar upload/camera support
  - security activity reporting
- Sales, payments, expenses, budget watch, recurring expenses, and dashboard summaries

## Network Design Direction

Primary target setup:

```text
Starlink -> MikroTik RB5009 -> managed PoE switch/APs
```

Recommended VLANs:

```text
VLAN 10 - Management
VLAN 20 - Open hotspot/captive portal
VLAN 30 - Staff/admin Wi-Fi
VLAN 40 - PPPoE/CPE
VLAN 50 - POS devices
```

POS devices should use a password-protected POS SSID, usually VLAN 50, and be registered in HotspotFreeRAD by MAC address for renewal and expiry.

## Important Operational Notes

- Do not expose tenant/admin passwords. Tenant creation should send temporary password/reset flow.
- For Flux Pro composer auth, use the Flux license key, not the account password.
- Vite build may show an existing Flux CSS warning about `[snap="mandatory" &]`; build still succeeds.
- `APP_URL` must match the access domain/protocol. For public HTTPS, Livewire must generate HTTPS URLs to avoid mixed-content errors.
- Local `.env` currently expects `APP_URL=http://127.0.0.1:8001`.
- `WIREGUARD_MANAGE_PEERS` defaults to `false` everywhere. Only set it to `true` on the Pi, and only after completing `docs/wireguard-server-setup.md` (installs `wg`, creates the `wg-saas` server interface, and grants `www-data` a narrowly scoped sudoers rule for `wg`/`wg-quick`).
- The RouterOS API client (`evilfreelancer/routeros-api-php`) needs PHP's `ext-sockets` extension. It's enabled on this machine's local XAMPP now (`extension=sockets` uncommented in `php.ini`) but confirm it's present on the Pi too (`php -m | grep sockets`) — it's usually on by default on Linux `php-fpm` builds.
- `composer audit` currently reports 6 pre-existing advisories against `guzzlehttp/guzzle` (already a transitive dependency before router-monitoring work; unrelated to it). Worth a deliberate `composer update guzzlehttp/guzzle` pass and regression test, not fixed inline here since it wasn't part of the task that surfaced it.
- Two pre-existing, unrelated test failures were found while verifying nav/UX changes (confirmed via `git log` that neither file has been touched this recent work): `AdminExpenseTest` (uses a hardcoded past `incurred_on` date that's now outside whatever "current" filter the list defaults to) and `AdminSetupCenterTest`/`HotspotPortalTest` (assert on exact copy text that appears to have drifted from the actual page). Worth a cleanup pass.

## Next Good Work

- Add POS accounting/inspect modal similar to PPPoE.
- Add POS payment tracking when tenant sells/renews POS access.
- Improve router script generator with selectable AP vendor notes: MikroTik, Ruijie/Reyee, TP-Link Omada, Wavlink.
- Add second Starlink load-balancing/failover script profile.
- Extend the WireGuard peer sync (`hotspot:sync-wireguard-peers`) to allow each router's LAN/VLAN subnets through the tunnel (not just its own tunnel IP), so external APs can reach the Pi's RADIUS server for MAC authentication — see the limitation noted in `docs/staff-wifi-access.md`.
- Longer term: WPA2/3-Enterprise (802.1X/EAP) for Staff/Management Wi-Fi, giving each person their own revocable login instead of a MAC allowlist plus shared PSK. Would need FreeRADIUS EAP/cert setup and a CA cert distribution flow (`.mobileconfig` for iOS/macOS, a PowerShell import script for Windows) — MAC allowlisting is the interim step already shipped.
- Verify Wi-Fi scan session cleanup and the RouterOS API user-group policy flags against real hardware — see the caveats in `docs/router-monitoring.md`. Both are best-effort, untested against a physical MikroTik from this codebase.
- Physical network topology (LLDP/CDP neighbor discovery) as a richer alternative/addition to the current org-hierarchy topology view, if useful once routers actually have API credentials applied in the field.
- Add public MMS Radius homepage/pricing after core feature set stabilizes.
