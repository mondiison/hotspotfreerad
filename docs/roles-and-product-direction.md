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
