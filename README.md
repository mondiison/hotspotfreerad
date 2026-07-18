# HotspotFreeRAD

HotspotFreeRAD is a separate Laravel 12 project for managing MikroTik Hotspot access through FreeRADIUS and MySQL/MariaDB.

The project is based on the attached blueprint: a multi-tenant hotspot SaaS where shop owners create packages, customers pay through a captive portal, and Laravel provisions access by writing to the FreeRADIUS SQL tables.

## Current Foundation

- Laravel 12 application scaffold
- Livewire and Flux packages installed
- Core application models and migrations:
  - Tenants
  - Shops
  - Routers
  - Packages
  - Customers
  - Payments
  - Subscriptions
  - Coupons
- MikroTik provisioning script generator
- FreeRADIUS provisioning service for routers, package profiles, and paid MAC access
- FreeRADIUS/MySQL architecture notes in the developer blueprint

## Target Production Topology

```text
Customer device
    |
    | Captive portal redirect
    v
MikroTik router
    |
    | RADIUS auth/accounting over WireGuard
    v
Raspberry Pi 4
    |
    +-- Laravel app
    +-- FreeRADIUS 3
    +-- MySQL/MariaDB radius database
```

## Raspberry Pi Assumption

You have already installed FreeRADIUS and MySQL on the Raspberry Pi using the MikroTik Masters guide.

Before connecting this app to the Pi, confirm these tables exist in the `radius` database:

- `nas`
- `radcheck`
- `radreply`
- `radacct`
- `radpostauth`
- `radusergroup`
- `radgroupreply`
- `radgroupcheck`

## Guides

- [Router onboarding](docs/router-onboarding.md): explains NAS identifiers, WireGuard internal IPs, RADIUS shared secrets, and first-router testing.
- [Deployment architecture](docs/deployment-architecture.md): explains GitHub, hosting, captive portal reachability, and database placement.
- [Raspberry Pi deployment](docs/raspberry-pi-deployment.md): explains moving the Laravel app onto the Pi with local MySQL/FreeRADIUS access.
- [Roles and product direction](docs/roles-and-product-direction.md): defines super admin, tenant admin, package rules, and the Flux/Livewire UI direction.

## Local Development

```bash
cd C:\xampp\htdocs\HotspotFreeRAD
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

## Important Environment Values

Set these in `.env` when connecting to the Raspberry Pi database:

```env
DB_CONNECTION=mysql
DB_HOST=RASPBERRY_PI_IP
DB_PORT=3306
DB_DATABASE=radius
DB_USERNAME=radius
DB_PASSWORD=your_radius_mysql_password

RADIUS_SERVER_IP=10.8.0.1
WIREGUARD_ENDPOINT_HOST=your-public-host-or-ip
WIREGUARD_ENDPOINT_PORT=13231
WIREGUARD_PUBLIC_KEY=your-server-wireguard-public-key
HOTSPOT_DNS_NAME=hotspot.local
```

For production, do not expose MySQL or RADIUS directly to the public internet. Keep router-to-server traffic inside WireGuard where possible.

## Development Phases

1. Core admin: tenant, shop, router, and package management.
2. RADIUS sync: mirror routers into `nas`, packages into `radgroupreply`, and paid devices into `radcheck`/`radusergroup`.
3. Captive portal: accept MikroTik redirect parameters, show active packages, and start checkout.
4. Flutterwave: initialize payments, verify webhooks, then grant access.
5. Automation: expire subscriptions, enforce FUP throttling, and disconnect stale sessions.

## Verification

```bash
php artisan test
```

Current local suite: 6 tests covering app boot, MikroTik script generation, and RADIUS provisioning writes.
