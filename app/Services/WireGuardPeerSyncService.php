<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Process;

class WireGuardPeerSyncService
{
    /**
     * Reconcile the Raspberry Pi's WireGuard peers against the routers table.
     *
     * Only adds/updates peers derived from `Router::wireguard_public_key`; it never
     * removes a peer, so manually added peers (e.g. an admin's own management
     * access) are left alone. Requires WIREGUARD_MANAGE_PEERS=true and every `wg`/
     * `wg-quick` call here runs through `sudo -n` (non-interactive, so it fails fast
     * instead of hanging a cron/queue process if the sudoers rule is missing) --
     * see docs/wireguard-server-setup.md for the one-time NOPASSWD sudoers grant
     * this depends on. Setting a peer's allowed-ips is the one operation that needs
     * per-router variable arguments, which some hardened sudo builds flatly refuse
     * to grant via a wildcarded sudoers rule ("wildcards are not allowed in command
     * arguments") -- so that one goes through a small stdin-driven wrapper script
     * (`services.wireguard.set_peer_script`) that itself takes no CLI arguments at
     * all, letting its sudoers rule be argument-free too.
     *
     * @return array{enabled: bool, interface: string, binary_available: bool, desired: array<string,string>, current: array<string,string>, added: array<string,string>, updated: array<string,string>, errors: array<int,string>}
     */
    public function reconcile(bool $dryRun = false): array
    {
        $interface = (string) config('services.wireguard.interface', 'wg-saas');
        $enabled = (bool) config('services.wireguard.manage_peers', false);

        $result = [
            'enabled' => $enabled,
            'interface' => $interface,
            'binary_available' => false,
            'desired' => $this->desiredPeers(),
            'current' => [],
            'added' => [],
            'updated' => [],
            'errors' => [],
        ];

        if (! $enabled) {
            return $result;
        }

        try {
            $show = Process::run(['sudo', '-n', 'wg', 'show', $interface, 'dump']);
        } catch (\Throwable $e) {
            $result['errors'][] = 'Could not run the "wg" binary: '.$e->getMessage();

            return $result;
        }

        if (! $show->successful()) {
            $result['errors'][] = "Could not read WireGuard interface \"{$interface}\": ".trim($show->errorOutput() ?: $show->output());

            return $result;
        }

        $result['binary_available'] = true;
        $result['current'] = $this->parseDump($show->output());

        foreach ($result['desired'] as $publicKey => $allowedIp) {
            $existingAllowedIp = $result['current'][$publicKey] ?? null;

            if ($existingAllowedIp === $allowedIp) {
                continue;
            }

            $bucket = $existingAllowedIp === null ? 'added' : 'updated';

            if ($dryRun) {
                $result[$bucket][$publicKey] = $allowedIp;

                continue;
            }

            try {
                $set = Process::input($publicKey."\n".$allowedIp."\n")
                    ->run(['sudo', '-n', (string) config('services.wireguard.set_peer_script')]);
            } catch (\Throwable $e) {
                $result['errors'][] = "Failed to set peer {$publicKey}: ".$e->getMessage();

                continue;
            }

            if (! $set->successful()) {
                $result['errors'][] = "Failed to set peer {$publicKey}: ".trim($set->errorOutput() ?: $set->output());

                continue;
            }

            $result[$bucket][$publicKey] = $allowedIp;
        }

        if (! $dryRun && (count($result['added']) > 0 || count($result['updated']) > 0)) {
            try {
                Process::run(['sudo', '-n', 'wg-quick', 'save', $interface]);
            } catch (\Throwable $e) {
                $result['errors'][] = 'Peers were applied live but "wg-quick save" failed, so they may not survive a reboot: '.$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @return array<string,string> WireGuard public key => allowed internal IP
     */
    public function desiredPeers(): array
    {
        return Router::query()
            ->whereNotNull('wireguard_public_key')
            ->pluck('wireguard_internal_ip', 'wireguard_public_key')
            ->all();
    }

    /**
     * Parse `wg show <interface> dump` output. The first line describes the
     * interface itself (private-key, public-key, listen-port, fwmark); every
     * line after that is one peer (public-key, preshared-key, endpoint,
     * allowed-ips, latest-handshake, transfer-rx, transfer-tx, keepalive).
     *
     * @return array<string,string> WireGuard public key => allowed IP
     */
    private function parseDump(string $output): array
    {
        $peers = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $index => $line) {
            if ($index === 0 || trim($line) === '') {
                continue;
            }

            $columns = preg_split('/\s+/', trim($line));
            $publicKey = $columns[0] ?? null;
            $allowedIps = $columns[3] ?? '';
            $ip = (string) str($allowedIps)->before('/');

            if ($publicKey && $ip !== '' && $ip !== '(none)') {
                $peers[$publicKey] = $ip;
            }
        }

        return $peers;
    }
}
