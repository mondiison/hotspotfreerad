# Zero-touch phone-to-router provisioning

How a `tenant_admin` sets up a brand-new router using only the Flutter mobile
app and their phone, without ever seeing the generated RouterOS script or the
router's WireGuard/API credentials. This is the one way `tenant_admin` can
onboard a router at all today, since the web admin's technical router detail
page (generated scripts, credentials, the live console) is `super_admin`-only.

## Why this needs the phone at all

The Laravel backend can only ever reach a router that's already on the
WireGuard/ZeroTier tunnel (`RouterOsConnectionService::candidateHosts()` only
resolves `wireguard_internal_ip`/`zerotier_ip`). A factory-default router has
no tunnel yet and sits on its own local Wi-Fi (conventionally `192.168.88.1`)
that the backend server has no route to. Only a phone physically joined to
that Wi-Fi can reach it — so the one-time bootstrap push has to run on the
phone, not as a backend capability.

## The flow

1. **Add router** (in the app): pick a shop, name the router. The app calls
   `POST /api/router-provisioning` on the real backend. The backend creates a
   minimal `Router` row (`RouterManagementService::create()`, unchanged —
   its `Router::creating()` hook auto-generates a WireGuard keypair and API
   credentials the same way it always has) and returns the router plus the
   exact bootstrap script `MikroTikProvisioningService::generateBootstrapScript()`
   already generates for the web admin's manual-paste flow. The app never
   renders this script text anywhere; it's consumed purely programmatically.
2. **Connect to the router's Wi-Fi** (manual, by the admin, via their phone's
   normal OS Wi-Fi settings — the app only deep-links to that settings screen
   via the `app_settings` package). The admin taps "I'm connected" once done.
3. **Push setup** (automatic, over the local network): the app, over a
   second, separate `Dio` instance scoped to `https://192.168.88.1` with a
   `badCertificateCallback` accepting RouterOS's self-signed cert for *that
   one connection only*, authenticates via HTTP Basic Auth as RouterOS's own
   factory `admin` user (blank password) — never the `mmsradius-api` user this
   app provisions and manages long-term, which deliberately has no REST
   access at all. It confirms the router is RouterOS 7 (REST-only feature —
   see "RouterOS 6" below), then mirrors
   `RouterOsConnectionService::pushFreshInfrastructureScript()`'s existing
   "temporary `/system/script` object: add, run, delete regardless of
   outcome" pattern over REST instead of the binary API, using the
   confirmed-correct `policy=` token string
   (`MikroTikProvisioningService::SCRIPT_OBJECT_ALLOWED_POLICY_TOKENS`,
   `'read,write,test,sensitive,ftp'`). On success it calls
   `POST /api/router-provisioning/{router}/mark-pushed`.
4. **Wait** (the admin switches their phone back to normal Wi-Fi/data): the
   app polls `GET /api/router-provisioning/{router}/status` — a cheap,
   DB-only read of `bootstrap_pushed_at`/`auto_provisioned_at`, never a live
   RouterOS call. The existing `hotspot:auto-provision-routers` scheduled job
   (completely unchanged by this feature) retries this router every 5 minutes
   in the background and finishes the job the moment the new WireGuard tunnel
   actually comes up — the same automatic finish every other router already
   gets once it has API credentials and no `auto_provisioned_at` yet.

## Authorization

The three `api.router-provisioning.*` routes are deliberately never added to
`App\Support\StaffPermissions::ROUTE_PERMISSIONS` — that omission alone makes
them `tenant_admin`-only (and `super_admin`, redundantly): both roles bypass
that map entirely, and `tenant_staff` is denied by default for any route name
not explicitly listed there, the same precedent `admin.billing`/`admin.tenants`
already set. `Client`-role users are already denied every `api.*` route
outright by the reseller-model Phase 1 `canAccessRoute()` fix.

`store()` logs a `SecurityActivityService` event
(`router_bootstrap_script_issued`) — this is the one place in the system that
returns raw WireGuard/API secrets over the network, so it gets a durable
audit trail even though access is already tightly scoped.

## RouterOS 6

RouterOS 6 has no REST API at all, so a RouterOS 6 router keeps using today's
existing flow unchanged: `super_admin` views the generated bootstrap script
in the web admin and pastes it into the router's terminal by hand. Nothing
about the zero-touch flow applies there.

## Not yet confirmed against real hardware

Matching this codebase's honesty pattern for every other freshly-added
RouterOS interaction — this has only been verified as far as an Android
emulator with no real router present allows (the create-router call, the
audit log entry, and the designed "could not reach a router" error/retry
path). Still open against one real, factory-reset RouterOS 7 device:

- Whether the factory Wi-Fi-side interface actually exposes the REST/`www`
  service out of the box (plausible — factory default typically bridges
  Ethernet+Wi-Fi into one LAN with services bound to all interfaces — but
  unconfirmed).
- Whether a freshly-reset router demands an initial admin password be set
  before accepting any other REST request. If so, the push flow needs an
  extra, silent "set an initial password" REST call before the script push
  — the app would never surface this password to the admin either way.
- Whether re-running the push after a partial failure is safe. The bootstrap
  script has some guarded idempotency already, but a second full run could
  still hit "already exists" on a line that already succeeded; a soft
  warning (not a hard failure) is the right UI response if the tunnel comes
  up afterward anyway.

Given this codebase's own extensive history of RouterOS behaving differently
than documented, expect at least one round of live correction against real
hardware before this is genuinely done — "builds and the emulator flow
works" is not "done."
