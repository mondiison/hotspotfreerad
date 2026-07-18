# Developer Blueprint: Multi-Tenant Hotspot SaaS

TALL Stack + FreeRADIUS 3 + MikroTik + Flutterwave

## 1. System Overview

This project is a multi-tenant Wi-Fi hotspot management platform intended to run on a Raspberry Pi 4 with 8 GB RAM, Ubuntu Server LTS, Laravel, Livewire, Flux Pro, Tailwind CSS, MariaDB, FreeRADIUS 3, and MikroTik routers.

The platform allows ISP shop owners to:

- Manage one or more shop locations.
- Attach multiple MikroTik routers to each shop.
- Sell voucherless internet plans through Flutterwave.
- Provision paid users automatically through FreeRADIUS.
- Apply speed limits, uptime limits, and fair usage policies.
- Run tenant-level marketing and retention workflows.

## 2. Architecture

```text
User Device
    |
    | Captive portal redirect
    v
MikroTik Router
    |
    | RADIUS auth/accounting UDP 1812/1813 over WireGuard
    v
FreeRADIUS 3
    |
    | SQL reads/writes
    v
MariaDB
    ^
    |
Laravel App <---- Flutterwave webhook
```

Recommended traffic boundaries:

- Public web traffic reaches Nginx and Laravel over HTTPS.
- Remote routers reach the Raspberry Pi through WireGuard.
- RADIUS ports should not be publicly exposed to the internet.
- FreeRADIUS reads from the same MariaDB instance used by Laravel, but Laravel remains the source of truth for tenant, shop, payment, subscription, and package logic.

## 3. Infrastructure Setup

### Target Environment

- OS: Ubuntu Server LTS 64-bit
- Web server: Nginx
- PHP runtime: PHP 8.3-FPM
- Database: MariaDB
- Queue/cache: Redis
- Process manager: Supervisor
- VPN: WireGuard
- RADIUS: FreeRADIUS 3 with `freeradius-mysql`

### Base Packages

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y \
  nginx mariadb-server redis-server supervisor git unzip wireguard \
  freeradius freeradius-mysql \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-xml \
  php8.3-mbstring php8.3-zip php8.3-bcmath php8.3-intl
```

### Production Hardening Checklist

- Enable HTTPS with a valid certificate.
- Bind MariaDB to localhost unless remote database access is required.
- Restrict RADIUS UDP 1812/1813 to the WireGuard interface or router subnet.
- Store all tenant payment credentials encrypted at rest.
- Rotate MikroTik RADIUS shared secrets per router.
- Enable Laravel queue workers through Supervisor.
- Configure scheduled jobs through cron.
- Back up MariaDB and `.env` secrets.

## 4. Database Design

FreeRADIUS tables can live in the same MariaDB database as Laravel application tables. Keep the FreeRADIUS schema compatible with `rlm_sql`, especially `radcheck`, `radreply`, `radacct`, and `nas`.

### Application Tables

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->string('company_name');
    $table->string('owner_email')->unique();
    $table->string('subscription_plan')->default('basic');
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamps();
});

Schema::create('shops', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('location_city')->nullable();
    $table->string('logo_path')->nullable();
    $table->text('flutterwave_client_id')->nullable();
    $table->text('flutterwave_client_secret')->nullable();
    $table->text('flutterwave_webhook_secret')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

Schema::create('routers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('nas_identifier')->unique();
    $table->string('wireguard_internal_ip')->unique();
    $table->text('shared_secret');
    $table->boolean('is_online')->default(false);
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
});

Schema::create('packages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->decimal('price', 10, 2);
    $table->string('currency', 3)->default('NGN');
    $table->unsignedInteger('limit_uptime_seconds');
    $table->string('speed_limit_profile');
    $table->unsignedBigInteger('fup_data_threshold_bytes')->nullable();
    $table->string('fup_speed_limit_profile')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

Schema::create('customers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
    $table->string('mac_address');
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->timestamps();

    $table->unique(['shop_id', 'mac_address']);
});

Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
    $table->foreignId('package_id')->constrained()->restrictOnDelete();
    $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
    $table->string('provider')->default('flutterwave');
    $table->string('tx_ref')->unique();
    $table->string('provider_reference')->nullable()->index();
    $table->decimal('amount', 10, 2);
    $table->string('currency', 3);
    $table->string('status')->default('pending');
    $table->json('payload')->nullable();
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();
});

Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
    $table->foreignId('package_id')->constrained()->restrictOnDelete();
    $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
    $table->string('mac_address');
    $table->timestamp('starts_at');
    $table->timestamp('expires_at');
    $table->boolean('is_throttled')->default(false);
    $table->timestamps();

    $table->index(['shop_id', 'mac_address']);
    $table->index(['expires_at']);
});

Schema::create('coupons', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('discount_type');
    $table->decimal('discount_value', 10, 2);
    $table->unsignedInteger('max_uses')->default(100);
    $table->unsignedInteger('used_count')->default(0);
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});
```

### FreeRADIUS Tables

Initialize the default FreeRADIUS SQL schema for MariaDB and confirm these tables exist:

- `radcheck`
- `radreply`
- `radacct`
- `nas`
- `radpostauth`

Important implementation notes:

- Mirror each active router into the FreeRADIUS `nas` table.
- Use the MikroTik `nas_identifier` consistently in Laravel, MikroTik, and FreeRADIUS.
- Keep generated RADIUS credentials idempotent so payment retries do not duplicate `radcheck` and `radreply` rows.
- Prefer deleting or expiring old rows when a subscription expires.

## 5. Laravel Application Modules

### Super Admin Panel

Use Livewire and Flux Pro for internal platform administration:

- Tenant CRUD and subscription plan management.
- Shop and router visibility across tenants.
- Platform coupon management.
- Payment status monitoring.
- Router health and last-seen reporting.
- Revenue and usage summaries.

### Tenant Dashboard

Each tenant should be able to:

- Manage shop branding.
- Configure Flutterwave credentials per shop.
- Create and deactivate internet packages.
- Register routers.
- Generate MikroTik setup scripts.
- View active sessions and payment history.
- Export customer and usage reports.

### Captive Portal

The MikroTik hotspot should redirect users to a Laravel route similar to:

```text
https://example.com/hotspot/portal?mac=$(mac)&nasid=$(identity)&link-login=$(link-login)&link-orig=$(link-orig)
```

The portal should:

- Resolve the router by `nasid`.
- Resolve the shop through the router.
- Display only active packages for that shop.
- Capture customer email or phone.
- Start a Flutterwave payment.
- Provision access only after verified payment success.

## 6. MikroTik Script Generation

Generate scripts per router from Laravel. Avoid hard-coding secrets in the guide or in shared support channels.

```php
public function generateMikrotikScript(Router $router): string
{
    $router->load('shop');

    $nasId = $router->nas_identifier;
    $secret = decrypt($router->shared_secret);
    $portalUrl = rtrim(config('app.url'), '/') . '/hotspot/portal';
    $portalHost = parse_url($portalUrl, PHP_URL_HOST);
    $radiusIp = config('services.radius.server_ip', '10.8.0.1');
    $wgEndpointHost = config('services.wireguard.endpoint_host', 'YOUR_PI_PUBLIC_IP');
    $wgEndpointPort = config('services.wireguard.endpoint_port', '13231');
    $wgPublicKey = config('services.wireguard.public_key', 'YOUR_PI_WG_PUBLIC_KEY');

    return <<<SCRIPT
/system identity set name="{$nasId}"
/interface wireguard add name=wg-saas listen-port=13231 mtu=1420
/interface wireguard peers add interface=wg-saas public-key="{$wgPublicKey}" endpoint-address={$wgEndpointHost} endpoint-port={$wgEndpointPort} allowed-address=10.8.0.1/32 persistent-keepalive=25s
/ip address add address={$router->wireguard_internal_ip}/24 interface=wg-saas
/radius add address={$radiusIp} secret="{$secret}" service=hotspot authentication-port=1812 accounting-port=1813 timeout=1000ms
/ip hotspot profile add name=saas-prof use-radius=yes login-by=http-chap,cookie,mac-cookie html-directory=flash/hotspot
/ip hotspot profile set saas-prof radius-accounting=yes
/ip hotspot walled-garden add dst-host={$portalHost} action=allow
SCRIPT;
}
```

Review the generated script against the exact RouterOS version in use. RouterOS syntax can vary across major versions.

## 7. Flutterwave Payment Flow

Store Flutterwave configuration per shop. Use config values for API base URLs so endpoint changes do not require code changes.

```php
namespace App\Services;

use App\Models\Package;
use App\Models\Shop;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FlutterwavePaymentService
{
    public function initializePayment(
        Shop $shop,
        Package $package,
        array $customerData,
        string $macAddress
    ): string {
        $clientId = Crypt::decryptString($shop->flutterwave_client_id);
        $clientSecret = Crypt::decryptString($shop->flutterwave_client_secret);
        $baseUrl = rtrim(config('services.flutterwave.base_url'), '/');

        $authResponse = Http::asJson()
            ->acceptJson()
            ->post("{$baseUrl}/oauth/token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ])
            ->throw();

        $token = data_get($authResponse->json(), 'data.access_token');
        $txRef = 'HS-' . Str::ulid();

        $response = Http::withToken($token)
            ->acceptJson()
            ->post("{$baseUrl}/collections", [
                'amount' => $package->price,
                'currency' => $package->currency,
                'tx_ref' => $txRef,
                'redirect_url' => route('payment.callback'),
                'customer' => [
                    'email' => $customerData['email'] ?? null,
                    'phone_number' => $customerData['phone'] ?? null,
                ],
                'meta' => [
                    'mac_address' => $macAddress,
                    'shop_id' => $shop->id,
                    'package_id' => $package->id,
                ],
            ])
            ->throw();

        // Persist payment before redirecting.
        // Return the provider checkout link from the response payload.
        return data_get($response->json(), 'data.link');
    }
}
```

Before production launch, confirm the current Flutterwave v4 base URL, authentication flow, payload fields, and webhook signature rules against the official Flutterwave documentation. Keep them in `config/services.php` and `.env`, not scattered across application code.

## 8. Webhook and Provisioning Logic

Webhook handling must be idempotent, signature-verified, and tenant-aware.

```php
public function handleWebhook(Request $request)
{
    $signature = $request->header('verif-hash');
    $payload = $request->all();

    $shopId = data_get($payload, 'data.meta.shop_id');
    $shop = Shop::findOrFail($shopId);

    abort_unless(
        hash_equals(Crypt::decryptString($shop->flutterwave_webhook_secret), (string) $signature),
        403
    );

    $txRef = data_get($payload, 'data.tx_ref');
    $status = data_get($payload, 'data.status');

    $payment = Payment::where('tx_ref', $txRef)->firstOrFail();

    if ($payment->status === 'successful') {
        return response()->json(['status' => 'already_processed']);
    }

    abort_unless($status === 'successful', 422);

    DB::transaction(function () use ($payment, $payload) {
        $package = $payment->package;
        $mac = data_get($payload, 'data.meta.mac_address');
        $password = 'authenticated_device_pass';

        $payment->update([
            'status' => 'successful',
            'provider_reference' => data_get($payload, 'data.id'),
            'payload' => $payload,
            'paid_at' => now(),
        ]);

        Subscription::updateOrCreate(
            [
                'shop_id' => $payment->shop_id,
                'mac_address' => $mac,
            ],
            [
                'package_id' => $package->id,
                'payment_id' => $payment->id,
                'starts_at' => now(),
                'expires_at' => now()->addSeconds($package->limit_uptime_seconds),
                'is_throttled' => false,
            ]
        );

        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $mac,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => $password,
            ]
        );

        DB::table('radreply')->updateOrInsert(
            [
                'username' => $mac,
                'attribute' => 'Session-Timeout',
            ],
            [
                'op' => ':=',
                'value' => $package->limit_uptime_seconds,
            ]
        );

        DB::table('radreply')->updateOrInsert(
            [
                'username' => $mac,
                'attribute' => 'Mikrotik-Rate-Limit',
            ],
            [
                'op' => ':=',
                'value' => $package->speed_limit_profile,
            ]
        );
    });

    return response()->json(['status' => 'processed']);
}
```

## 9. Network Automation

### Fair Usage Policy

Run a scheduled command every 5 minutes:

```cron
*/5 * * * * cd /var/www/hotspot && php artisan schedule:run >> /dev/null 2>&1
```

Example command logic:

```php
public function handle(): int
{
    $activeSessions = DB::table('radacct')
        ->whereNull('acctstoptime')
        ->get();

    foreach ($activeSessions as $session) {
        $totalBytes = (int) $session->acctinputoctets + (int) $session->acctoutputoctets;

        $subscription = Subscription::query()
            ->with('package')
            ->where('mac_address', $session->username)
            ->where('expires_at', '>', now())
            ->first();

        if (! $subscription || ! $subscription->package->fup_data_threshold_bytes) {
            continue;
        }

        if ($subscription->is_throttled) {
            continue;
        }

        if ($totalBytes <= $subscription->package->fup_data_threshold_bytes) {
            continue;
        }

        DB::table('radreply')
            ->where('username', $session->username)
            ->where('attribute', 'Mikrotik-Rate-Limit')
            ->update([
                'value' => $subscription->package->fup_speed_limit_profile ?? '1M/1M',
            ]);

        $subscription->update(['is_throttled' => true]);
    }

    return self::SUCCESS;
}
```

### Expiry Cleanup

Run a scheduled command that removes or disables expired access:

- Find subscriptions where `expires_at <= now()`.
- Delete or deactivate related `radcheck` and `radreply` records.
- Optionally disconnect active sessions through MikroTik API or CoA if configured.

### Router Health

Use one or more of:

- RADIUS accounting updates from `radacct`.
- MikroTik API polling over WireGuard.
- Heartbeat job from the router if available.

Update `routers.last_seen_at` and `routers.is_online` from that signal.

## 10. Marketing and Retention

Use Laravel queues for email and messaging workflows:

- Trial access links that create short-lived FreeRADIUS access.
- Follow-up campaigns for customers inactive for 7+ days.
- Payment success receipts.
- Expiry reminders before active subscriptions end.
- Tenant-level customer exports.

Avoid sending these messages synchronously from payment or portal requests.

## 11. Implementation Phases

### Phase 1: Infrastructure

- Install Nginx, PHP, MariaDB, Redis, Supervisor, WireGuard, and FreeRADIUS.
- Configure FreeRADIUS SQL.
- Confirm a MikroTik router can authenticate through FreeRADIUS over WireGuard.

### Phase 2: Core Laravel App

- Build tenant, shop, router, package, customer, payment, and subscription models.
- Add Livewire and Flux Pro admin panels.
- Add tenant dashboard screens.
- Implement MikroTik script generation.

### Phase 3: Captive Portal and Payments

- Build the Livewire captive portal.
- Resolve shop context from router `nasid`.
- Initialize Flutterwave payments.
- Verify and process webhooks.
- Provision FreeRADIUS access after confirmed payment.

### Phase 4: Automation

- Add FUP enforcement.
- Add subscription expiry cleanup.
- Add queue workers for notifications.
- Add router health checks.

### Phase 5: Production Readiness

- Add automated tests for payment provisioning and webhook idempotency.
- Add database backups.
- Add observability for failed jobs, payment errors, router status, and RADIUS rejects.
- Document tenant onboarding and router installation procedures.

## 12. Local Testing Setup

For development, place a test MikroTik router on the same bench as the Raspberry Pi and connect it through the WireGuard interface. Validate the full loop before deploying to a tenant site:

- Captive redirect opens the Laravel portal.
- The portal receives `mac` and `nasid`.
- Package checkout completes.
- Flutterwave webhook is verified.
- `radcheck` and `radreply` records are created.
- MikroTik grants internet access immediately.
- RADIUS accounting records appear in `radacct`.
- Expiry and FUP jobs update access as expected.
