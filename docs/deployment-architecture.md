# Deployment Architecture

This project has two different traffic paths:

```text
Customer browser -> Captive portal website
MikroTik router  -> FreeRADIUS auth/accounting
```

Keep those paths separate in your head. The website needs HTTPS and public reachability. RADIUS needs stable, low-latency access from routers and should not be exposed openly to the public internet.

## Recommended Production Shape

For this project, the best early deployment is:

```text
Public HTTPS domain
    |
Cloudflare Tunnel / reverse proxy
    |
Raspberry Pi
    +-- Laravel app
    +-- MySQL/MariaDB radius database
    +-- FreeRADIUS
    +-- WireGuard
```

Why this is best right now:

- FreeRADIUS and MySQL stay close together on the Pi.
- MikroTik routers keep using WireGuard and RADIUS at `10.8.0.1`.
- Laravel can write directly to the same `radius` database FreeRADIUS reads.
- The captive portal can still be public through HTTPS.

## About `mondiison.16mb.com`

You can use `mondiison.16mb.com` only if the host supports the Laravel requirements:

- PHP 8.2 or newer
- Composer dependencies
- writable `storage/` and `bootstrap/cache/`
- web root pointed to Laravel `public/`
- MySQL/MariaDB
- HTTPS
- cron for `php artisan schedule:run`
- queue worker support, or at least a safe fallback for queued jobs

Many free/shared PHP hosts are fine for simple PHP sites but awkward for full Laravel apps because they may not provide SSH, Composer, long-running workers, or cron. For example, recent free-hosting roundups often list PHP/MySQL support but also note limits around SSH, cron, and advanced features. Treat free hosting as a demo option, not the final production architecture.

## Database Recommendation

Do not put the production RADIUS database on a random shared host unless you have confirmed:

- the Pi can connect to it reliably;
- remote MySQL connections are allowed;
- traffic is encrypted or carried through a VPN;
- latency is low enough for authentication;
- backups are available;
- you can recover quickly if the host suspends or throttles the account.

For RADIUS, database downtime means customers cannot log in. The safest current setup is:

```text
FreeRADIUS database stays on the Raspberry Pi.
Laravel writes to that database.
Public web access reaches Laravel through a tunnel/proxy.
```

## GitHub Deployment Flow

The repository remote should be:

```text
https://github.com/mondiison/hotspotfreerad.git
```

Safe automatic pushing means:

```text
commit -> post-commit hook -> git push
```

Avoid push-on-every-file-save. It can publish broken intermediate files, secrets accidentally created outside `.env`, or code that has not passed tests.

## Current Recommendation

1. Push this code to GitHub.
2. Keep the Pi database as the source of truth.
3. Make the Laravel app reachable publicly through a proper HTTPS endpoint.
4. Use `mondiison.16mb.com` only if it can run Laravel correctly.
5. If `16mb.com` cannot run Laravel well, use a small VPS or expose the Pi with Cloudflare Tunnel.
