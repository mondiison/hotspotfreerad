<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\RouterOsConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-pushes a router's walled-garden allow-list live over the RouterOS API --
 * dispatched whenever a shop's active payment gateway changes, so the new
 * gateway's hosted-checkout domain is reachable without an admin needing to
 * remember to click "Provision via API" or re-paste the script. Silently a
 * no-op for routers with no RouterOS API credentials yet or that are offline
 * (best-effort, matching how the rest of live provisioning behaves) -- this
 * runs on the default queue processed by the existing worker service.
 */
class SyncRouterWalledGarden implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $routerId) {}

    public function routerId(): int
    {
        return $this->routerId;
    }

    public function handle(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router || ! $routerOs->isConfigured($router)) {
            return;
        }

        $routerOs->syncWalledGarden($router);
    }
}
