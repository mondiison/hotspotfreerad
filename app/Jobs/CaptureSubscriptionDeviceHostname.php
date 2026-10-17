<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Subscription;
use App\Services\RouterOsConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 2026-10-17, direct request: the admin panel only ever showed a
 * subscription's MAC address, which is exactly the thing iOS/Android's
 * privacy-randomized Wi-Fi addressing keeps changing on reconnect --
 * making it hard for staff to tell which physical device a subscription
 * actually belongs to during a support call. A DHCP lease's host-name
 * (the device's own self-reported name, e.g. "Johns-iPhone") is a much
 * more durable identifier, and RouterOS already records it on every
 * lease -- the same source RouterConnectedDevices already reads via
 * dhcpLeases().
 *
 * Dispatched from RadiusProvisioningService::grantSubscriptionAccess()
 * -- the one shared method every grant path (paid checkout, voucher
 * redemption, free trial, the debug test-access button, and both the
 * manual "Change device" and automated MAC-recovery paths) already
 * funnels through -- rather than looked up live on every admin page
 * view. Looking it up live on every view would mean a RouterOS API
 * round trip (with real router-reachability risk) each time an admin
 * opens that one subscription's detail, for a value that essentially
 * never changes once captured. Capturing it synchronously inside the
 * grant flow instead would add that same live API call to every
 * customer-facing checkout -- this job exists specifically to avoid
 * both: it runs on the default queue (the existing hotspotfreerad-
 * worker service), after the customer's own request has already been
 * answered, so checkout latency is completely unaffected either way.
 *
 * A subscription has no router_id of its own (it's shop-scoped, not
 * router-scoped -- a shop can have more than one router), so this tries
 * every router belonging to the subscription's shop and stops at the
 * first lease match for its MAC -- the same small-N "one request per
 * router" tradeoff WireGuardRouteSyncService/ZeroTierMembershipSyncService
 * already accept elsewhere in this codebase. Best-effort throughout: a
 * router with no API credentials yet, an unreachable router, or simply
 * no matching lease (the device hasn't pulled one yet, or it expired)
 * all leave device_hostname untouched rather than retrying forever --
 * it's a convenience label, not something anything else depends on.
 */
class CaptureSubscriptionDeviceHostname implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(private readonly int $subscriptionId) {}

    public function handle(RouterOsConnectionService $routerOs): void
    {
        $subscription = Subscription::find($this->subscriptionId);

        if (! $subscription) {
            return;
        }

        $routers = Router::query()->where('shop_id', $subscription->shop_id)->get();

        foreach ($routers as $router) {
            if (! $routerOs->isConfigured($router)) {
                continue;
            }

            $result = $routerOs->dhcpLeases($router);

            if (! ($result['success'] ?? false)) {
                continue;
            }

            foreach ($result['leases'] ?? [] as $lease) {
                $leaseMac = strtoupper((string) ($lease['mac_address'] ?? ''));
                $hostname = $lease['hostname'] ?? null;

                if ($leaseMac === strtoupper($subscription->mac_address) && filled($hostname)) {
                    $subscription->update(['device_hostname' => $hostname]);

                    return;
                }
            }
        }
    }
}
