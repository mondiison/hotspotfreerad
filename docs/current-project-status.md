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
composer install --no-dev --optimize-autoloader
php artisan migrate
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Use `php artisan migrate` whenever new migrations are added. **Always run `composer install` on every deploy, not just when you remember `composer.json` changed** — a `git pull` updates `composer.lock` but never touches `vendor/`, so any PHP dependency added since the Pi's last `composer install` silently stays missing until something on a live request tries to autoload it (e.g. `Class "ParagonIE_Sodium_Compat" not found` when `paragonie/sodium_compat` was pulled in but never installed). `composer install` is fast and a no-op when nothing changed, so there's no cost to always including it.

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
- Lighter router setup: a minimal Bootstrap Script (identity + WireGuard + API user only) is the one thing pasted by hand on a new router; the rest of the hotspot or PPPoE config can then be pushed live with a "Provision via API" button once the router is reachable. See `docs/router-monitoring.md` (Bootstrap script + API-push provisioning).
- Read-only RouterOS console (Router Script page → Live tab): type any `print` command and see live results, using the same read-only API user as the rest of monitoring — no write access is possible. See `docs/router-monitoring.md` (Read-only console).
- Standalone Script Generator (`admin/tools/script-generator`, super admin only, 2026-08-07, revised 2026-08-08 from network-engineer feedback + MikroTik doc research): a self-contained wizard for configuring any MikroTik router as a hotspot without adding it to the platform at all — no WireGuard, no HotspotFreeRAD RADIUS, no shop/tenant. Handles both RouterOS 6 and 7 (different wireless syntax) and wireless vs wired-only hardware via plain capability toggles (no hardcoded router-model catalog to maintain), auto-bridges every Ethernet port except WAN, and separates "Router identity" from "Hotspot name". Voucher validity now starts at first login, not creation: RouterOS 7 uses the built-in User Manager as a self-hosted local RADIUS server (`starts-when=first-auth`); RouterOS 6 uses an on-login scheduler script (best-effort, verify on real hardware). When the management network is enabled, the router's own www/www-ssl/winbox/ssh services are locked to that subnet, and admins are pointed at RouterOS's own WebFig/User Manager web UI for ongoing voucher generation rather than a custom "web Winbox" — RouterOS's REST API has no CORS support and this app's backend can't reach a standalone router's private network without a tunnel, so rebuilding a live management UI here wasn't the right call. The result page offers a copy/download of the `.rsc` script plus a printable voucher sheet (grid, compact, or receipt template, with optional code prefix/length/character set) in one stateless flow — nothing is saved to the database. Each voucher's username and password can be the same or independently generated per profile, and the character set (safe alphanumeric, numeric-only, letters-only, or full case-sensitive) is also chosen per profile, matching MikroTik's own batch-voucher dialog; print templates show both credentials, combined into one line when they're equal. See the `App\Services\StandaloneHotspotScriptGenerator` note in `CLAUDE.md`.
- Staff accounts (`tenant_staff` role): tenant admins can create limited-access staff logins (cashier, support, network tech) scoped to specific feature areas (vouchers, POS, PPPoE, customers, network, payments, expenses, reports) instead of sharing the admin password or granting full tenant-admin access. Enforced deny-by-default via `App\Http\Middleware\AuthorizeTenantStaff` on the whole admin route group. See `docs/roles-and-product-direction.md` (Staff).
- Router health alerts: edge-triggered notifications (offline/back online/high CPU/high RAM) to super admins and the owning tenant's admins, always shown in the admin notification bell, with email as a per-user opt-in toggle on the Profile page. Delivered through the queue (existing `hotspotfreerad-worker` service), not a cron-triggered send.
- Flexible router provisioning settings, via a 4-step wizard (2026-08-08, replacing a single flat form): Identity → Hardware (built-in Wi-Fi on/off, port count, WAN1/WAN2/trunk/Pi port pickers, or an advanced manual-entry fallback for non-`etherN` interfaces) → Features (POS/PPPoE/QoS toggles, wireless credentials shown only when Wi-Fi is on) → Network plan (VLANs, gateways, networks, DHCP pools, now including previously-missing Staff network inputs). `admin/routers/create` and `/edit` redirect into this same wizard instead of a separate full page. The old `l009_builtin_wifi` preset (named after a specific MikroTik board) is gone — built-in Wi-Fi is its own toggle, independent of the bandwidth/VLAN template.
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
- When a hotspot customer skips the optional email field, every gateway service falls back to a synthetic `guest-{payment_id}@` address via `App\Support\GuestCustomerEmail`. Confirmed live on 2026-08-06: it previously used `@hotspot.local`, which Squad's checkout API (and likely others with strict validators) rejected outright with `"email" must be a valid email`, since `.local` isn't a real IANA-registered TLD. It now uses this app's own domain (from `APP_URL`), falling back to `example.com` in local dev where `APP_URL` has no real domain.
- Squad and Monnify each have a per-shop Live/Test "Environment" toggle in Payment Setup now (2026-08-06) instead of a single platform-wide `.env` base URL — confirmed live after a shop's Squad checkout timed out hitting the sandbox API with a live-looking key and no way to tell which environment was actually configured. Same fix pass also closed a real data-loss bug where saving *any* gateway_settings field (even just switching Environment) silently wiped every other field on that gateway that wasn't retyped in the same save — see the `PaymentSettingsService::updates()` note in `CLAUDE.md`.
- Paystack and Stripe (2026-08-06) also have an Environment dropdown now, for consistency with Squad/Monnify, plus a read-only "Detected: Live/Test key" badge next to their Secret Key field. Since both only have one API host, the dropdown doesn't change any base URL — the `sk_test_`/`sk_live_` key prefix is the real source of truth, and a warning shows if the saved dropdown value disagrees with the key. See the `PaymentGatewayCatalog::detectedEnvironment()` note in `CLAUDE.md`.
- The Shops page (2026-08-06) no longer has its own hardcoded, Flutterwave-only credential fields — editing an existing shop now embeds the same `PaymentSettingsCard` used on Payment Setup (all gateways, environment included) directly in its edit view/flyout. New shops must be saved once before payment settings can be configured. See the `App\Livewire\Admin\PaymentSettingsCard` note in `CLAUDE.md`.
- For Flux Pro composer auth, use the Flux license key, not the account password.
- Vite build may show an existing Flux CSS warning about `[snap="mandatory" &]`; build still succeeds.
- `APP_URL` must match the access domain/protocol. For public HTTPS, Livewire must generate HTTPS URLs to avoid mixed-content errors.
- Local `.env` currently expects `APP_URL=http://127.0.0.1:8001`.
- `WIREGUARD_MANAGE_PEERS` defaults to `false` everywhere. Only set it to `true` on the Pi, and only after completing `docs/wireguard-server-setup.md` (installs `wg`, creates the `wg-saas` server interface, and grants `www-data` a narrowly scoped sudoers rule for `wg`/`wg-quick`).
- The RouterOS API client (`evilfreelancer/routeros-api-php`) needs PHP's `ext-sockets` extension. It's enabled on this machine's local XAMPP now (`extension=sockets` uncommented in `php.ini`) but confirm it's present on the Pi too (`php -m | grep sockets`) — it's usually on by default on Linux `php-fpm` builds.
- `composer audit` currently reports 6 pre-existing advisories against `guzzlehttp/guzzle` (already a transitive dependency before router-monitoring work; unrelated to it). Worth a deliberate `composer update guzzlehttp/guzzle` pass and regression test, not fixed inline here since it wasn't part of the task that surfaced it.
- Two pre-existing, unrelated test failures were found while verifying nav/UX changes (confirmed via `git log` that neither file has been touched this recent work): `AdminExpenseTest` (uses a hardcoded past `incurred_on` date that's now outside whatever "current" filter the list defaults to) and `AdminSetupCenterTest`/`HotspotPortalTest` (assert on exact copy text that appears to have drifted from the actual page). Worth a cleanup pass.
- The hotspot captive portal (`resources/views/hotspot/portal.blade.php`) now shows the shop's actual active payment gateway name/logo at the point of package selection, and the generated router scripts (`generateScript()`/`generateFreshInfrastructureScript()`) now add walled-garden entries for that gateway's hosted-checkout domain, not just Flutterwave's — see the Payment Gateways note in `CLAUDE.md`. The per-gateway domain lists are best-effort and not verified against a live checkout on real hardware.
- (2026-08-07) A live report of Squad checkout failing with `net::ERR_CONNECTION_CLOSED` traced to routers never getting re-provisioned after a gateway switch — walled-garden entries were only ever written at script-generation/manual-provision time, with no re-sync when `payment_gateway` changed later. Fixed two ways: `RouterOsConnectionService::syncWalledGarden()` (extracted from `provisionHotspot()`, which previously pushed only the portal host over the API, not the gateway's) is now also called automatically via a new queued `App\Jobs\SyncRouterWalledGarden` job dispatched from `PaymentSettingsService::update()` whenever a shop's saved gateway actually changes, for every router that shop owns. See the Payment Gateways note in `CLAUDE.md` for the underlying HTTPS-walled-garden mechanism.
- The queued fix above only helps a shop that's *changing* gateway going forward, and only once a queue worker is actually draining the `default` queue — locally, `.env` has `QUEUE_CONNECTION=database` and nothing runs `queue:work`/`queue:listen` unless you use `composer run dev`, so a dispatched job just sits in the `jobs` table (`php artisan tinker` → `DB::table('jobs')->count()` to check). For a router whose shop was already on the broken gateway before this fix shipped, or to force a resync without touching the queue at all, use the new `hotspot:resync-walled-garden {router?} {--all}` Artisan command — synchronous, no worker required.
- (2026-08-07) Running that command surfaced a much bigger, pre-existing bug: it reported "walled garden synced" but nothing changed on the real router. Root cause was two-fold — the RouterOS API user's policy denied `write` entirely (it was scoped read-only, for monitoring), so RouterOS rejected every write `provisionHotspot()`/`provisionPppoe()`/`syncWalledGarden()` issued; and `evilfreelancer/routeros-api-php`'s `Client::read()` parses a rejected command (`!trap`) the same as a successful one (`!done`) and never throws, so the rejection was silently reported as success. This means **"Provision via API" has likely never actually written anything to a real router** with the documented policy, for any gateway, since the feature shipped. Fixed: the policy now grants `write`, and `RouterOsConnectionService::runSteps()`/`applyHotspotProfile()` now detect a `!trap` response (`extractTrapMessage()`) and report a genuine failure instead. A router bootstrapped before this fix still has the old policy baked in — its script needs re-running before any API write will actually succeed. See the router-monitoring note in `CLAUDE.md`.
- (2026-08-08) Added a second standalone super-admin tool, "PTP Radio Generator" (`admin/tools/ptp-generator`, `App\Services\StandalonePtpScriptGenerator` / `App\Livewire\Admin\StandalonePtpGenerator`), for configuring a MikroTik point-to-point wireless bridge link across RouterOS 6/7 — see the `CLAUDE.md` note next to the existing Script Generator paragraph. Fully self-contained like the hotspot tool (no DB persistence, no Router/WireGuard binding); produces two matching scripts (one per radio) instead of one. `tests/Unit/StandalonePtpScriptGeneratorTest.php` and `tests/Feature/StandalonePtpGeneratorTest.php` cover it.
- (2026-08-08) While verifying the PTP tool, confirmed (in isolation) that 3 pre-existing `AdminDashboardTest` failures — `dashboard shows current month finance summary`, `dashboard shows payment health for current month`, `tenant launch checklist points payment to shop creation before shops e...` — are unrelated to this or any recent work (file untouched since 2026-08-03, failures reproduce with zero other changes present). Likely the same category as the other pre-existing date-drift failures noted above; worth a cleanup pass.
- (2026-08-08) Two follow-up fixes to the PTP tool from real hardware feedback (a live "AP mode not supported" error on an SXTsq Lite5, a CPE-class dish radio): "bridged" link mode was creating no actual RouterOS bridge interface (only flipped the wireless station mode), fixed via `bridgeLines()`; and the AP end was unconditionally using `ap-bridge` (RouterOS's multi-client AP mode, gated behind a higher wireless license tier) when a point-to-point link only ever has one peer and should use `bridge` mode (single-peer, included in the base/CPE license tier CPE hardware ships with). Added `link_topology` (`'ptp'`/`'ptmp'`) so the tool can also generate a true point-to-multipoint hub AP (`ap-bridge`) when that's actually wanted — re-run the wizard once per additional station against the same AP. See the `CLAUDE.md` note.

## Next Good Work

- Add POS accounting/inspect modal similar to PPPoE.
- Add POS payment tracking when tenant sells/renews POS access.
- Improve router script generator with selectable AP vendor notes: MikroTik, Ruijie/Reyee, TP-Link Omada, Wavlink.
- Add second Starlink load-balancing/failover script profile.
- Extend the WireGuard peer sync (`hotspot:sync-wireguard-peers`) to allow each router's LAN/VLAN subnets through the tunnel (not just its own tunnel IP), so external APs can reach the Pi's RADIUS server for MAC authentication — see the limitation noted in `docs/staff-wifi-access.md`.
- Longer term: WPA2/3-Enterprise (802.1X/EAP) for Staff/Management Wi-Fi, giving each person their own revocable login instead of a MAC allowlist plus shared PSK. Would need FreeRADIUS EAP/cert setup and a CA cert distribution flow (`.mobileconfig` for iOS/macOS, a PowerShell import script for Windows) — MAC allowlisting is the interim step already shipped.
- Verify Wi-Fi scan session cleanup against real hardware — see the caveat in `docs/router-monitoring.md`. Still best-effort, untested from this codebase. (The RouterOS API user-group policy flags were verified and fixed against a real RouterOS 7.18.2 router on 2026-08-06 — see the same doc.)
- Verify the per-gateway walled-garden host lists in `PaymentGatewayCatalog::walledGardenHosts()` against a real checkout flow on physical hardware for each gateway (Paystack, Monnify, Squad, Stripe) — the domains are based on public provider docs, not confirmed traffic captures from this codebase.
- Physical network topology (LLDP/CDP neighbor discovery) as a richer alternative/addition to the current org-hierarchy topology view, if useful once routers actually have API credentials applied in the field.
- Add public MMS Radius homepage/pricing after core feature set stabilizes.
