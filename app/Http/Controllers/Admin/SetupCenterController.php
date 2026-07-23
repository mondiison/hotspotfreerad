<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Support\TenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SetupCenterController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $shopQuery = TenantAccess::scopeShops(Shop::with(['tenant', 'routers']), $user);
        $shops = $shopQuery->withCount(['routers', 'packages'])->orderBy('name')->get();
        $routerCount = TenantAccess::scopeRouters(Router::query(), $user)->count();
        $activePackageCount = TenantAccess::scopePackages(Package::query(), $user)
            ->where('is_active', true)
            ->whereIn('service_type', ['hotspot', 'both'])
            ->count();
        $activePppoePackageCount = TenantAccess::scopePackages(Package::query(), $user)
            ->where('is_active', true)
            ->whereIn('service_type', ['pppoe', 'both'])
            ->count();
        $tenant = $user->tenant_id ? Tenant::find($user->tenant_id) : null;
        $firstRouter = TenantAccess::scopeRouters(Router::with('shop.tenant'), $user)->oldest()->first();

        $paymentReady = [
            'opay_transfer' => $shops->filter->hasCompleteFlutterwaveCredentials()->count(),
            'card' => $shops->filter->hasFlutterwaveHostedCheckoutKey()->count(),
            'webhook' => $shops->filter->hasFlutterwaveWebhookSecret()->count(),
        ];

        $steps = [
            [
                'phase' => 'Workspace',
                'label' => 'Brand the tenant workspace',
                'description' => 'Set logo, brand color, public page copy, and offer images before customers see the portal.',
                'complete' => $tenant
                    ? (filled($tenant->brand_color) && ($tenant->logo_image_path || $tenant->hero_image_path || filled($tenant->public_site_tagline)))
                    : $user->isSuperAdmin(),
                'route' => $user->isSuperAdmin() ? 'admin.tenants.index' : 'admin.brand.edit',
                'action' => $user->isSuperAdmin() ? 'Review tenants' : 'Open brand settings',
                'hint' => $tenant?->publicUrl() ?? 'Tenant public page appears after tenant creation.',
            ],
            [
                'phase' => 'Location',
                'label' => 'Create the hotspot shop',
                'description' => 'A shop represents a branch, park, lounge, estate, or Wi-Fi coverage area.',
                'complete' => $shops->isNotEmpty(),
                'route' => $shops->isNotEmpty() ? 'admin.shops.index' : 'admin.shops.create',
                'action' => $shops->isNotEmpty() ? 'Review shops' : 'Add first shop',
                'hint' => $shops->isNotEmpty() ? $shops->count().' shop/location record ready.' : 'Routers, packages, and payment settings attach to shops.',
            ],
            [
                'phase' => 'Network',
                'label' => 'Register MikroTik router',
                'description' => 'Add NAS identifier, WireGuard internal IP, and shared secret, then copy the generated RouterOS script.',
                'complete' => $routerCount > 0,
                'route' => $firstRouter ? 'admin.routers.show' : 'admin.routers.create',
                'route_parameters' => $firstRouter ? [$firstRouter] : [],
                'action' => $firstRouter ? 'Open router script' : 'Add router',
                'hint' => $routerCount > 0 ? $routerCount.' router registered in FreeRADIUS.' : 'This is what connects MikroTik hotspot login to RADIUS.',
            ],
            [
                'phase' => 'Offer',
                'label' => 'Publish internet packages',
                'description' => 'Create packages with price, uptime, total data, speed limit, and FUP rules.',
                'complete' => $activePackageCount > 0,
                'route' => $activePackageCount > 0 ? 'admin.packages.index' : 'admin.packages.create',
                'action' => $activePackageCount > 0 ? 'Review packages' : 'Add package',
                'hint' => $activePackageCount > 0 ? $activePackageCount.' active hotspot package available on the captive portal.' : 'Customers need at least one active hotspot package to buy access.',
            ],
            [
                'phase' => 'Payments',
                'label' => 'Connect tenant Flutterwave account',
                'description' => 'Save v4 Client ID/Secret for OPay and transfer, v3 Secret Key for card, and webhook hash for automatic confirmation.',
                'complete' => $paymentReady['opay_transfer'] > 0 && $paymentReady['card'] > 0 && $paymentReady['webhook'] > 0,
                'route' => $shops->isEmpty() ? 'admin.shops.create' : 'admin.payment-settings.index',
                'action' => $shops->isEmpty() ? 'Add shop first' : 'Open payment setup',
                'hint' => $paymentReady['opay_transfer'].' OPay/transfer ready, '.$paymentReady['card'].' card ready, '.$paymentReady['webhook'].' webhook ready.',
            ],
            [
                'phase' => 'Test',
                'label' => 'Run a live captive portal test',
                'description' => 'Connect a phone to the MikroTik hotspot, choose a package, pay, and confirm automatic login.',
                'complete' => false,
                'route' => 'admin.subscriptions.index',
                'action' => 'Check access grants',
                'hint' => 'Use a real phone test after router, packages, and payment settings are ready.',
            ],
        ];

        $provisioningMethods = [
            [
                'label' => 'MikroTik Hotspot',
                'status' => 'Live',
                'description' => 'Best for walk-in Wi-Fi, cafes, parks, lounges, schools, and short-term prepaid internet.',
                'customer_identity' => 'Device MAC through captive portal',
                'router_work' => 'Hotspot profile, walled garden, login.html redirect, RADIUS hotspot service.',
                'billing_work' => 'Customer selects package on portal, pays, then gets automatic access.',
                'action_route' => $firstRouter ? 'admin.routers.show' : 'admin.routers.create',
                'action_parameters' => $firstRouter ? [$firstRouter] : [],
                'action' => $firstRouter ? 'Open Hotspot script' : 'Add router',
            ],
            [
                'label' => 'MikroTik PPPoE',
                'status' => 'Guided',
                'description' => 'Best for home broadband, estates, fixed wireless, CPE installs, and monthly subscribers.',
                'customer_identity' => 'Username and password assigned to customer/CPE',
                'router_work' => 'PPPoE server, PPP profile, RADIUS ppp service, accounting enabled.',
                'billing_work' => 'Customer account renewal extends PPPoE credentials instead of captive portal MAC access.',
                'action_route' => 'admin.setup.index',
                'action_parameters' => ['method' => 'pppoe'],
                'action' => 'View PPPoE guide',
            ],
            [
                'label' => 'MikroTik CPE / Client Router',
                'status' => 'Guide',
                'description' => 'Best when each customer has a MikroTik CPE that dials PPPoE to your main router.',
                'customer_identity' => 'CPE PPPoE client username and password',
                'router_work' => 'Configure CPE WAN as PPPoE client, set service credentials, optionally lock wireless/LAN.',
                'billing_work' => 'Tenant provisions or renews the PPPoE account from MMS Radius.',
                'action_route' => 'admin.setup.index',
                'action_parameters' => ['method' => 'cpe'],
                'action' => 'View CPE guide',
            ],
            [
                'label' => 'Other Router / ONT',
                'status' => 'Guide',
                'description' => 'For TP-Link, Ubiquiti, Huawei ONT, FiberHome, and other devices that support PPPoE client mode.',
                'customer_identity' => 'PPPoE username and password entered into WAN settings',
                'router_work' => 'Set WAN connection type to PPPoE and enter the customer credentials.',
                'billing_work' => 'MMS Radius manages the RADIUS account while the device only needs PPPoE credentials.',
                'action_route' => 'admin.setup.index',
                'action_parameters' => ['method' => 'other-cpe'],
                'action' => 'View generic guide',
            ],
        ];

        $pppoeWizard = [
            [
                'title' => 'Prepare the access router',
                'detail' => 'Choose the MikroTik interface or VLAN that will serve subscribers. Enable PPPoE server there, then point PPP authentication/accounting to FreeRADIUS over WireGuard.',
                'example' => '/radius add address='.config('services.radius.server_ip').' secret="RADIUS_SECRET" service=ppp authentication-port='.config('services.radius.auth_port').' accounting-port='.config('services.radius.acct_port'),
            ],
            [
                'title' => 'Create the subscriber profile',
                'detail' => 'Use package limits to decide uptime, speed, and total transfer. PPPoE accounts will map to RADIUS groups in the same spirit as hotspot packages.',
                'example' => 'Active PPPoE-ready plans: '.$activePppoePackageCount.'. Example: Home 10M monthly, username: customer001, password: generated or custom.',
            ],
            [
                'title' => 'Provision the customer device',
                'detail' => 'For MikroTik CPE, set WAN to PPPoE client. For other brands, use Internet/WAN settings and choose PPPoE. Enter the assigned username and password.',
                'example' => 'WAN type: PPPoE, username: customer001, password: ********, service name: optional.',
            ],
            [
                'title' => 'Confirm accounting and renewal',
                'detail' => 'When the device connects, FreeRADIUS accounting should show the online session. Renewal should extend the PPPoE account rather than using the hotspot portal.',
                'example' => 'Check radacct for username, NAS IP, framed IP, upload, download, and session start time.',
            ],
        ];

        return view('admin.setup.index', [
            'steps' => $steps,
            'shops' => $shops,
            'firstRouter' => $firstRouter,
            'paymentReady' => $paymentReady,
            'provisioningMethods' => $provisioningMethods,
            'pppoeWizard' => $pppoeWizard,
            'completedCount' => collect($steps)->where('complete', true)->count(),
        ]);
    }
}
