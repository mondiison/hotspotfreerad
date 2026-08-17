<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Talks to a self-hosted ZeroTier network controller running on this same
 * machine (the Pi) -- NOT ZeroTier Central's cloud service. Central's free
 * plan caps at 10 devices and has no API access at all (Essential plan,
 * $18+/mo, required for automated member authorization); a self-hosted
 * controller has neither limitation, since it's just the local zerotier-one
 * service's own controller mode, authenticated with its own auth-token file
 * rather than a paid account. See docs/zerotier-fallback-setup.md.
 *
 * Every method returns a {success, ...} array rather than throwing -- unlike
 * the payment gateway services in this codebase (which throw and let the
 * caller handle it), this needs to be safely callable from a scheduled job
 * (ZeroTierMembershipSyncService) without an uncaught exception aborting the
 * whole run, mirroring RouterOsConnectionService's convention.
 *
 * Confirmed against a real controller (2026-08-19, live report) after this
 * had never been exercised against a real Pi: `GET .../member` does NOT
 * return an array of full member objects -- it returns a flat
 * `{address: revision}` map (e.g. `{"aaaa111111": 3}`), so per-member detail
 * (`authorized`/`ipAssignments`) needs a second GET per address, `.../member/
 * {address}` -- the same endpoint `setAuthorized()` already POSTs to. That
 * single-member response has `authorized`/`ipAssignments` as flat top-level
 * fields, NOT nested under a `"config"` key -- both `listMembers()` and
 * `setAuthorized()` originally assumed a `{"config": {...}}` wrapper (never
 * verified live), which meant every authorize/deauthorize POST silently
 * succeeded (HTTP 200) without actually changing anything on the controller.
 */
class ZeroTierControllerService
{
    /**
     * @return array{success: bool, members?: list<array{node_id: string, authorized: bool, ip_assignments: list<string>}>, error?: string}
     */
    public function listMembers(): array
    {
        $token = $this->authToken();

        if ($token === null) {
            return ['success' => false, 'error' => 'ZeroTier controller auth token not readable -- check services.zerotier.auth_token_path.'];
        }

        $networkId = (string) config('services.zerotier.network_id');

        if (blank($networkId) || $networkId === 'YOUR_ZEROTIER_NETWORK_ID') {
            return ['success' => false, 'error' => 'ZEROTIER_NETWORK_ID is not configured.'];
        }

        try {
            $addresses = Http::withHeaders(['X-ZT1-Auth' => $token])
                ->acceptJson()
                ->timeout(5)
                ->get($this->baseUrl().'/controller/network/'.$networkId.'/member')
                ->throw()
                ->json();

            $members = collect($addresses)
                ->keys()
                ->map(function (string $nodeId) use ($token, $networkId): array {
                    $member = Http::withHeaders(['X-ZT1-Auth' => $token])
                        ->acceptJson()
                        ->timeout(5)
                        ->get($this->baseUrl().'/controller/network/'.$networkId.'/member/'.$nodeId)
                        ->throw()
                        ->json();

                    return [
                        'node_id' => $nodeId,
                        'authorized' => (bool) ($member['authorized'] ?? false),
                        'ip_assignments' => (array) ($member['ipAssignments'] ?? []),
                    ];
                })
                ->values()
                ->all();

            return ['success' => true, 'members' => $members];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function authorizeMember(Router $router): array
    {
        return $this->setAuthorized((string) $router->zerotier_node_id, true, $router->zerotier_ip);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function deauthorizeMember(string $nodeId): array
    {
        return $this->setAuthorized($nodeId, false);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    private function setAuthorized(string $nodeId, bool $authorized, ?string $ip = null): array
    {
        if (blank($nodeId)) {
            return ['success' => false, 'error' => 'No ZeroTier node ID given.'];
        }

        $token = $this->authToken();

        if ($token === null) {
            return ['success' => false, 'error' => 'ZeroTier controller auth token not readable -- check services.zerotier.auth_token_path.'];
        }

        $networkId = (string) config('services.zerotier.network_id');

        if (blank($networkId) || $networkId === 'YOUR_ZEROTIER_NETWORK_ID') {
            return ['success' => false, 'error' => 'ZEROTIER_NETWORK_ID is not configured.'];
        }

        $payload = ['authorized' => $authorized];

        if ($authorized && filled($ip)) {
            $payload['ipAssignments'] = [$ip];
        }

        try {
            Http::withHeaders(['X-ZT1-Auth' => $token])
                ->acceptJson()
                ->timeout(5)
                ->post($this->baseUrl().'/controller/network/'.$networkId.'/member/'.$nodeId, $payload)
                ->throw();

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function authToken(): ?string
    {
        $path = (string) config('services.zerotier.auth_token_path');

        if (blank($path) || ! File::exists($path)) {
            return null;
        }

        $token = trim(File::get($path));

        return $token !== '' ? $token : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.zerotier.controller_url'), '/');
    }
}
