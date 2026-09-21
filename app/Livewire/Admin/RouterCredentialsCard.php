<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterManagementService;
use App\Services\RouterOsConnectionService;
use App\Services\ZeroTierMembershipSyncService;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RouterCredentialsCard extends Component
{
    #[Locked]
    public int $routerId;

    public bool $showRegenerateWireguardKeyModal = false;

    public bool $showRegenerateApiCredentialsModal = false;

    public ?string $zerotierNodeId = null;

    public function mount(Router $router): void
    {
        $this->routerId = $router->id;
        $this->zerotierNodeId = $router->zerotier_node_id;
    }

    public function confirmRegenerateWireguardKey(): void
    {
        $this->showRegenerateWireguardKeyModal = true;
    }

    public function regenerateWireguardKey(RouterManagementService $routers): void
    {
        $router = Router::find($this->routerId);
        $this->showRegenerateWireguardKeyModal = false;

        if (! $router) {
            return;
        }

        $routers->regenerateWireguardKey($router, auth()->user());

        Flux::toast(
            heading: 'WireGuard key generated',
            text: 'MMS Radius refreshed the Pi peer list. Re-run the WireGuard section of the script on the physical router.',
            variant: 'success',
        );
    }

    public function confirmRegenerateApiCredentials(): void
    {
        $this->showRegenerateApiCredentialsModal = true;
    }

    public function regenerateApiCredentials(RouterManagementService $routers): void
    {
        $router = Router::find($this->routerId);
        $this->showRegenerateApiCredentialsModal = false;

        if (! $router) {
            return;
        }

        $routers->regenerateApiCredentials($router, auth()->user());

        Flux::toast(
            heading: 'RouterOS API credentials generated',
            text: 'Re-run the script on this router to apply the new credentials.',
            variant: 'success',
        );
    }

    public function testConnection(RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $result = $routerOs->testConnection($router);

        Flux::toast(
            heading: $result['success'] ? 'Connected' : 'Could not connect',
            text: $result['success']
                ? 'RouterOS reports identity "'.$result['identity'].'".'
                : $result['error'],
            variant: $result['success'] ? 'success' : 'danger',
        );
    }

    /**
     * Saves just the ZeroTier node ID right here on the router's own page --
     * previously this needed the full 4-step edit wizard (Identity step)
     * just to update one field, which is real friction on a router that gets
     * reset repeatedly during testing and needs a fresh node ID pasted in
     * every time. Clearing zerotier_authorized_at when the ID actually
     * changes matters here specifically: this card displays "Authorized X
     * ago" straight off that timestamp, and leaving it in place after typing
     * in a brand-new ID would misleadingly claim the NEW node is already
     * authorized when it never has been.
     */
    public function saveZeroTierNodeId(): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $validated = validator(
            ['zerotier_node_id' => $this->zerotierNodeId],
            ['zerotier_node_id' => ['nullable', 'string', 'max:16', Rule::unique('routers')->ignore($router)]],
        )->validate();

        $newNodeId = filled($validated['zerotier_node_id']) ? trim($validated['zerotier_node_id']) : null;
        $changed = $newNodeId !== $router->zerotier_node_id;

        $router->forceFill([
            'zerotier_node_id' => $newNodeId,
            'zerotier_authorized_at' => $changed ? null : $router->zerotier_authorized_at,
        ])->save();

        $this->zerotierNodeId = $newNodeId;

        Flux::toast(
            heading: 'ZeroTier node ID saved',
            text: $changed && $newNodeId !== null
                ? 'Cleared the previous authorization timestamp since the ID changed -- click "Authorize & connect now" below.'
                : 'Saved.',
            variant: 'success',
        );
    }

    /**
     * Manual "don't wait for the 5-minute cycle" button: authorizes this
     * router's saved node ID on the ZeroTier controller right now, then
     * immediately pushes syncZeroTierNetworkMembership() over the live
     * RouterOS API so the router re-requests its network status instead of
     * sitting on a stale ACCESS_DENIED until the next scheduled sync (or
     * another manual "Provision via API" click) comes along. Combines what
     * was previously two separate manual steps (hotspot:sync-zerotier-members,
     * then remove/re-add the interface by hand on the router console) into
     * one click.
     */
    public function authorizeZeroTier(ZeroTierMembershipSyncService $zeroTierMembers, RouterOsConnectionService $routerOs): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $authorize = $zeroTierMembers->authorizeRouter($router);

        if (! $authorize['success']) {
            Flux::toast(heading: 'Could not authorize', text: $authorize['error'], variant: 'danger');

            return;
        }

        $sync = $routerOs->syncZeroTierNetworkMembership($router);

        Flux::toast(
            heading: $sync['success'] ? 'Authorized and connected' : 'Authorized, but the router-side sync had trouble',
            text: $sync['success']
                ? 'Approved on the ZeroTier controller and pushed live to the router -- check "/zerotier interface print" on the router for STATUS "OK".'
                : collect($sync['steps'])->map(fn (array $step): string => ($step['success'] ? 'OK' : 'FAILED').' - '.$step['label'].($step['error'] ? ' ('.$step['error'].')' : ''))->implode(' | '),
            variant: $sync['success'] ? 'success' : 'danger',
        );
    }

    public function render()
    {
        return view('livewire.admin.router-credentials-card', [
            'router' => Router::find($this->routerId),
        ]);
    }
}
