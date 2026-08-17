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

### Why are we installing our own network instead of just joining one?

If you've used ZeroTier before, you might know the normal way: go to `my.zerotier.com`, click "Create Network," get an ID, done in 10 seconds — no installing anything on a server. So why isn't that what this doc has you do?

Because of one thing: **approving new devices**. Every device that joins a ZeroTier network has to be individually approved before it's allowed to talk to anything else on that network — that's the security feature that stops a stranger from joining your network. On the normal website version, that approval is a manual click, by a human, on the website, every single time.

That's fine for a handful of personal devices. It doesn't work for this app, where you might add a new router next month, or ten next year, and you don't want to have to remember to go and click "approve" on a website every time. So instead of using ZeroTier's website, **Step 1 installs that exact same "network" software directly on your Pi**, running privately for just you. It does the identical job — devices still join, still need approval — except now HotspotFreeRAD itself can click "approve" automatically, the moment a new router shows up, by talking to that software directly instead of a human clicking a website.

Nothing about how the routers themselves connect is any different either way — this choice only changes *who* (a person on a website, or the app automatically) approves new devices.

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
  -d '{"authorized": true, "ipAssignments": ["10.9.0.1"]}'
```

The response should echo back `"authorized":true`. If it comes back `"authorized":false` instead, the command didn't take — see Troubleshooting below.

Check it worked:

```bash
sudo zerotier-cli listnetworks
```

You're looking for `OK` in that output, next to your network, with `10.9.0.1` listed as the address. If you instead see `ACCESS_DENIED`, the Pi has joined the network but the approval above hasn't taken effect yet — see Troubleshooting.

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

**One more manual step after saving the ID, for a router being switched from an existing WireGuard-only setup**: `/zerotier enable` and actually *joining* the network are two different things, and the RADIUS client entry for ZeroTier doesn't exist yet either — a router that was already provisioned before you turned ZeroTier on for it needs both pushed live. Click **"Provision via API"** on the router's page and both are now handled automatically: it reconciles the RADIUS client list (adds the missing ZeroTier entry, fixes the existing WireGuard entry's priority so RouterOS tries it first) and joins the router to the ZeroTier network if it hasn't already, both safe to click any time — re-running it again once everything's already correct just reports "skipped" for anything that doesn't need changing.

To check it's running (safe to run any time, makes no changes):

```bash
cd /var/www/hotspotfreerad
sudo -u www-data php artisan hotspot:sync-zerotier-members --dry-run
```

If a device shows up on the network that this command doesn't recognize (a typo, a stray device, someone who forgot to save the ID), it's listed under "Unmatched nodes on the controller" instead of being silently approved or ignored — so you always have something concrete to check.

**One expected entry here, confirmed live:** your Pi's own ID (the one you approved by hand in Step 4) will always show up as "unmatched" — the Pi itself isn't a `Router` record in HotspotFreeRAD, it's the controller, so the sync has no way to know what it is. Seeing it listed isn't a problem; it's just this command being honest that it doesn't recognize an ID it was never told about. Right after finishing this doc, before you've added any router through the wizard, "would authorize 0 node(s)" plus your Pi's ID listed as unmatched is the normal, expected result.

## If something goes wrong

- **`listnetworks` still shows `ACCESS_DENIED` after running the approve command in Step 4** — re-run the approve command and actually read what it prints back this time. It should echo `"authorized":true`. If it instead comes back `"authorized":false`, the approval didn't take — the most common cause is a stray `"config"` wrapper around the fields (some older copies of this doc showed `-d '{"config": {"authorized": true, ...}}'`, which ZeroTier silently ignores instead of rejecting). The command above sends `"authorized"`/`"ipAssignments"` directly at the top level of the JSON body — no wrapper.
- **"ZeroTier controller auth token not readable" / `Permission denied` reading `authtoken.secret`** — confirmed live: this file is root-only (`0600 root:root`) by default, and the app (running as `www-data`) can't read it as-is. Fix it with:
  ```bash
  sudo chgrp www-data /var/lib/zerotier-one/authtoken.secret
  sudo chmod 640 /var/lib/zerotier-one/authtoken.secret
  ```
  Confirm it worked with `sudo -u www-data cat /var/lib/zerotier-one/authtoken.secret` — it should print the token, not an error. If `zerotier-one` is ever upgraded or reinstalled, it may recreate this file with the default root-only permissions again, so this may need to be re-applied after that.
- **A router's ID never gets approved** — double-check the ID saved on the router record in HotspotFreeRAD matches exactly what `/zerotier print` shows on the router. A single wrong character means you're approving an ID that doesn't exist, while the real one waits forever.
- **A `wireguard_zerotier` router isn't failing over properly** — on the router itself, run `/radius print`. You should see two entries, one WireGuard and one ZeroTier. RouterOS handles switching between them on its own; there's no extra logic in the app to debug here.
- **"`/zerotier` package missing" on a router** — that router's hardware likely can't run it at all. Run `/system resource print` on it and check `architecture-name` — anything other than ARM/ARM64 means this feature isn't available on that board, and it should stay on plain WireGuard.
- **The app can't reach the controller at all** — `ZEROTIER_CONTROLLER_URL` only works when the app and ZeroTier are running on the same machine, which is only true on the Pi in production. It will never work from a local dev machine unless you've also installed ZeroTier there.

## A couple of extra details, if you're curious

- ZeroTier's normal website dashboard also caps a free account at 10 devices — a second reason (on top of the manual-approval one explained above) this setup avoids it, since you might eventually have more than 10 routers.
- Your routers don't know or care that this network lives on your Pi instead of ZeroTier's website — as far as any router is concerned, it's just a ZeroTier network like any other.
