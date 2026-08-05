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

## 6. Allow the web server user to manage WireGuard peers

The Laravel queue/scheduler on the Pi runs as `www-data` (see `docs/raspberry-pi-deployment.md`), which does not have permission to run `wg`/`wg-quick` by default. Grant it a narrowly scoped, passwordless sudo rule:

```bash
sudo visudo -f /etc/sudoers.d/hotspotfreerad-wireguard
```

```text
www-data ALL=(root) NOPASSWD: /usr/bin/wg show wg-saas dump
www-data ALL=(root) NOPASSWD: /usr/bin/wg set wg-saas peer * allowed-ips *
www-data ALL=(root) NOPASSWD: /usr/bin/wg-quick save wg-saas
```

Confirm the real path to `wg`/`wg-quick` first with `which wg` / `which wg-quick` — adjust the paths above if they differ. Keep this file scoped to exactly these three commands; do not widen it to `ALL` or to other binaries.

## 7. Forward the WireGuard port

For routers outside the Pi's local network, forward UDP `13231` on whatever sits in front of the Pi (ISP router, Starlink router, etc.) to the Pi's LAN IP.

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
- **Router still can't reach `10.8.0.1`** — check the port-forward in step 7, and confirm the router's own script ran without errors (`/interface wireguard print` on the router should show a handshake once the Pi-side peer exists).
