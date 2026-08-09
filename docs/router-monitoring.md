# Router Monitoring: Historical Graphs, Live Graphs, Wi-Fi Scan, Topology

Four related features, in increasing order of how much they depend on being able to reach the router live.

## Bootstrap script + API-push provisioning

Router Script page → **Overview** tab → "Bootstrap Script". A brand-new router only needs one script pasted by hand now: `MikroTikProvisioningService::generateBootstrapScript()` contains just `/system identity`, the WireGuard interface/peer/address lines, and the RouterOS API user — the minimum needed to get the router reachable over the WireGuard tunnel and API. Everything else (RADIUS client, hotspot profile, walled-garden, or the PPPoE/RADIUS/PPP-profile/PPPoE-server equivalent) is then pushed live over the API from the **Hotspot Script** / **PPPoE Script** tabs' "Provision via API" button, once `$router->api_username` exists — no more full-script re-pasting for routine setup or config changes.

`RouterOsConnectionService::provisionHotspot()` / `provisionPppoe()` run each RouterOS command independently and return a per-step `{label, success, error}` report (mirroring the existing `WireGuardPeerSyncService` partial-success style) — one failed step (e.g. an entry that already exists) doesn't stop the rest, and the admin sees exactly which steps succeeded. The full multi-tab "paste the whole script" flow still exists and remains the source of truth for what gets provisioned; the API push is a convenience that mirrors it, not a separate feature.

`provisionHotspot()`'s walled-garden step is `syncWalledGarden()`, a separate public method on `RouterOsConnectionService` so it can be called on its own — which `App\Jobs\SyncRouterWalledGarden` does, queued automatically whenever `PaymentSettingsService::update()` sees a shop's `payment_gateway` actually change, re-pushing that gateway's hosted-checkout hosts (plus the portal host and `*.cloudflare.com`) to every router the shop owns. This matters because a router whose walled garden doesn't include the active gateway's checkout domain doesn't degrade gracefully: MikroTik can't rewrite an HTTPS response to inject the captive-portal redirect, so it resets the connection instead (`net::ERR_CONNECTION_CLOSED` in the browser at "Continue to payment"). Same best-effort caveat as the walled-garden host lists elsewhere (see `PaymentGatewayCatalog::walledGardenHosts()` note in `CLAUDE.md`) — not verified against a live checkout for every gateway.

Because `SyncRouterWalledGarden` only dispatches on an actual gateway *change*, it does nothing for a router whose shop was already on the affected gateway before this feature shipped, and it depends on a queue worker actually draining the `default` queue (`QUEUE_CONNECTION=database` locally with no `queue:work`/`queue:listen` running just leaves it sitting in the `jobs` table indefinitely — check with `php artisan tinker` → `DB::table('jobs')->count()` if a dispatched sync never seems to take effect). `hotspot:resync-walled-garden {router?} {--all}` (see the Commands section in `CLAUDE.md`) is the manual escape hatch for both cases — it calls `syncWalledGarden()` synchronously, no queue involved.

**2026-08-07 correctness fix — API writes were silently failing on every router.** The API user's group policy denied `write` (it was originally scoped read-only, for monitoring only), so RouterOS rejected every write `provisionHotspot()`/`provisionPppoe()`/`syncWalledGarden()` issued. Worse, `evilfreelancer/routeros-api-php`'s `Client::read()` parses a RouterOS `!trap` (rejected command) the same way as `!done` (success) and never throws, so the rejection was reported back as a success with no indication anything was wrong — an admin running `hotspot:resync-walled-garden` (or clicking "Provision via API") would see "synced" while the router's actual config never changed. Fixed by granting `write` in `apiUserProvisioningLines()`'s policy and by having `RouterOsConnectionService::runSteps()`/`applyHotspotProfile()` call `read(false)` (raw) and check for a `!trap` block via `RouterOsConnectionService::extractTrapMessage()` before reporting a step successful. **A router bootstrapped before this fix is still running the old read-only policy** — its script needs re-running (or just the `/user group set ... policy=...` line re-applied) before any API write will actually succeed; until then, `hotspot:resync-walled-garden`/"Provision via API" will now correctly report a permissions failure instead of a false success.

`syncWalledGarden()` also lists the router's current walled-garden entries first (`existingWalledGardenHosts()`) and only issues `/add` for hosts genuinely missing (`RouterOsConnectionService::missingWalledGardenEntries()`, a pure/unit-tested filter) — RouterOS doesn't dedupe `dst-host` on its own, so unconditionally re-adding the same entries on every resync (every gateway change, every manual `hotspot:resync-walled-garden` run) would otherwise pile up duplicate rows indefinitely. A host already present is reported back as a successful, skipped step rather than re-sent.

**Neither the pasted Hotspot Script nor `provisionHotspot()` creates the hotspot server itself** (the `/ip hotspot add` binding of a server to an interface + address pool) — that's router-specific and normally comes from MikroTik's own `/ip hotspot setup` wizard, or a router that was already running a hotspot before this feature existed. What both DO handle is pointing whatever hotspot server(s) already exist at the RADIUS-integrated `saas-prof` profile: the pasted script's last line is `/ip hotspot set [find] profile=saas-prof` (a safe no-op if none exists yet), and `provisionHotspot()`'s final step (`applyHotspotProfile()`) does the same over the API — querying `/ip/hotspot/print` first and reporting "no hotspot server found yet, run the wizard first" as an informational (not failed) step rather than silently doing nothing. Either way, run `/ip hotspot setup` on a genuinely new router before or after pasting/provisioning; order doesn't matter since this step is idempotent.

**Explicitly out of scope for this round**: pushing the "Fresh Infrastructure Script" (VLANs, bridges, DHCP, firewall, QoS queues, Wi-Fi profiles) via the API. That script is much larger and still needs to be pasted by hand — a much bigger, separate follow-up given its scale and the lack of real-hardware verification so far. It doesn't have this gap, though — it creates its own hotspot server binding directly.

## Historical bandwidth graphs

Router Script page → **Overview** tab. Built entirely from FreeRADIUS accounting (`radacct`), which already exists — no RouterOS API involved. Shows the last 30 days of daily download/upload, with a session's bytes attributed to the day it started (an approximation, not exact per-day billing).

## RouterOS API foundation (what the other three need)

Live bandwidth, Wi-Fi scanning, and topology (router status) need to talk to the router directly, not just read RADIUS accounting. This works the same way WireGuard peer connectivity does: **the generated script provisions what's needed automatically**, so there's no separate manual credential-setup step.

Every router gets, on creation:

- A read-only RouterOS API user (`App\Models\Router::API_USERNAME`, currently `mmsradius-api`) with a random generated password (`api_password`, encrypted at rest — see `App\Models\Router`).
- These are baked into all three generated scripts as (simplified; the real output uses `:if`/`else` so re-running the script updates an existing group/user in place instead of erroring on a duplicate name — see below):
  ```routeros
  /user group add name=mmsradius-api-group policy=read,api,!local,!telnet,!ssh,!ftp,!reboot,!write,!policy,!test,!winbox,!password,!web,!sniff,!sensitive,!romon,!rest-api comment="MMS Radius read-only API access"
  /user add name="mmsradius-api" password="..." group=mmsradius-api-group comment="MMS Radius monitoring"
  /ip service set api disabled=no port=8728 address=10.8.0.0/24
  ```
- The API service is restricted to the `10.8.0.0/24` WireGuard subnet — it is not exposed on the WAN.

**Policy flags confirmed against real hardware** (RouterOS 7.18.2, L009UiGS-2HaxD, 2026-08-06): `App\Services\MikroTikProvisioningService::apiUserProvisioningLines()` originally included `!dude` and `!tikapp`, neither of which RouterOS 7 recognizes (`input does not match any value of policy`) — that made the whole `/user group add` line fail, so the API user silently never got created on the router at all, and every RouterOS API feature failed with an authentication error. Fixed by cross-checking `/user group print detail where name=full` (RouterOS's own built-in group listing every valid flag) on real hardware; `!rest-api` was added in its place to also deny RouterOS 7's separate REST API surface, which this app doesn't use. If a future RouterOS release shifts the flag list again, the same `/user group print detail where name=full` check is the fastest way to find the current valid set.

**Also fixed at the same time**: the group/user creation lines are now idempotent (`:if ([:len [...find...]] = 0) do={ ...add... } else={ ...set... }`) — re-pasting the script, e.g. after clicking "Regenerate credentials", now actually updates the router's password instead of erroring on a duplicate name and leaving it silently out of sync with what the app has stored (the exact failure mode a "Test connection" → "Invalid user name or password" error usually means).

`App\Services\RouterOsConnectionService` wraps [`evilfreelancer/routeros-api-php`](https://github.com/EvilFreelancer/routeros-api-php) (chosen over `pear2/net_routeros` because the latter has never left beta). It requires PHP's `ext-sockets` extension — enabled by default on most Linux `php-fpm` installs, but disabled by default in XAMPP for Windows (`extension=sockets` is commented out in `php.ini` by default; uncomment it for local testing).

Connections use short timeouts (5s) and a single attempt so a "Test Connection" click or a monitoring page load never hangs on an offline router — it fails fast with a clear message instead.

**Test it**: Router Script page → Router Details card → "Test connection". A router created before this feature shipped won't have credentials yet — "Generate credentials" appears instead, then re-run the script on the physical router.

## Live bandwidth graphs

Router Script page → **Live** tab → "Live Bandwidth". Polls `/interface/monitor-traffic` with RouterOS's `once` flag every 5 seconds (via `App\Livewire\Admin\RouterLiveMonitor`), so each poll returns a single instantaneous sample rather than opening a streaming connection. Keeps a rolling window of the last 30 samples (~2.5 minutes) in the Livewire component's own state — nothing is persisted to the database.

It only ever charts **one interface at a time**, picked from a dropdown populated live from `RouterOsConnectionService::listInterfaces()` (`/interface/print`) — so admins don't have to already know (or type out by hand) a real interface name. It defaults to `wg-saas`, which is easy to misread as "this router's traffic" — it's actually just the WireGuard tunnel to the Pi (RADIUS + RouterOS API control traffic only). To see customer or staff/management traffic, switch the dropdown to the relevant interface instead — e.g. `wifi-mgmt` for the L009 built-in Wi-Fi profile's management SSID, `vlan-mgmt` for that VLAN's aggregate traffic, or `ether1` for total WAN throughput. Switching interfaces clears the chart's rolling sample window, since mixing two interfaces' data in one line chart would be meaningless. If the interface list fails to load (API unreachable), the picker falls back to a plain text input so the feature degrades instead of breaking outright.

## Wi-Fi scanning

Router Script page → **Live** tab → "Wi-Fi Scan". On-demand only (not polled) via `App\Livewire\Admin\RouterWifiScan`, using `/interface/wifi/scan` (or `/interface/wireless/scan` with the "legacy wireless package" checkbox for older RouterOS installs) on whichever interface name you enter — `wifi1` for the L009 built-in Wi-Fi test profile.

**This is the least-verified part of this feature set.** RouterOS's scan command normally streams results until explicitly cancelled; this reads a limited batch (`count` option) and closes the connection, expecting that to implicitly stop the scan on the router side. That has historically been a source of stuck API sessions on some RouterOS releases for similar streaming commands. Test it against real hardware — including running a second scan shortly after the first — before relying on it operationally. Also note: scanning can briefly interrupt client connections on some wireless drivers when run on an interface that's actively serving an SSID.

## Connected Devices

Router Script page → **Live** tab → "Connected Devices" (added 2026-08-09). Shows live DHCP leases for a router, per network (`mgmt`/`staff`/`pos`/`hotspot`), via `App\Livewire\Admin\RouterConnectedDevices` and `RouterOsConnectionService::dhcpLeases()` (`/ip/dhcp-server/lease/print`, filtered by the network's fixed DHCP server name — `dhcp-mgmt`, `dhcp-staff`, `dhcp-pos`, `dhcp-hotspot`). For `mgmt`/`staff`, each row has a "Register as trusted" button that creates a `TrustedWifiDevice` from the lease's MAC/hostname.

This is deliberately DHCP-lease-based, not wireless/wifi registration-table-based. A registration-table query (`/interface/wifi/registration-table` or the legacy wireless equivalent) only shows clients associated to the *MikroTik's own* radio — useless the moment a network's actual access point is an external device (e.g. a Ruijie AP) bridged into the same VLAN, since those clients never associate to the MikroTik radio at all. DHCP leases are recorded by the router itself regardless of which physical AP handed the client its connection, as long as that AP is bridged into the router-managed network — the right data source for a design meant to survive a built-in-Wi-Fi-to-external-AP transition.

**This is visibility only.** It does not change or fix anything about whether the Trusted Wi-Fi allowlist is actually enforced on the router — see `docs/staff-wifi-access.md` for the separate, still-open enforcement gaps (the MikroTik built-in Wi-Fi access-list is never auto-pushed to the router; external-AP RADIUS MAC-auth has a WireGuard LAN-routing gap). The "Register as trusted" flash message says this explicitly rather than implying the button blocks or allows anything by itself.

## Read-only console

Router Script page → **Live** tab → "Console". A RouterOS terminal, scoped to read-only: type any CLI-style `print` command (e.g. `/interface print`, `/ip hotspot active print where server=hotspot1`, `/ppp active print detail`) and it runs live over the same read-only API user as the rest of this feature set, showing the returned rows as a table.

`RouterOsConnectionService::parseReadOnlyCommand()` returns a bare menu path (e.g. `/system/resource`) for display, but a RouterOS API command needs the trailing verb — `/system/resource` alone isn't executable and RouterOS reports `no such command` for it. `readOnlyQueryPath()` appends `/print` when actually building the query sent to the router; the original implementation missed this step entirely (fixed 2026-08-06, confirmed on real RouterOS 7.18.2 hardware), so every console command failed with `no such command` regardless of what was typed.

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
