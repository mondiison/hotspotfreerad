# Router Monitoring: Historical Graphs, Live Graphs, Wi-Fi Scan, Topology

Four related features, in increasing order of how much they depend on being able to reach the router live.

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

## Insight: CPU/RAM/disk, hardware health, uptime, latency history

Router Script page → **Insight** tab. Two independent pieces:

- **Live snapshot** (`App\Livewire\Admin\RouterInsight`, "Refresh" button): CPU load, RAM used/total, disk used/total, uptime, board name, and RouterOS version from `/system/resource/print`, plus whatever hardware sensors `/system/health/print` reports. Needs RouterOS API credentials, same as Live Bandwidth and Wi-Fi Scan.
- **History** (Today / 7 days / 30 days buttons): CPU/RAM and latency-to-router charts, built from `router_metric_samples` — a new table populated every 5 minutes by the `hotspot:sample-router-metrics` scheduled command (`App\Services\RouterMetricSamplingService`).

**Latency does not need RouterOS API credentials at all.** It's measured by shelling out to the system `ping` binary from wherever Laravel runs (the Pi in production) directly at the router's WireGuard IP — the same approach works whether or not the router has API credentials configured yet, since it's plain ICMP over the tunnel, not a RouterOS API call. CPU/RAM/disk/health history only populates once API credentials exist and the script has been re-applied on the router.

Bucketing (hourly for "Today", daily for 7/30 days) happens in PHP over the fetched rows, not in SQL, because SQLite (tests) and MySQL (production) don't share a common date-truncation function — a deliberate simplification given the modest row volume (one row per router per 5 minutes).

**Hardware health caveat**: `/system/health/print`'s output shape varies significantly by hardware and RouterOS version — some devices report nothing, some report voltage/temperature only, some report per-fan speeds as separate rows. The Insight tab renders whatever key/value pairs come back rather than assuming specific fields (like fan speed) exist; don't expect fan data on hardware without fans.

**Flux charts have no built-in drag-to-zoom or brush selection** (confirmed against the Flux docs) — the "Today / 7 days / 30 days" buttons are a server-refetch range picker, not a client-side zoom interaction.

## Topology mapper

**Admin → Network → Topology** (`admin/topology`). This is deliberately the organizational hierarchy — Tenant → Shop → Router, with live online/offline status from `RadiusAccountingStats` — not physical network topology discovery (LLDP/CDP neighbor walking). Real physical-topology discovery would need its own RouterOS API work and hasn't been attempted; the scoping choice here was to ship something correct and fully DB-driven (no RouterOS dependency, works even for routers with no API credentials yet) rather than a partially-working neighbor-discovery feature.
