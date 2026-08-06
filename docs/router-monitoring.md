# Router Monitoring: Historical Graphs, Live Graphs, Wi-Fi Scan, Topology

Four related features, in increasing order of how much they depend on being able to reach the router live.

## Bootstrap script + API-push provisioning

Router Script page → **Overview** tab → "Bootstrap Script". A brand-new router only needs one script pasted by hand now: `MikroTikProvisioningService::generateBootstrapScript()` contains just `/system identity`, the WireGuard interface/peer/address lines, and the RouterOS API user — the minimum needed to get the router reachable over the WireGuard tunnel and API. Everything else (RADIUS client, hotspot profile, walled-garden, or the PPPoE/RADIUS/PPP-profile/PPPoE-server equivalent) is then pushed live over the API from the **Hotspot Script** / **PPPoE Script** tabs' "Provision via API" button, once `$router->api_username` exists — no more full-script re-pasting for routine setup or config changes.

`RouterOsConnectionService::provisionHotspot()` / `provisionPppoe()` run each RouterOS command independently and return a per-step `{label, success, error}` report (mirroring the existing `WireGuardPeerSyncService` partial-success style) — one failed step (e.g. an entry that already exists) doesn't stop the rest, and the admin sees exactly which steps succeeded. The full multi-tab "paste the whole script" flow still exists and remains the source of truth for what gets provisioned; the API push is a convenience that mirrors it, not a separate feature.

**Explicitly out of scope for this round**: pushing the "Fresh Infrastructure Script" (VLANs, bridges, DHCP, firewall, QoS queues, Wi-Fi profiles) via the API. That script is much larger and still needs to be pasted by hand — a much bigger, separate follow-up given its scale and the lack of real-hardware verification so far.

## Historical bandwidth graphs

Router Script page → **Overview** tab. Built entirely from FreeRADIUS accounting (`radacct`), which already exists — no RouterOS API involved. Shows the last 30 days of daily download/upload, with a session's bytes attributed to the day it started (an approximation, not exact per-day billing).

## RouterOS API foundation (what the other three need)

Live bandwidth, Wi-Fi scanning, and topology (router status) need to talk to the router directly, not just read RADIUS accounting. This works the same way WireGuard peer connectivity does: **the generated script provisions what's needed automatically**, so there's no separate manual credential-setup step.

Every router gets, on creation:

- A read-only RouterOS API user (`App\Models\Router::API_USERNAME`, currently `mmsradius-api`) with a random generated password (`api_password`, encrypted at rest — see `App\Models\Router`).
- These are baked into all three generated scripts as:
  ```routeros
  /user group add name=mmsradius-api-group policy=read,api,!local,!telnet,!ssh,!ftp,!reboot,!write,!policy,!test,!winbox,!password,!web,!sniff,!sensitive,!romon,!dude,!tikapp comment="MMS Radius read-only API access"
  /user add name="mmsradius-api" password="..." group=mmsradius-api-group comment="MMS Radius monitoring"
  /ip service set api disabled=no port=8728 address=10.8.0.0/24
  ```
- The API service is restricted to the `10.8.0.0/24` WireGuard subnet — it is not exposed on the WAN.

**Verify the exact user-group policy flags against your RouterOS version before relying on this in production.** RouterOS 7's policy flag list has shifted slightly across releases; the set above is a best-effort read-only policy, not something tested against real hardware from this codebase.

`App\Services\RouterOsConnectionService` wraps [`evilfreelancer/routeros-api-php`](https://github.com/EvilFreelancer/routeros-api-php) (chosen over `pear2/net_routeros` because the latter has never left beta). It requires PHP's `ext-sockets` extension — enabled by default on most Linux `php-fpm` installs, but disabled by default in XAMPP for Windows (`extension=sockets` is commented out in `php.ini` by default; uncomment it for local testing).

Connections use short timeouts (5s) and a single attempt so a "Test Connection" click or a monitoring page load never hangs on an offline router — it fails fast with a clear message instead.

**Test it**: Router Script page → Router Details card → "Test connection". A router created before this feature shipped won't have credentials yet — "Generate credentials" appears instead, then re-run the script on the physical router.

## Live bandwidth graphs

Router Script page → **Live** tab → "Live Bandwidth". Polls `/interface/monitor-traffic` with RouterOS's `once` flag every 5 seconds (via `App\Livewire\Admin\RouterLiveMonitor`), so each poll returns a single instantaneous sample rather than opening a streaming connection. Keeps a rolling window of the last 30 samples (~2.5 minutes) in the Livewire component's own state — nothing is persisted to the database.

## Wi-Fi scanning

Router Script page → **Live** tab → "Wi-Fi Scan". On-demand only (not polled) via `App\Livewire\Admin\RouterWifiScan`, using `/interface/wifi/scan` (or `/interface/wireless/scan` with the "legacy wireless package" checkbox for older RouterOS installs) on whichever interface name you enter — `wifi1` for the L009 built-in Wi-Fi test profile.

**This is the least-verified part of this feature set.** RouterOS's scan command normally streams results until explicitly cancelled; this reads a limited batch (`count` option) and closes the connection, expecting that to implicitly stop the scan on the router side. That has historically been a source of stuck API sessions on some RouterOS releases for similar streaming commands. Test it against real hardware — including running a second scan shortly after the first — before relying on it operationally. Also note: scanning can briefly interrupt client connections on some wireless drivers when run on an interface that's actively serving an SSID.

## Read-only console

Router Script page → **Live** tab → "Console". A RouterOS terminal, scoped to read-only: type any CLI-style `print` command (e.g. `/interface print`, `/ip hotspot active print where server=hotspot1`, `/ppp active print detail`) and it runs live over the same read-only API user as the rest of this feature set, showing the returned rows as a table.

`RouterOsConnectionService::parseReadOnlyCommand()` only accepts commands containing a `print` verb and rejects anything containing `add`/`set`/`remove`/`enable`/`disable`/`reset`/`monitor`/`scan`/etc. as a whole token, before it's ever sent to the router. This is defense-in-depth on top of the actual guarantee: the monitoring API user's RouterOS-side policy is `read,api,!write,...`, so even a crafted write command would be rejected by the router itself. There is no separate write-capable API user — this console can never change router configuration, by design.

Filters use RouterOS's `where field=value [field2=value2 ...]` syntax (quoted values with spaces are supported, e.g. `where name="customer 001"`); anything more advanced (comparison operators, `and`/`or` grouping) isn't parsed and the whole command is rejected with a clear error rather than silently doing something unexpected.

## Insight: CPU/RAM/disk, hardware health, uptime, latency history

Router Script page → **Insight** tab. Two independent pieces:

- **Live snapshot** (`App\Livewire\Admin\RouterInsight`, "Refresh" button): CPU load, RAM used/total, disk used/total, uptime, board name, and RouterOS version from `/system/resource/print`, plus whatever hardware sensors `/system/health/print` reports. Needs RouterOS API credentials, same as Live Bandwidth and Wi-Fi Scan.
- **History** (Today / 7 days / 30 days buttons): CPU/RAM and latency-to-router charts, built from `router_metric_samples` — a new table populated every 5 minutes by the `hotspot:sample-router-metrics` scheduled command (`App\Services\RouterMetricSamplingService`).

**Latency does not need RouterOS API credentials at all.** It's measured by shelling out to the system `ping` binary from wherever Laravel runs (the Pi in production) directly at the router's WireGuard IP — the same approach works whether or not the router has API credentials configured yet, since it's plain ICMP over the tunnel, not a RouterOS API call. CPU/RAM/disk/health history only populates once API credentials exist and the script has been re-applied on the router.

Bucketing (hourly for "Today", daily for 7/30 days) happens in PHP over the fetched rows, not in SQL, because SQLite (tests) and MySQL (production) don't share a common date-truncation function — a deliberate simplification given the modest row volume (one row per router per 5 minutes).

**Hardware health caveat**: `/system/health/print`'s output shape varies significantly by hardware and RouterOS version — some devices report nothing, some report voltage/temperature only, some report per-fan speeds as separate rows. The Insight tab renders whatever key/value pairs come back rather than assuming specific fields (like fan speed) exist; don't expect fan data on hardware without fans.

**Flux charts have no built-in drag-to-zoom or brush selection** (confirmed against the Flux docs) — the "Today / 7 days / 30 days" buttons are a server-refetch range picker, not a client-side zoom interaction.

## Notifications & Alerts

Router health alerts fire from the same `hotspot:sample-router-metrics` job that records history — after each sample, `RouterMetricSamplingService` compares it to the router's immediately preceding sample and notifies on four transitions:

- Router stops responding to ping (`RouterAlertNotification::TYPE_OFFLINE`)
- Router responds to ping again after being unreachable (`TYPE_ONLINE`)
- CPU crosses 85% (`TYPE_HIGH_CPU`)
- RAM crosses 85% (`TYPE_HIGH_RAM`)

**Alerting is edge-triggered, not level-triggered**: a notification fires only on the transition into a bad (or recovered) state, not on every 5-minute sample while that state persists. A router stuck offline for six hours sends exactly one "offline" alert, not 72 of them. There's no separate cooldown/dedup table — the check is simply "did the previous sample have this condition." The tradeoff is that there's no periodic reminder for a still-ongoing issue; if that's needed later, it'd be a deliberate addition, not implicit in the current design.

**Recipients**: every active super admin, plus the active tenant admins belonging to the router's tenant (`RouterMetricSamplingService::recipients()`).

**Delivery — in-system always, email is a per-user opt-in**: every alert always creates a database notification (Admin → notification bell icon, top right of every page), which cannot be turned off. Email is a separate channel gated by each user's own `notify_by_email` preference (Profile page → Notifications → "Email me too" toggle, defaults on). `RouterAlertNotification::via()` includes `mail` only when the recipient has opted in.

**Background delivery**: `RouterAlertNotification implements ShouldQueue`, so both the database write and the email send happen through Laravel's queue, not inline during the scheduled command's execution — sending an alert never blocks or slows down the sampling job. Queued jobs are processed by the persistent queue worker already documented in `docs/raspberry-pi-deployment.md` (`hotspotfreerad-worker` systemd service running `queue:work`), not by a separate cron-triggered process. That worker service is what needs to be running on the Pi for alert emails to actually go out — if it's stopped, notifications pile up in the `jobs` table until it's restarted, they don't silently disappear.

## Topology mapper

**Admin → Network → Topology** (`admin/topology`). This is deliberately the organizational hierarchy — Tenant → Shop → Router, with live online/offline status from `RadiusAccountingStats` — not physical network topology discovery (LLDP/CDP neighbor walking). Real physical-topology discovery would need its own RouterOS API work and hasn't been attempted; the scoping choice here was to ship something correct and fully DB-driven (no RouterOS dependency, works even for routers with no API credentials yet) rather than a partially-working neighbor-discovery feature.
