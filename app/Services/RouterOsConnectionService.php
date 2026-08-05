<?php

namespace App\Services;

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class RouterOsConnectionService
{
    /**
     * Short timeouts so a "Test Connection" click or a monitoring page load
     * never hangs the web request on an offline router -- fail fast instead.
     */
    public function client(Router $router, int $timeoutSeconds = 5): Client
    {
        return new Client(new Config([
            'host' => $router->wireguard_internal_ip,
            'user' => (string) $router->api_username,
            'pass' => (string) $router->api_password,
            'port' => (int) ($router->api_port ?: Router::API_PORT),
            'timeout' => $timeoutSeconds,
            'socket_timeout' => $timeoutSeconds,
            'attempts' => 1,
        ]));
    }

    public function isConfigured(Router $router): bool
    {
        return filled($router->api_username) && filled($router->api_password);
    }

    /**
     * @return array{success: bool, identity?: string, error?: string}
     */
    public function testConnection(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'error' => 'No RouterOS API credentials generated for this router yet. Save the router again to generate them, then re-run the script on the physical router.',
            ];
        }

        try {
            $identity = $this->client($router)
                ->query(new Query('/system/identity/print'))
                ->read();

            return [
                'success' => true,
                'identity' => $identity[0]['name'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * One instantaneous traffic sample for an interface, using RouterOS's
     * `once` flag so the command returns immediately instead of streaming.
     *
     * @return array{success: bool, rx_bits_per_second?: int, tx_bits_per_second?: int, error?: string}
     */
    public function liveTrafficSample(Router $router, string $interface = 'wg-saas'): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        try {
            $response = $this->client($router)
                ->query(
                    (new Query('/interface/monitor-traffic'))
                        ->equal('interface', $interface)
                        ->equal('once', '')
                )
                ->read();

            return [
                'success' => true,
                'rx_bits_per_second' => (int) ($response[0]['rx-bits-per-second'] ?? 0),
                'tx_bits_per_second' => (int) ($response[0]['tx-bits-per-second'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * A batch of nearby Wi-Fi networks. `/interface/wireless/scan` (and its
     * wifiwave2 equivalent `/interface/wifi/scan`) normally streams results
     * until cancelled; this reads a limited batch via the `count` option and
     * closes the connection, which should implicitly stop the scan on the
     * router side. Verify this actually stops cleanly on your RouterOS
     * version/hardware -- an interrupted scan command has historically been
     * a source of stuck API sessions on some RouterOS releases.
     *
     * @return array{success: bool, networks?: list<array{ssid: ?string, frequency: ?string, signal: ?string}>, error?: string}
     */
    public function scanWifi(Router $router, string $interface, bool $legacyWireless = false): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        $path = $legacyWireless ? '/interface/wireless/scan' : '/interface/wifi/scan';

        try {
            $response = $this->client($router, 8)
                ->query((new Query($path))->equal('interface', $interface))
                ->read(true, ['count' => 20]);

            $networks = collect($response)
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row): array => [
                    'ssid' => $row['ssid'] ?? null,
                    'frequency' => $row['frequency'] ?? null,
                    'signal' => $row['signal-strength'] ?? $row['rssi'] ?? null,
                ])
                ->filter(fn (array $network): bool => filled($network['ssid']))
                ->values()
                ->all();

            return ['success' => true, 'networks' => $networks];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * CPU/RAM/disk/uptime snapshot from `/system/resource/print`.
     *
     * @return array{success: bool, cpu_percent?: int, ram_used_bytes?: int, ram_total_bytes?: int, disk_used_bytes?: int, disk_total_bytes?: int, uptime_seconds?: int, board_name?: ?string, version?: ?string, error?: string}
     */
    public function systemResource(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        try {
            $response = $this->client($router)->query(new Query('/system/resource/print'))->read();
            $row = $response[0] ?? [];

            $totalMemory = (int) ($row['total-memory'] ?? 0);
            $freeMemory = (int) ($row['free-memory'] ?? 0);
            $totalHdd = (int) ($row['total-hdd-space'] ?? 0);
            $freeHdd = (int) ($row['free-hdd-space'] ?? 0);

            return [
                'success' => true,
                'cpu_percent' => (int) ($row['cpu-load'] ?? 0),
                'ram_used_bytes' => max(0, $totalMemory - $freeMemory),
                'ram_total_bytes' => $totalMemory,
                'disk_used_bytes' => max(0, $totalHdd - $freeHdd),
                'disk_total_bytes' => $totalHdd,
                'uptime_seconds' => self::parseRouterOsUptime((string) ($row['uptime'] ?? '')),
                'board_name' => $row['board-name'] ?? null,
                'version' => $row['version'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Raw hardware health fields from `/system/health/print` -- voltage,
     * temperature, fan speeds, PSU state, etc. What's actually present
     * varies a lot by hardware (many small routers report nothing useful
     * here), so this returns whatever RouterOS sends back unmodified
     * rather than assuming specific fields exist.
     *
     * @return array{success: bool, fields?: array<string,mixed>, error?: string}
     */
    public function systemHealth(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        try {
            $response = $this->client($router)->query(new Query('/system/health/print'))->read();

            // Older RouterOS returns one row of key=>value pairs; newer
            // versions return one row PER sensor with name/value/type keys.
            $isPerSensorFormat = collect($response)->every(
                fn ($row) => is_array($row) && array_key_exists('name', $row) && array_key_exists('value', $row)
            );

            $fields = $isPerSensorFormat
                ? collect($response)->mapWithKeys(fn (array $row) => [$row['name'] => $row['value']])->all()
                : (array) ($response[0] ?? []);

            unset($fields['.id']);

            return ['success' => true, 'fields' => $fields];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Parses RouterOS's compact uptime string (e.g. "4w2d3h4m5s") into
     * total seconds.
     */
    public static function parseRouterOsUptime(string $uptime): int
    {
        if (! preg_match_all('/(\d+)(w|d|h|m|s)/', $uptime, $matches, PREG_SET_ORDER)) {
            return 0;
        }

        $unitSeconds = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;

        foreach ($matches as [, $value, $unit]) {
            $total += ((int) $value) * $unitSeconds[$unit];
        }

        return $total;
    }
}
