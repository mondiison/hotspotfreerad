# ZeroTier Fallback Tunnel Setup (One-Time, On The Pi)

This is the one manual step for enabling ZeroTier as a fallback (or replacement) for WireGuard on routers that can't reach the Pi over WireGuard. Do this once per Pi/server, not once per router.

## Why this exists

WireGuard breaks for any router whose site is behind carrier-grade NAT (CGNAT) — common on Starlink and many mobile/4G/5G home internet plans (see the CGNAT warning in `docs/wireguard-server-setup.md`). WireGuard has no relay/fallback mechanism of its own: if the Pi's own connection is CGNAT'd, port-forwarding is silently useless and remote routers can never form a tunnel, no matter how the router-side script is configured.

ZeroTier is the fix specifically because it has built-in NAT traversal, including automatic relay fallback when a direct peer-to-peer connection can't be established. RouterOS has had native ZeroTier support since 7.5+.

**Hardware caveat, first and plain**: ZeroTier on RouterOS is a separate installable package (`.npk`, upload + reboot) restricted to **ARM/ARM64 hardware only** — many common/budget MikroTik boards (MIPSBE/SMIPS) can't run it at all. This is why `tunnel_mode` is a per-router opt-in in the router wizard, never assumed available — the same established pattern as the "Built-in Wi-Fi" capability toggle (`docs/router-onboarding.md`), not a hardcoded hardware/model database.

## Why a self-hosted controller, not ZeroTier Central

ZeroTier's own cloud service ("Central") is the default way most people use ZeroTier, but its **free plan has a hard 10-device cap and no API access at all** — automating node authorization via Central's REST API requires the paid Essential plan ($18+/mo). Since this app needs to authorize routers programmatically (mirroring how `WireGuardPeerSyncService` already automates WireGuard peer registration), this setup instead runs a **self-hosted ZeroTier network controller on the Pi itself**. This is free, has no device cap, and its local API (`http://127.0.0.1:9993/controller/...`, loopback-only) needs no ZeroTier account or subscription of any kind — it's authenticated with the Pi's own `zerotier-one` service's local auth-token file.

RouterOS doesn't care whether the network it joins is Central-hosted or self-hosted — a network ID always embeds its controller's own node ID, so nothing about router-side script generation changes based on this choice.

## 1. Install ZeroTier on the Pi

```bash
curl -s https://install.zerotier.com | sudo bash
sudo systemctl enable --now zerotier-one
```

## 2. Find the Pi's own ZeroTier node ID

```bash
sudo zerotier-cli info
```

This prints something like `200 info <node-id> ONLINE ...` — the `<node-id>` (10 hex characters) is what the network ID is built from.

## 3. Create the self-hosted network

A ZeroTier network ID is the controller's own node ID plus 6 more hex characters you choose. Create the network via the controller's local API (the auth token file below is created automatically once `zerotier-one` has started):

```bash
sudo cat /var/lib/zerotier-one/authtoken.secret
```

```bash
# Replace <node-id> with the value from step 2, and choose any 6 hex characters for the suffix.
curl -s -X POST "http://127.0.0.1:9993/controller/network/<node-id>______" \
  -H "X-ZT1-Auth: $(sudo cat /var/lib/zerotier-one/authtoken.secret)" \
  -d '{
    "name": "hotspotfreerad",
    "private": true,
    "ipAssignmentPools": [],
    "v4AssignMode": {"zt": false}
  }'
```

`"private": true` is required — a public network lets any node join without authorization at all, defeating the entire point of this setup. `ipAssignmentPools`/`v4AssignMode.zt` are left off since this app pushes each router's IP assignment explicitly at authorization time (see `ZeroTierControllerService::authorizeMember()`), not through ZeroTier's own auto-assignment.

The resulting 16-character network ID (`<node-id>` + your chosen suffix) is what goes into `.env` as `ZEROTIER_NETWORK_ID` below.

## 4. Join the Pi itself to the network and give it a fixed IP

```bash
sudo zerotier-cli join <network-id>
```

Authorize the Pi's own node the same way a router gets authorized later (replace `<pi-node-id>` with the value from step 2, `<network-id>` with the ID from step 3, and pick a fixed IP for the Pi — `10.9.0.1` is a reasonable convention, matching WireGuard's own `10.8.0.1`):

```bash
curl -s -X POST "http://127.0.0.1:9993/controller/network/<network-id>/member/<pi-node-id>" \
  -H "X-ZT1-Auth: $(sudo cat /var/lib/zerotier-one/authtoken.secret)" \
  -d '{"config": {"authorized": true, "ipAssignments": ["10.9.0.1"]}}'
```

Confirm with `zerotier-cli listnetworks` — the Pi should show `OK` and the assigned IP.

## 5. Set `.env` on the Pi

```env
ZEROTIER_CONTROLLER_URL=http://127.0.0.1:9993
ZEROTIER_AUTH_TOKEN_PATH=/var/lib/zerotier-one/authtoken.secret
ZEROTIER_NETWORK_ID=<the 16-character network ID from step 3>
ZEROTIER_PI_IP=10.9.0.1
ZEROTIER_IP_PREFIX=10.9.0
ZEROTIER_MANAGE_MEMBERS=true
```

`ZEROTIER_MANAGE_MEMBERS` is `false` by default everywhere (including local dev), the same guard `WIREGUARD_MANAGE_PEERS` uses — the sync command is a safe no-op until this is explicitly turned on. Only set it to `true` on the Pi, after completing this doc.

## The node-ID bootstrapping limitation (read this before onboarding a router)

WireGuard automation works because the app generates a router's keypair *before* the router ever connects and bakes it into the script — the Pi can pre-authorize a peer centrally, with no coordination needed from the router side. ZeroTier is the opposite: a router generates its own ZeroTier node identity locally, the first time `/zerotier enable` runs on it — this app has no way to predict that identity in advance. On the private network created in step 3, a newly-joined node sits **unauthorized** until explicitly approved, and this app has no way to know which HotspotFreeRAD router a given unauthorized node ID belongs to without being told.

Two ways to resolve it, both handled by the router wizard's ZeroTier fields:

- **Router still has working WireGuard** (`tunnel_mode = wireguard_zerotier`, being set up normally): click **"Fetch over WireGuard"** in the wizard — the app runs `/zerotier print` over the still-functional WireGuard link and reads the node ID back automatically.
- **Router has no working WireGuard at all** (`tunnel_mode = zerotier`, e.g. a site already known to be behind CGNAT): there's no channel for the app to query the router remotely. Run `/zerotier print` on the router's own console (Winbox/SSH, physically or via whatever local access you have) after `/zerotier enable` has run, and paste the node ID into the wizard by hand.

Either way, once a node ID is saved against a router, `hotspot:sync-zerotier-members` (scheduled every 5 minutes, same as `hotspot:sync-wireguard-peers`) authorizes it automatically and keeps doing so — this limitation is strictly a *first-time onboarding* step, not an ongoing one.

## `hotspot:sync-zerotier-members` and unmatched nodes

```bash
cd /var/www/hotspotfreerad
sudo -u www-data php artisan hotspot:sync-zerotier-members --dry-run
```

This authorizes every router that has a saved `zerotier_node_id` but isn't yet authorized on the controller. If the controller reports a node this app can't attribute to any router (a node ID that showed up on the network but was never entered against a `Router` record — a typo, a stray device, or an admin who forgot to save the node ID), the command prints it under "Unmatched nodes on the controller" rather than silently ignoring it, so there's something concrete to investigate instead of a mystery pending member sitting in the controller forever.

## Troubleshooting

- **`ZeroTier controller auth token not readable`** — confirm `ZEROTIER_AUTH_TOKEN_PATH` matches the real path (`/var/lib/zerotier-one/authtoken.secret` on most Debian/Raspbian installs) and that the web server/queue user (`www-data`) can read it (`sudo -u www-data cat /var/lib/zerotier-one/authtoken.secret`) — this file is usually root-only by default and may need its permissions loosened for that one user, similar in spirit to the WireGuard sudoers grant in `docs/wireguard-server-setup.md`.
- **Node stuck unauthorized after `hotspot:sync-zerotier-members`** — confirm the node ID saved on the router record in HotspotFreeRAD exactly matches what `/zerotier print` shows on the router itself; a typo here means the sync command is authorizing a *different* (nonexistent) node ID and the real one just sits pending.
- **RADIUS failover not kicking in for a `wireguard_zerotier` router** — check `/radius print` on the router: it should show two entries, `priority=1` (WireGuard path) and `priority=2` (ZeroTier path). RouterOS's own RADIUS client handles the actual failover between them natively; there's no custom health-check logic in this app to debug.
- **`/zerotier` package missing on a router** — confirm the router's hardware is actually ARM/ARM64 (`/system resource print` shows `architecture-name`) before troubleshooting further; MIPSBE/SMIPS boards cannot run this package at all, and `tunnel_mode` should be left as `wireguard` for those.
- **Controller unreachable from the app** — `ZEROTIER_CONTROLLER_URL` defaults to `http://127.0.0.1:9993`, which only works when the Laravel app and `zerotier-one` are running on the same machine (the Pi, in production). This will never work from local dev unless ZeroTier is also installed there.
