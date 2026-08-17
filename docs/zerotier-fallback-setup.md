# ZeroTier Fallback Tunnel Setup (Do This Once, On The Pi)

## Do you actually need this?

Skip this whole document unless you have a router that **can't connect over WireGuard** — usually because its internet connection is Starlink, or a mobile/4G/5G "home internet" plan. Those connections sit behind something called CGNAT, which quietly blocks the kind of direct connection WireGuard needs. If all your routers are on normal fibre/DSL/cable internet, WireGuard alone is fine and you don't need any of this.

If you do have a CGNAT router, you only do this setup **once**, on the Pi — not once per router.

## What you're actually setting up, in plain terms

Right now, every router talks to the Pi over one tunnel: WireGuard. That works great, except WireGuard has no "plan B" — if a direct connection can't be made, it just fails, permanently, for that router.

ZeroTier is a second kind of tunnel you're adding as a backup. Its whole selling point is that it's much better at punching through tricky internet connections like CGNAT. So the plan is:

1. Install ZeroTier on the Pi (a small background service, like WireGuard already is).
2. Create a small private network that only your own routers are allowed to join.
3. Tell HotspotFreeRAD about it (a few lines in `.env`).

After that, any router you mark as needing ZeroTier will use it automatically — either as a backup alongside WireGuard, or as its only connection if WireGuard genuinely can't work for that site.

**One hardware catch:** ZeroTier only runs on newer MikroTik boards (ARM/ARM64 chips). Cheaper/older boards (MIPSBE/SMIPS) can't run it at all — nothing breaks, that router just isn't eligible for this feature and stays on WireGuard only. You'll pick this per-router in the router wizard later, not here.

This whole setup takes about 15 minutes and you do it once.

---

## Step 1 — Install ZeroTier on the Pi

```bash
curl -s https://install.zerotier.com | sudo bash
sudo systemctl enable --now zerotier-one
```

This downloads and starts the ZeroTier background service, the same way `wg-saas` already runs for WireGuard. Nothing to configure yet — it's just getting the software running.

## Step 2 — Get an ID number for your Pi

```bash
sudo zerotier-cli info
```

You'll see something like:

```
200 info 8badf00d12 ONLINE 1.14.0
```

The `8badf00d12` part (10 letters/numbers) is your Pi's own ZeroTier ID. **Write it down** — you'll use it in the next two steps. Every device that installs ZeroTier gets one of these; think of it like a serial number.

## Step 3 — Create your own private network

This is the fiddliest step, but you're only ever typing two commands. A "network" here just means "the private group your Pi and routers will all join" — like creating a private Wi-Fi network, except it works over the internet.

**3a. Get the access key.** ZeroTier created a secret key file on your Pi when it started in Step 1. You need to read it so the next command can use it:

```bash
sudo cat /var/lib/zerotier-one/authtoken.secret
```

This prints a long random string. You don't need to save it separately — the command below reads it automatically — but if that `cat` command fails, stop here and fix that first (see Troubleshooting).

**3b. Create the network.** A network's ID is just your Pi's ID from Step 2, followed by any 6 characters of your own choosing (letters a-f and numbers 0-9 only — e.g. `000001`). Pick something simple. Then run:

```bash
# Replace <node-id> with your Pi's ID from Step 2 (e.g. 8badf00d12),
# and 000001 with any 6 hex characters you like.
curl -s -X POST "http://127.0.0.1:9993/controller/network/<node-id>000001" \
  -H "X-ZT1-Auth: $(sudo cat /var/lib/zerotier-one/authtoken.secret)" \
  -d '{
    "name": "hotspotfreerad",
    "private": true,
    "ipAssignmentPools": [],
    "v4AssignMode": {"zt": false}
  }'
```

If it worked, it prints back the network's settings as text (starting with `{"id":"...`). That whole 16-character ID (your Pi's ID plus the 6 characters you chose) is your **network ID** — write it down, you'll need it in Step 5.

Don't worry about the exact meaning of every field in that command — the short version is: `"private": true` means routers can't just join on their own, they need your explicit approval first (which the app will end up doing automatically once it's set up). That's the whole point of self-hosting this instead of using a public ZeroTier network.

## Step 4 — Let the Pi itself join the network

```bash
sudo zerotier-cli join <network-id>
```

(Use the 16-character network ID from Step 3b.)

Joining isn't enough on its own — like any device, the Pi now needs to be approved before it can actually use the network. Approve it and give it a fixed address (`10.9.0.1` is a sensible choice, matching the `10.8.0.1` WireGuard already uses):

```bash
# Replace <network-id> with the ID from Step 3b, and <pi-node-id> with your Pi's ID from Step 2.
curl -s -X POST "http://127.0.0.1:9993/controller/network/<network-id>/member/<pi-node-id>" \
  -H "X-ZT1-Auth: $(sudo cat /var/lib/zerotier-one/authtoken.secret)" \
  -d '{"config": {"authorized": true, "ipAssignments": ["10.9.0.1"]}}'
```

Check it worked:

```bash
sudo zerotier-cli listnetworks
```

You're looking for `OK` in that output, next to your network, with `10.9.0.1` listed as the address.

## Step 5 — Tell HotspotFreeRAD about it

Add these lines to `.env` on the Pi:

```env
ZEROTIER_CONTROLLER_URL=http://127.0.0.1:9993
ZEROTIER_AUTH_TOKEN_PATH=/var/lib/zerotier-one/authtoken.secret
ZEROTIER_NETWORK_ID=<the 16-character network ID from step 3b>
ZEROTIER_PI_IP=10.9.0.1
ZEROTIER_IP_PREFIX=10.9.0
ZEROTIER_MANAGE_MEMBERS=true
```

That last line, `ZEROTIER_MANAGE_MEMBERS=true`, is the actual on-switch — everything is inactive until you add it (same as `WIREGUARD_MANAGE_PEERS`, if you've set that up already). Only add it here, on the Pi, after everything above is working — never in local development.

**That's it — the one-time Pi setup is done.** Everything from here on happens automatically or from inside the router wizard, not the command line.

---

## What happens when you actually add a ZeroTier-enabled router

You don't need to do anything from this document per router — but there's one small manual step the wizard walks you through, because of how ZeroTier works: a router only gets its own ZeroTier ID the first time you turn ZeroTier on, on that specific router. HotspotFreeRAD can't know that ID in advance the way it does for WireGuard.

- **If the router already has WireGuard working:** the wizard has a **"Fetch over WireGuard"** button that reads the router's ZeroTier ID automatically over the existing WireGuard link. Nothing to type.
- **If the router has no working WireGuard at all** (e.g. it's on Starlink and WireGuard was never going to work): after enabling ZeroTier on the router itself, run `/zerotier print` on the router's own console and copy the ID it shows into the wizard by hand, once.

Either way, once that ID is saved, the Pi checks every 5 minutes and approves the router automatically from then on — this is a one-time step per router, not something you repeat.

To check it's running (safe to run any time, makes no changes):

```bash
cd /var/www/hotspotfreerad
sudo -u www-data php artisan hotspot:sync-zerotier-members --dry-run
```

If a device shows up on the network that this command doesn't recognize (a typo, a stray device, someone who forgot to save the ID), it's listed under "Unmatched nodes on the controller" instead of being silently approved or ignored — so you always have something concrete to check.

## If something goes wrong

- **"ZeroTier controller auth token not readable"** — the app (running as `www-data`) can't read the secret file from Step 3a. Check the path is right and try `sudo -u www-data cat /var/lib/zerotier-one/authtoken.secret` — if that fails, the file's permissions need loosening for that one user (same idea as the WireGuard permission grant in `docs/wireguard-server-setup.md`).
- **A router's ID never gets approved** — double-check the ID saved on the router record in HotspotFreeRAD matches exactly what `/zerotier print` shows on the router. A single wrong character means you're approving an ID that doesn't exist, while the real one waits forever.
- **A `wireguard_zerotier` router isn't failing over properly** — on the router itself, run `/radius print`. You should see two entries, one WireGuard and one ZeroTier. RouterOS handles switching between them on its own; there's no extra logic in the app to debug here.
- **"`/zerotier` package missing" on a router** — that router's hardware likely can't run it at all. Run `/system resource print` on it and check `architecture-name` — anything other than ARM/ARM64 means this feature isn't available on that board, and it should stay on plain WireGuard.
- **The app can't reach the controller at all** — `ZEROTIER_CONTROLLER_URL` only works when the app and ZeroTier are running on the same machine, which is only true on the Pi in production. It will never work from a local dev machine unless you've also installed ZeroTier there.

## Why it's built this way (optional background reading)

You don't need this section to get the setup working — it's here for anyone curious about the reasoning.

ZeroTier's own cloud dashboard ("ZeroTier Central") is how most people normally use it, but its free tier caps you at 10 devices and gives no programmatic access at all — automating approvals the way this app does would need a paid plan. Since routers need to be approved automatically rather than by someone clicking a website, this setup instead runs a small ZeroTier "controller" directly on the Pi. It's free, has no device limit, and only talks to the app over `localhost` — no ZeroTier account needed anywhere.

RouterOS doesn't know or care whether a network was created this way or through Central — a network ID always contains its controller's identity, so nothing about how routers connect changes based on this choice.
