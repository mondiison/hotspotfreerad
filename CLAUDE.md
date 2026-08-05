# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

HotspotFreeRAD (a.k.a. MMS Radius) is a multi-tenant SaaS for MikroTik hotspot/PPPoE billing built on Laravel 12 + Livewire 4 + Flux/Flux Pro, backed by FreeRADIUS 3 over a shared MySQL/MariaDB `radius` database. Tenants (ISPs/hotspot shop owners) manage shops, routers, packages, customers, vouchers, PPPoE subscribers, and POS devices; customers pay through a MikroTik captive portal to get provisioned into FreeRADIUS.

Read `docs/current-project-status.md` first in any new session — it is the up-to-date handover doc (stack, local dev quirks, deployment steps, "next good work"). `docs/roles-and-product-direction.md`, `docs/deployment-architecture.md`, `docs/router-onboarding.md`, and `docs/raspberry-pi-deployment.md` cover product rules and infra in depth. `README.md` and the root blueprint markdown are older/less current than `docs/current-project-status.md` — prefer the docs folder when they conflict.

## Commands

Local dev runs on port **8001** (other local projects use 8000):

```bash
php artisan serve --host=127.0.0.1 --port=8001
npm run dev            # Vite
```

Or run everything (server, queue listener, logs, vite) at once:

```bash
composer run dev
```

Tests:

```bash
php artisan test                                  # full suite
php artisan test --filter=AdminPaymentIndexTest    # single test class
php artisan test tests/Feature/HotspotPortalTest.php
```

Test env uses in-memory SQLite (`phpunit.xml`), not the real `radius` MySQL DB — safe to run anytime.

Build/lint:

```bash
npm run build          # production assets
vendor/bin/pint         # Laravel Pint code style (composer require-dev)
```

Useful recovery/maintenance commands (see `docs/current-project-status.md`):

```bash
php artisan optimize:clear
php artisan migrate
```

Custom Artisan commands live inline in `routes/console.php` (there is no `app/Console/Commands` directory — this is a Laravel 12 skeleton app). Notable ones: `hotspot:create-super-admin`, `hotspot:create-tenant-admin`, `hotspot:sync-expired-hotspot`, `hotspot:sync-expired-pppoe`, `hotspot:sync-expired-pos`, `hotspot:prune-security-activity`, `hotspot:backfill-voucher-payments`, `hotspot:scheduler-heartbeat`, `hotspot:sync-wireguard-peers`. All but the first three are also registered on `Schedule::` in the same file (5-minute RADIUS expiry sync, 5-minute WireGuard peer sync, daily audit-log prune, per-minute scheduler heartbeat used by the Setup Center health check).

## Architecture

### Two separate traffic paths — keep them mentally distinct

1. **Customer captive-portal path**: customer device → MikroTik → HTTPS redirect → `App\Http\Controllers\Hotspot\PortalController` (`routes/web.php`, `/hotspot/*`) → payment gateway → RADIUS provisioning.
2. **Router auth/accounting path**: MikroTik → WireGuard tunnel → FreeRADIUS 3 → the same MySQL `radius` database (`nas`, `radcheck`, `radreply`, `radacct`, `radusergroup`, `radgroupreply`, `radgroupcheck` tables). Laravel never proxies RADIUS traffic itself — it only writes rows FreeRADIUS reads.

`App\Services\RadiusProvisioningService` and `App\Services\MikroTikProvisioningService` are the bridge: package/subscriber changes in the app get mirrored into the `radcheck`/`radusergroup`/`radgroupreply`/`nas` tables, and router onboarding generates a RouterOS script from stored provisioning settings.

Router-to-Pi WireGuard connectivity is app-managed, not manual: `App\Models\Router` auto-generates a WireGuard keypair on creation (`App\Support\WireGuardKeyPair`, via `paragonie/sodium_compat` so it works without the native sodium extension), the private key is baked into the generated script instead of letting RouterOS pick its own, and `App\Services\WireGuardPeerSyncService` (run via the `hotspot:sync-wireguard-peers` scheduled command) reconciles the Pi's live WireGuard peers from the `routers` table. That command is inert everywhere by default (`WIREGUARD_MANAGE_PEERS=false`) and only does anything once enabled on the Pi per `docs/wireguard-server-setup.md`.

Staff/Management Wi-Fi (WPA-PSK SSIDs, distinct from the open customer hotspot) gets a second access control layer beyond the shared password: `App\Models\TrustedWifiDevice` (admin: `admin/trusted-wifi-devices`) registers devices by MAC per shop/network (`staff`|`mgmt`), synced into `radcheck` via `RadiusProvisioningService::provisionTrustedWifiDevice()`. For the MikroTik built-in Wi-Fi test profile (`l009_builtin_wifi`), the script generator also emits a local `/interface wifi access-list` allow/deny list from the same registered devices — regenerated on demand, not live-pushed to the router. See `docs/staff-wifi-access.md` for the external-AP RADIUS MAC-auth path and its current limitations.

Live router monitoring (as opposed to the WireGuard/RADIUS provisioning above) needs to talk to the router directly, not just write RADIUS rows. `App\Models\Router` also auto-generates a read-only RouterOS API user/password on creation (`api_username`/`api_password`, mirroring the WireGuard keypair pattern), baked into the generated scripts by `MikroTikProvisioningService::apiUserProvisioningLines()` and restricted to the WireGuard subnet only. `App\Services\RouterOsConnectionService` (wrapping `evilfreelancer/routeros-api-php`, which needs `ext-sockets`) is the single place that opens a live RouterOS API connection, always with short timeouts so an offline router fails fast instead of hanging a request. Historical bandwidth graphs (`App\Support\RouterUsageHistory`) need none of this — they read existing `radacct` data. CPU/RAM/disk/health/uptime history (`router_metric_samples`, populated every 5 minutes by `hotspot:sample-router-metrics` / `App\Services\RouterMetricSamplingService`) mixes both: latency is a plain ICMP ping needing no RouterOS API at all, while CPU/RAM/disk/health do. See `docs/router-monitoring.md`, including the honest caveats on Wi-Fi scan session cleanup, hardware-health field variability, and the topology view's deliberately-limited scope (org hierarchy, not physical LLDP discovery).

### Tenant scoping is manual, not a global scope

There is no Eloquent global scope for tenancy. Every tenant-scoped query/authorization goes through the static helpers in `app/Support/TenantAccess.php` (`scopeShops`, `scopeRouters`, `scopePackages`, `assertShop`, `assertRouter`, etc.), each of which special-cases `$user->isSuperAdmin()` to bypass the `tenant_id`/`shop.tenant_id` filter. When adding a new tenant-owned resource, add a matching `scopeX`/`assertX` pair here rather than filtering ad hoc in the controller. Controllers also gate super-admin-only actions with inline `abort_unless(request()->user()->isSuperAdmin(), 403)` (e.g. `TenantController`) — there are no route-level role middleware groups; authorization happens inside controllers/services.

Two roles only: `super_admin` (platform owner, no `tenant_id`) and `tenant_admin` (scoped to one `tenant_id`). See `docs/roles-and-product-direction.md` for the full rule set.

### Request flow: thin controllers, fat services

`app/Http/Controllers/Admin/*` and `app/Livewire/Admin/*` stay thin and delegate business logic to `app/Services/*ManagementService.php` (e.g. `PackageManagementService`, `RouterManagementService`, `PppoeSubscriberManagementService`, `PosDeviceManagementService`, `VoucherManagementService`). Reporting has its own service layer (`SalesReportService`, `PaymentReportService`, `SubscriptionReportService`, `SecurityActivityReportService`). Livewire is used for interactive index/filter/table components; plain controllers handle CRUD resources and exports.

### Payment gateways

Two independent gateway stacks exist, both selected/configured per entity via `App\Support\PaymentGatewayCatalog`:

- **Tenant/shop-level hotspot checkout** (customer paying for hotspot/PPPoE/POS access): `FlutterwaveService`, `PaystackService`, `MonnifyService`, `SquadService`, `StripeService`, `ManualBankTransferService`, orchestrated by `App\Services\Payments\HotspotHostedCheckoutManager` implementing `App\Services\Payments\Contracts\HotspotHostedGateway` per provider, confirmed via `HotspotPaymentConfirmationService`. Entry points are the `/hotspot/pay`, `/hotspot/payment/*` routes on `PortalController`.
- **Platform billing** (tenant paying HotspotFreeRAD for their subscription plan): `PlatformFlutterwaveService`, `PlatformStripeService`, confirmed via `PlatformBillingConfirmationService`. Entry points are the `/billing/*` routes on `Admin\BillingController`.

Webhook routes (`/hotspot/payment/webhook`, `/billing/payment/webhook`) are explicitly excluded from CSRF (`withoutMiddleware([ValidateCsrfToken::class])`) since gateways POST to them directly — keep that exclusion narrow if adding new webhook routes.

### Packages and RADIUS attribute mapping

Packages carry bandwidth, uptime, and optional data caps/FUP. When synced to RADIUS: `Mikrotik-Rate-Limit` = bandwidth, `Session-Timeout` = uptime (seconds), `Mikrotik-Total-Limit`/`Mikrotik-Total-Limit-Gigawords` = data quota. Data values are stored/compared in raw bytes (see unit table in `docs/roles-and-product-direction.md`). `NAS-Identifier` on the router must exactly match the router's stored identity — mismatches are the most common "router not registered" support issue.

### Expiry/cleanup model

Hotspot subscriptions, PPPoE subscribers, and POS devices are each revoked from RADIUS by a scheduled command (see Commands section) rather than by a live TTL in FreeRADIUS — the `Schedule::command(...)->everyFiveMinutes()` jobs in `routes/console.php` are what actually cut off expired access. If a feature depends on "access should end at time X," it needs a corresponding scheduled sync, not just an `expires_at` column.

## Known environment quirk

Local XAMPP MariaDB has previously hung due to a crashed `mysql.db` table; if the app hangs on load, check MySQL first and repair with `aria_chk` before assuming an app bug (see `docs/current-project-status.md`).

## Real infra values are not in this repo

`mondiison/hotspotfreerad` is a **public** GitHub repo, so real WireGuard endpoints, public keys, and RADIUS server IPs must never be committed here — the docs in `docs/` intentionally use placeholder values. The actual live values (WireGuard endpoint/port/public key, RADIUS server IP, known `.env` gotchas) are recorded locally in `.local-notes/infra-settings.md`, which is git-ignored. Check that file before re-deriving these from scratch or asking the user to repeat them; it only exists on this machine, not on the Pi or a fresh clone.
