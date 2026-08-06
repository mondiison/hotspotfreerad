# WireGuard Server Setup (One-Time, On The Pi)

This is the one manual step left in router onboarding. Everything else — generating each router's WireGuard key and registering it as a peer on the Pi — is automatic once this is done, via the `hotspot:sync-wireguard-peers` scheduled command.

Do this once per Pi/server, not once per router.

## Why this exists

Previously, connecting a router to the Pi's WireGuard tunnel required SSHing into the Pi and manually adding that router's self-generated public key as a peer — an undocumented step that was easy to forget how to repeat. Now:

- HotspotFreeRAD generates a WireGuard keypair for every router it creates and bakes the private key straight into that router's generated script (`admin/routers/{id}` "Script" page). The router no longer picks its own random key.
- A scheduled Artisan command (`hotspot:sync-wireguard-peers`, every 5 minutes, plus a manual `--dry-run`-checkable run) reads every router's public key from the database and registers it as a peer on the Pi's WireGuard interface, using `wg set` + `wg-quick save` so it survives reboots.
- The command only ever adds/updates peers from the `routers` table. It never removes a peer, so it will not touch a peer you added by hand (e.g. your own laptop for remote Pi access).

This doc covers the one-time server-side bootstrap that makes that automation possible.

## 1. Install WireGuard tools

```bash
sudo apt update
sudo apt install -y wireguard
```

## 2. Generate the Pi's own server keypair

This is the Pi's identity on the tunnel — separate from every router's individual keypair, which the app generates for you.

```bash
cd /etc/wireguard
umask 077
wg genkey | sudo tee privatekey | wg pubkey | sudo tee publickey
```

Keep `privatekey` secret. `publickey` is what you'll put in `.env` as `WIREGUARD_PUBLIC_KEY` — every router's script includes it so routers know how to reach the Pi.

## 3. Create the server interface config

```bash
sudo nano /etc/wireguard/wg-saas.conf
```

```ini
[Interface]
PrivateKey = <contents of /etc/wireguard/privatekey>
Address = 10.8.0.1/24
ListenPort = 13231
```

Do not add `[Peer]` blocks here by hand — the sync command manages those from this point on.

## 4. Enable the interface on boot

```bash
sudo systemctl enable --now wg-quick@wg-saas
sudo systemctl status wg-quick@wg-saas
sudo wg show wg-saas
```

`wg show wg-saas` should show the interface with your public key and no peers yet — that's expected before any router has been synced.

## 5. Set `.env` on the Pi

```env
WIREGUARD_ENDPOINT_HOST=YOUR_PI_PUBLIC_IP_OR_DDNS
WIREGUARD_ENDPOINT_PORT=13231
WIREGUARD_PUBLIC_KEY=<contents of /etc/wireguard/publickey>
WIREGUARD_INTERFACE=wg-saas
WIREGUARD_MANAGE_PEERS=true
```

`WIREGUARD_MANAGE_PEERS` is `false` by default everywhere (including local dev) so the sync command is a safe no-op unless explicitly turned on. Only set it to `true` on the Pi, after completing this doc.

To find `WIREGUARD_ENDPOINT_HOST`, run `curl -4 ifconfig.me` on the Pi to see its current public IP. If the connection in front of the Pi doesn't give a static IP (most residential/Starlink connections don't), use a DDNS hostname instead of a raw IP so router configs don't silently break the next time the IP changes — see the CGNAT note in step 7 before assuming a public IP will work at all. If the router is on the same LAN as the Pi, use the Pi's LAN IP instead (see "Local Pi Behind The Same MikroTik" in `docs/router-onboarding.md`).

## 6. Allow the web server user to manage WireGuard peers

The Laravel queue/scheduler on the Pi runs as `www-data` (see `docs/raspberry-pi-deployment.md`), which does not have permission to run `wg`/`wg-quick` by default. Grant it a narrowly scoped, passwordless sudo rule.

First, create the peer-set wrapper script. Setting a peer's allowed-ips needs per-router arguments (the public key and internal IP), but some hardened `sudo` builds reject wildcarded arguments in sudoers entirely (`wildcards are not allowed in command arguments`) — so instead of a wildcarded `wg set wg-saas peer * allowed-ips *` rule, this script takes no CLI arguments at all and reads them from stdin instead, keeping its sudoers rule argument-free:

```bash
sudo tee /usr/local/sbin/hotspotfreerad-wg-set-peer > /dev/null <<'EOF'
#!/bin/sh
set -e
read -r PUBLIC_KEY
read -r ALLOWED_IP
exec wg set wg-saas peer "$PUBLIC_KEY" allowed-ips "$ALLOWED_IP/32"
EOF

sudo chmod 755 /usr/local/sbin/hotspotfreerad-wg-set-peer
```

Then grant the sudoers rule — prefer writing it non-interactively with `tee` over `visudo`'s editor, since pasting a multi-line block into `visudo` over SSH is a common source of paste corruption that then loops you at its "What now?" prompt:

```bash
sudo tee /etc/sudoers.d/hotspotfreerad-wireguard > /dev/null <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/bin/wg show wg-saas dump
www-data ALL=(root) NOPASSWD: /usr/local/sbin/hotspotfreerad-wg-set-peer
www-data ALL=(root) NOPASSWD: /usr/bin/wg-quick save wg-saas
EOF

sudo chmod 440 /etc/sudoers.d/hotspotfreerad-wireguard
sudo visudo -c
```

Confirm the real path to `wg`/`wg-quick` first with `which wg` / `which wg-quick` — edit the file above (same `tee` approach) if they differ; sudoers matches the literal path, not whatever `$PATH` would resolve. `sudo visudo -c` should report this file `parsed OK`; if it still complains about the wrapper script's line, your build may also reject sudoers entries with no arguments at all — if so, share the exact error text before changing anything further, since that would need a different workaround. Keep this file scoped to exactly these three commands; do not widen it to `ALL` or to other binaries.

## 7. Forward the WireGuard port

For routers outside the Pi's local network, forward UDP `13231` on whatever sits in front of the Pi (ISP router, Starlink router, etc.) to the Pi's LAN IP.

**CGNAT warning, especially on Starlink**: standard residential Starlink (and many mobile/4G/5G home internet plans) sits behind carrier-grade NAT (CGNAT) by default — the public IP `curl -4 ifconfig.me` shows you is shared across many customers and is **not** actually forwardable, no matter how port forwarding is configured on the Starlink router/app. Remote routers will never be able to reach the Pi in that case, and this fails silently from the app's side — the router's script runs fine, `wg-saas` exists, but the peer just never shows a handshake. Signs you're behind CGNAT: the port-forwarding UI has no effect, or the IP from `ifconfig.me` doesn't match anything configurable in the router/app. If so, either buy Starlink's "Public IP" add-on (paid, gives a real static IP) or put the Pi behind a connection that isn't CGNAT'd. This only matters for routers outside the Pi's LAN — local/on-site routers are unaffected since they never leave the LAN to begin with.

## 8. Verify

```bash
cd /var/www/hotspotfreerad
sudo -u www-data php artisan hotspot:sync-wireguard-peers --dry-run
```

This should report `WireGuard sync on "wg-saas": would add N peer(s)...` for however many routers already exist, with no errors. Drop `--dry-run` to apply it immediately instead of waiting for the next scheduled run.

## From here on

Adding a router is now just:

1. Create the router in HotspotFreeRAD.
2. Open its Script page and paste the generated commands into the MikroTik RouterOS terminal (the WireGuard section now includes a `private-key=` value the app generated, not a placeholder).
3. Wait up to 5 minutes (or run `php artisan hotspot:sync-wireguard-peers` manually) for the Pi to register it as a peer.

No SSH into the Pi, no manually copying a public key off the router.

## Troubleshooting

- **Router's "WireGuard public key" shows "Not generated yet" on its Script page** — this router was created before this feature shipped. Use the "Generate WireGuard key" button on that page, then re-paste the WireGuard section of the script on the physical router.
- **Peer never appears in `sudo wg show wg-saas`** — run `php artisan hotspot:sync-wireguard-peers --dry-run` and read the `errors` it reports; it usually means the sudoers rule in step 6 isn't in place yet, or `WIREGUARD_MANAGE_PEERS` isn't `true`.
- **`Could not read WireGuard interface "wg-saas": Unable to access: operation not permitted`** — the command was run as a user without the sudoers rule from step 6 (`WireGuardPeerSyncService` shells out through `sudo -n wg ...`/`sudo -n wg-quick ...`, never as plain `wg`, specifically so that rule is what grants access). Confirm `/etc/sudoers.d/hotspotfreerad-wireguard` exists and matches the real `wg`/`wg-quick` paths (`which wg`, `which wg-quick`), and that you're testing as the same user the scheduler/queue actually runs as (`sudo -u www-data php artisan hotspot:sync-wireguard-peers --dry-run`, not just `php artisan ...` as your own login user).
- **Same error, but running as root (e.g. `sudo php artisan hotspot:sync-wireguard-peers`) says `... no such device`** — this means the permission problem is gone (root can always run `wg`) but the `wg-saas` interface itself was never created. Go back to steps 1–4: install `wireguard`, generate the Pi's keypair, write `/etc/wireguard/wg-saas.conf`, then `sudo systemctl enable --now wg-quick@wg-saas` and confirm `sudo wg show wg-saas` shows the interface before touching the sync command again.
- **Router still can't reach `10.8.0.1`** — check the port-forward in step 7, and confirm the router's own script ran without errors (`/interface wireguard print` on the router should show a handshake once the Pi-side peer exists). If port forwarding looks correctly configured but a handshake still never happens for any off-site router, suspect CGNAT (see step 7) — the peer will still register fine on the Pi's side (that part is purely local), it's the router's connection attempt from the outside that silently goes nowhere.
- **If the router in question is physically on the same LAN as the Pi** (plugged into the same local network, not remote), don't chase port-forwarding or CGNAT at all — that only affects off-site routers. Use the router's **"WireGuard endpoint override"** field in HotspotFreeRAD instead, set to the Pi's LAN IP, so its script dials the Pi locally rather than through its own public IP (which commonly fails via NAT hairpin/loopback for UDP). See "Local Pi Behind The Same MikroTik" in `docs/router-onboarding.md`.
