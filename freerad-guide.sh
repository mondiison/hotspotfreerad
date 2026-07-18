cat << 'EOF' > ~/Documents/saas_blueprint.txt
================================================================================
MULTI-TENANT HOTSPOT SAAS DEVELOPER BLUEPRINT & CONFIGURATION GUIDE
================================================================================
Target OS: Ubuntu Desktop / Server 26.04 LTS (Native PHP 8.5)
Engine Stack: TALL Stack (Laravel 12 / Livewire / Alpine / Tailwind) + FreeRADIUS 3
Inspirations: daloRADIUS Case Study & Flutterwave v4 Unified API Architecture

--------------------------------------------------------------------------------
1. EXECUTIVE SYSTEM ARCHITECTURE
--------------------------------------------------------------------------------
This platform is an enterprise-grade, multi-tenant Wi-Fi Hotspot management SaaS
designed to host multiple tenants (ISP/Shop owners) running multiple physical
locations, with support for multiple MikroTik routers per location. It eliminates
physical vouchers entirely in favor of an automated payment, authorization, and
device-remembering flow.

Topology:
[Client Device] ────> [MikroTik Router (NAS)] ────> (WireGuard Tunnel 10.8.0.0/24)
                               │                               │
                      (RADIUS Auth / Acct)             (Captive Redirect)
                               ▼                               ▼
                      [FreeRADIUS 3 Engine]        [Laravel App / Portal (Pi 4)]
                               │                               │
                       (Direct DB Read/Write)         (Flutterwave v4 Auth/Webhooks)
                               ▼                               ▼
                      [MariaDB Core Database] <────────────────┘

--------------------------------------------------------------------------------
2. INFRASTRUCTURE SETUP (UBUNTU 26.04 LTS NATIVE)
--------------------------------------------------------------------------------
We bypass external repositories and use Ubuntu 26.04's native PHP 8.5 and core
stacks to ensure a long support runway (through December 2029) and optimal
resource handling on a Raspberry Pi 4 or production VPS.

Commands:
$ sudo apt update && sudo apt upgrade -y
$ sudo apt install -y nginx mariadb-server php-fpm php-mysql php-curl php-xml php-mbstring php-zip php-redis php-bcmath freeradius freeradius-mysql wireguard redis-server supervisor git unzip

--------------------------------------------------------------------------------
3. DATABASE SCHEMA: APP-RADIUS UNIFIED ENGINE
--------------------------------------------------------------------------------
To mimic daloRADIUS's battle-tested efficiency, we bypass editing user-specific
attributes directly in `radreply` for every transaction. Instead, we use RADIUS
Group Profiles (radusergroup, radgroupreply, radgroupcheck).
* When a user pays, Laravel simply binds their MAC address to a group profile
  (e.g., 'tenant_1_5mbps') in radusergroup.
* Profiles are defined once in radgroupreply.

Laravel Migrations Block:

// 1. Tenants (Subscribers to your SaaS platform)
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->string('company_name');
    $table->string('owner_email')->unique();
    $table->string('subscription_tier')->default('basic'); // basic, premium, enterprise
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamps();
});

// 2. Shops/Locations
Schema::create('shops', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
    $table->string('name');
    $table->string('location_city');
    $table->string('logo_path')->nullable();
    $table->text('flw_v4_client_id')->nullable();     // Encrypted (Crypt::encryptString)
    $table->text('flw_v4_client_secret')->nullable(); // Encrypted (Crypt::encryptString)
    $table->text('flw_v4_webhook_secret')->nullable(); // Encrypted (Crypt::encryptString)
    $table->timestamps();
});

// 3. Routers (Supports multiple NAS clients per shop)
Schema::create('routers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->onDelete('cascade');
    $table->string('nas_identifier')->unique(); // Links directly to radacct/nas tables
    $table->string('wireguard_internal_ip')->unique();
    $table->string('shared_secret'); // Used by FreeRADIUS to authorize NAS requests
    $table->boolean('is_online')->default(false);
    $table->timestamps();
});

// 4. Packages/Plans (Group profiles mapped to RADIUS profiles)
Schema::create('packages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->onDelete('cascade');
    $table->string('name'); // e.g., "1 Hour Ultra"
    $table->string('radius_group_name'); // e.g., "tenant_3_shop_1_1hr_ultra"
    $table->decimal('price', 10, 2);
    $table->string('currency')->default('NGN');
    $table->integer('limit_uptime_seconds');
    $table->string('speed_limit_profile');  // e.g., "5M/5M" (Mikrotik-Rate-Limit format)
    $table->bigInteger('fup_data_threshold_bytes')->nullable(); // Premium FUP trigger limit
    $table->timestamps();
});

// 5. Coupons (Super Admin Managed Promotions)
Schema::create('coupons', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('discount_type'); // 'percentage' or 'fixed'
    $table->decimal('discount_value', 10, 2);
    $table->integer('max_uses')->default(100);
    $table->integer('used_count')->default(0);
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});

--------------------------------------------------------------------------------
4. FREERADIUS 3 INTEGRATION (daloRADIUS-style MySQL Schema)
--------------------------------------------------------------------------------
Ensure your schema contains the standard RADIUS tables. When a package is created
in Laravel, the app populates the matching profiles in the background:

INSERT INTO radgroupreply (groupname, attribute, op, value)
VALUES ('tenant_1_5mbps', 'Mikrotik-Rate-Limit', ':=', '5M/5M');

INSERT INTO radgroupreply (groupname, attribute, op, value)
VALUES ('tenant_1_5mbps', 'Session-Timeout', ':=', '3600');

--------------------------------------------------------------------------------
5. MODERN APPLICATION DEVELOPMENT GUIDE
--------------------------------------------------------------------------------

[Module 1: Automated Tenant Router Provisioning Script]
Generate a text block configuration payload containing MikroTik CLI commands:

public function generateMikrotikProvisioningScript(Router $router)
{
    $nasId = $router->nas_identifier;
    $secret = $router->shared_secret;
    $wgIp = $router->wireguard_internal_ip;

    return <<<MIKROTIK
    # 1. Setup Outbound VPN Tunnel back to SaaS Server
    /interface wireguard add listen-port=13231 name=wg-saas
    /interface wireguard peers add allowed-address=10.8.0.1/32 endpoint-address="YOUR_SERVER_PUBLIC_IP:13231" interface=wg-saas public-key="YOUR_SERVER_WG_PUBLIC_KEY"
    /ip address add address={$wgIp}/24 interface=wg-saas

    # 2. Add RADIUS authentication servers
    /radius add address=10.8.0.1 secret="{$secret}" service=hotspot authentication-port=1812 accounting-port=1813 timeout=300ms

    # 3. Apply MAC Authentication & Profile Paths
    /ip hotspot profile add name=saas-profile hotspot-address=192.168.88.1 login-by=http-chap,cookie,mac use-radius=yes nas-port-type=19
    /ip hotspot profile set saas-profile mac-auth=yes dns-name="hotspot.local"
    MIKROTIK;
}

[Module 2: Captive Portal Handshake & Flutterwave v4 API Payments]
When a user connects, the local MikroTik intercepts the client request and redirects:
https://yoursaas.com/hotspot/portal?mac=$(mac)&nasid=SHOP_ROUTER_ID

Flutterwave v4 Tokenized Collection Service Integration:

namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

class FlutterwaveV4Service {
    public function generateCheckoutLink($shop, $package, $customerData, $macAddress) {
        $clientId = Crypt::decryptString($shop->flw_v4_client_id);
        $clientSecret = Crypt::decryptString($shop->flw_v4_client_secret);

        // 1. Fetch OAuth v4 Session Token
        $authResponse = Http::post('https://f4bexperience.flutterwave.com/v4/oauth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials'
        ]);

        $token = $authResponse->json()['data']['access_token'];

        // 2. Generate Collections Request
        $paymentResponse = Http::withToken($token)
            ->post('https://f4bexperience.flutterwave.com/v4/collections', [
                'amount' => $package->price,
                'currency' => $package->currency,
                'tx_ref' => 'HS-TX-' . uniqid(),
                'redirect_url' => route('payment.callback'),
                'customer' => [
                    'email' => $customerData['email'],
                    'phonenumber' => $customerData['phone']
                ],
                'meta' => [
                    'mac_address' => $macAddress,
                    'shop_id' => $shop->id,
                    'package_id' => $package->id
                ]
            ]);

        return $paymentResponse->json()['data']['link'];
    }
}

Flutterwave Webhook Controller Hook:

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlutterwaveWebhookController extends Controller {
    public function handle(Request $request) {
        $verifiedSignature = $request->header('verif-hash');
        $payload = $request->input('data');

        $mac = $payload['meta']['mac_address'];
        $packageId = $payload['meta']['package_id'];
        $package = Package::find($packageId);

        // daloRADIUS Principle: Create/verify the user MAC, bind to Group Profile
        DB::table('radcheck')->updateOrInsert(
            ['username' => $mac],
            ['attribute' => 'Cleartext-Password', 'op' => '==', 'value' => 'authenticated_pass']
        );

        DB::table('radusergroup')->updateOrInsert(
            ['username' => $mac],
            ['groupname' => $package->radius_group_name, 'priority' => 1]
        );

        return response()->json(['status' => 'access_granted'], 200);
    }
}

--------------------------------------------------------------------------------
6. PREMIUM SAAS CORE AUTOMATION SERVICES
--------------------------------------------------------------------------------

[1. Dynamic Fair Usage Policy Throttler (Background Cron)]
Configure scheduling console task running every 5 minutes:

public function handle() {
    $activeLeases = DB::table('radacct')->whereNull('acctstoptime')->get();

    foreach($activeLeases as $lease) {
        $totalBytes = $lease->acctinputoctets + $lease->acctoutputoctets;

        $package = DB::table('packages')
            ->join('radusergroup', 'packages.radius_group_name', '=', 'radusergroup.groupname')
            ->where('radusergroup.username', $lease->username)
            ->first();

        if ($package && $package->fup_data_threshold_bytes && $totalBytes > $package->fup_data_threshold_bytes) {
            DB::table('radreply')->updateOrInsert(
                ['username' => $lease->username, 'attribute' => 'Mikrotik-Rate-Limit'],
                ['op' => ':=', 'value' => '1M/1M']
            );
            $this->disconnectUserSession($lease->nasipaddress, $lease->username);
        }
    }
}

[2. Live Session Disconnection: PoD / CoA (Packet of Disconnect)]
Fires an RFC 3576 disconnect ping command to terminate sessions in real time:

private function disconnectUserSession($nasIp, $username) {
    $command = "echo \"User-Name = $username\" | radclient -x $nasIp:1700 disconnect 'secret_shared'";
    shell_exec($command);
}

--------------------------------------------------------------------------------
7. SAAS PROJECT DEVELOPMENT TIMELINE
--------------------------------------------------------------------------------
* STAGE 1: Sandbox Configuration (Complete)
    - Set up Ubuntu Desktop 26.04.
    - Map database targets to FreeRADIUS and verify debug outputs.
* STAGE 2: Backend Control Core
    - Initialize Laravel project environment, multi-tenancy rules, and Filament layouts.
    - Design and deploy the custom script generation module.
* STAGE 3: Portal & Flutterwave v4 Pipeline
    - Create zero-dependency landing pages targeting incoming client parameters.
    - Map OAuth v4 authentication tokens and webhook receivers.
* STAGE 4: Enterprise Automations & Launch
    - Build dynamic FUP background throttlers and Mail queues.
    - Pilot using a local test router connected to your sandbox PC.
================================================================================
EOF
