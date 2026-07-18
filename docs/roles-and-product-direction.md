# Roles And Product Direction

HotspotFreeRAD should behave like a SaaS platform, not a single-router utility.

## Roles

### Super Admin

Platform owner role.

Can manage:

- all tenants;
- all tenant admins;
- all shops;
- all routers;
- all packages;
- platform coupons and subscription tiers;
- payment provider status;
- global RADIUS and deployment health.

### Tenant Admin

Business/shop owner role.

Can manage only their tenant scope:

- their shops;
- their routers;
- their packages/data plans;
- their customers;
- their payments;
- their active sessions and reports.

Tenant admins should not see other tenants' data.

## Package Model

Packages should support:

- price and currency;
- bandwidth/rate limit, e.g. `5M/5M`;
- uptime, with presets like 1 hour, 1 day, 3 days, 7 days, 30 days;
- total transferred data quota, or unlimited data;
- optional FUP threshold;
- optional FUP throttled speed.

RADIUS sync:

- `Mikrotik-Rate-Limit` controls bandwidth.
- `Session-Timeout` controls session duration.
- `Mikrotik-Total-Limit` and `Mikrotik-Total-Limit-Gigawords` control hotspot data quota when data is limited.

MikroTik's RouterOS RADIUS documentation points to the supported MikroTik RADIUS dictionary and notes that `NAS-Identifier` is the router identity name. Keep router identities and app NAS identifiers matched exactly.

## Package Examples

### Daily 5GB

```text
Name: Daily 5GB
Uptime: 86400
Bandwidth: 5M/5M
Hard data cap: 5368709120
FUP threshold: blank
FUP speed: blank
```

Customer gets one day of access and is cut off after 5GB total upload + download.

### Weekly Unlimited

```text
Name: Weekly Unlimited
Uptime: 604800
Bandwidth: 3M/3M
Hard data cap: blank
FUP threshold: blank
FUP speed: blank
```

Customer gets seven days of unlimited access at 3M/3M.

### 30-Day Fair Use

```text
Name: 30-Day Fair Use
Uptime: 2592000
Bandwidth: 10M/10M
Hard data cap: blank
FUP threshold: 21474836480
FUP speed: 1M/1M
```

Customer gets 30 days of access. First 20GB runs at 10M/10M. After 20GB, access continues at 1M/1M.

### 10GB With Soft Slowdown

```text
Name: 10GB Smart Plan
Uptime: 604800
Bandwidth: 5M/5M
Hard data cap: 10737418240
FUP threshold: 7516192768
FUP speed: 512K/512K
```

Customer can use up to 10GB total. After 7GB, speed drops to 512K/512K until the 10GB hard cap is reached.

## Unit Notes

Use bytes internally:

```text
1GB = 1073741824
2GB = 2147483648
5GB = 5368709120
10GB = 10737418240
20GB = 21474836480
50GB = 53687091200
100GB = 107374182400
```

The UI should offer presets but still allow custom byte values.

## Premium UI Direction

Short term:

- keep Blade views stable;
- improve visual hierarchy, density, and empty states;
- use consistent table, form, and status patterns.

After Flux Pro is installed:

- replace hand-written inputs with Flux fields/selects/buttons;
- move CRUD pages to Livewire components;
- add searchable tables and modal forms;
- add tenant-scoped dashboard cards;
- add router health/session widgets;
- add role-aware navigation.

Livewire 4 is now available and its official upgrade guide describes a mostly compatible upgrade path from v3. We should upgrade after the current portal/provisioning flow is stable on the Pi.
