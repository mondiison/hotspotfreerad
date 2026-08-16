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
            $response = Http::withHeaders(['X-ZT1-Auth' => $token])
                ->acceptJson()
                ->timeout(5)
                ->get($this->baseUrl().'/controller/network/'.$networkId.'/member')
                ->throw()
                ->json();

            $members = collect($response)
                ->map(fn (array $member): array => [
                    'node_id' => (string) ($member['nodeId'] ?? ''),
                    'authorized' => (bool) ($member['config']['authorized'] ?? false),
                    'ip_assignments' => (array) ($member['config']['ipAssignments'] ?? []),
                ])
                ->filter(fn (array $member): bool => $member['node_id'] !== '')
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

        $config = ['authorized' => $authorized];

        if ($authorized && filled($ip)) {
            $config['ipAssignments'] = [$ip];
        }

        try {
            Http::withHeaders(['X-ZT1-Auth' => $token])
                ->acceptJson()
                ->timeout(5)
                ->post($this->baseUrl().'/controller/network/'.$networkId.'/member/'.$nodeId, ['config' => $config])
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
