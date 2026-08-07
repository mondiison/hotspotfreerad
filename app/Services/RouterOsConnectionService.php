<?php

namespace App\Services;

use App\Models\Router;
use App\Support\PaymentGatewayCatalog;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class RouterOsConnectionService
{
    public function __construct(private readonly MikroTikProvisioningService $provisioning)
    {
    }

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
     * Every interface RouterOS knows about, for populating a picker instead
     * of making an admin already know (and type out by hand) an interface
     * name like "wifi-mgmt" before Live Bandwidth can show anything.
     *
     * @return array{success: bool, interfaces?: list<array{name: string, type: ?string, running: bool, disabled: bool}>, error?: string}
     */
    public function listInterfaces(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        try {
            $response = $this->client($router)->query(new Query('/interface/print'))->read();

            $interfaces = collect($response)
                ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
                ->map(fn (array $row): array => [
                    'name' => (string) $row['name'],
                    'type' => $row['type'] ?? null,
                    'running' => filled($row['running'] ?? null) && $row['running'] !== 'false',
                    'disabled' => filled($row['disabled'] ?? null) && $row['disabled'] !== 'false',
                ])
                ->sortBy('name')
                ->values()
                ->all();

            return ['success' => true, 'interfaces' => $interfaces];
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
     * A read-only RouterOS "terminal" -- accepts a CLI-style `print` command
     * (e.g. "/interface print", "/ip hotspot active print where server=hotspot1")
     * and runs it as a live API query. Only `print` is accepted; this is
     * belt-and-braces on top of the API user's own read-only policy
     * (`policy=read,api,!write,...`), which already rejects any write
     * command RouterOS-side regardless of what's typed here.
     *
     * @return array{success: bool, path?: string, rows?: list<array<string,mixed>>, error?: string}
     */
    public function runReadOnlyCommand(Router $router, string $command): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        $parsed = self::parseReadOnlyCommand($command);

        if ($parsed === null) {
            return [
                'success' => false,
                'error' => 'Only read-only "print" commands are supported here, for example "/interface print" or "/ip hotspot active print where server=hotspot1".',
            ];
        }

        try {
            $query = new Query(self::readOnlyQueryPath($parsed['path']));

            foreach ($parsed['filters'] as $key => $value) {
                $query->where($key, $value);
            }

            $response = $this->client($router, 8)->query($query)->read();

            $rows = collect($response)->filter(fn ($row) => is_array($row))->values()->all();

            return ['success' => true, 'path' => $parsed['path'], 'rows' => $rows];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Parses a CLI-style RouterOS command into an API path plus simple
     * equality filters. Returns null for anything that isn't a plain
     * `print` command (no `add`/`set`/`remove`/`monitor`/etc.), so callers
     * can reject it before it ever reaches the router.
     *
     * @return array{path: string, filters: array<string,string>}|null
     */
    public static function parseReadOnlyCommand(string $command): ?array
    {
        $command = trim($command);

        if ($command === '' || ! preg_match_all('/[^\s"]+="[^"]*"|"[^"]*"|\S+/', $command, $matches)) {
            return null;
        }

        $tokens = $matches[0];
        $lowerTokens = array_map('strtolower', $tokens);

        $disallowed = ['add', 'set', 'remove', 'enable', 'disable', 'reset', 'export', 'import', 'reboot', 'shutdown', 'monitor', 'scan', 'flush', 'reset-configuration'];
        if (array_intersect($lowerTokens, $disallowed) !== []) {
            return null;
        }

        $printIndex = array_search('print', $lowerTokens, true);
        if ($printIndex === false || $printIndex === 0) {
            return null;
        }

        $pathSegments = array_filter(
            array_map(fn (string $token): string => trim($token, '/'), array_slice($tokens, 0, $printIndex)),
            fn (string $segment): bool => $segment !== ''
        );

        if ($pathSegments === []) {
            return null;
        }

        $path = '/'.implode('/', $pathSegments);
        $filters = [];

        $whereIndex = array_search('where', array_slice($lowerTokens, $printIndex + 1), true);
        if ($whereIndex !== false) {
            foreach (array_slice($tokens, $printIndex + 1 + $whereIndex + 1) as $clause) {
                if (! str_contains($clause, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $clause, 2);
                $key = trim($key);

                if ($key === '' || ! preg_match('/^[a-zA-Z0-9\-]+$/', $key)) {
                    return null;
                }

                $value = trim($value);
                if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                    $value = substr($value, 1, -1);
                }

                $filters[$key] = $value;
            }
        }

        return ['path' => $path, 'filters' => $filters];
    }

    /**
     * `parseReadOnlyCommand()` returns the bare menu path (e.g. "/system/resource")
     * for display purposes -- the actual RouterOS API command needs the trailing
     * verb, since "/system/resource" alone isn't executable ("no such command").
     */
    public static function readOnlyQueryPath(string $menuPath): string
    {
        return rtrim($menuPath, '/').'/print';
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
     * Pushes the hotspot/RADIUS side of `MikroTikProvisioningService::generateScript()`
     * live over the API, once a router already has WireGuard + API credentials
     * from the bootstrap script. Each step runs independently -- a failure on
     * one (e.g. the walled-garden entry already exists) doesn't stop the rest.
     * The final step points any existing hotspot server at the new profile --
     * see applyHotspotProfile() for why that's a best-effort step, not a
     * guarantee the router ends up with a working hotspot server.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function provisionHotspot(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'RouterOS API credentials', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        $steps = [
            'Add RADIUS client' => (new Query('/radius/add'))
                ->equal('address', (string) config('services.radius.server_ip'))
                ->equal('secret', (string) $router->shared_secret)
                ->equal('service', 'hotspot,ppp')
                ->equal('authentication-port', (string) config('services.radius.auth_port'))
                ->equal('accounting-port', (string) config('services.radius.acct_port'))
                ->equal('timeout', '1000ms'),
            'Add hotspot profile' => (new Query('/ip/hotspot/profile/add'))
                ->equal('name', 'saas-prof')
                ->equal('use-radius', 'yes')
                ->equal('login-by', 'http-chap,cookie,mac-cookie')
                ->equal('html-directory', 'flash/hotspot')
                ->equal('dns-name', (string) config('services.mikrotik.hotspot_dns_name'))
                ->equal('radius-accounting', 'yes'),
        ];

        $result = $this->runSteps($router, $steps);
        $walledGardenResult = $this->syncWalledGarden($router);
        $profileStep = $this->applyHotspotProfile($router);

        $result['steps'] = array_merge($result['steps'], $walledGardenResult['steps']);
        $result['steps'][] = $profileStep;
        $result['success'] = $result['success'] && $walledGardenResult['success'] && $profileStep['success'];

        return $result;
    }

    /**
     * Pushes only the walled-garden allow-list live: the portal host, the shop's
     * *currently active* payment gateway's hosted-checkout domain(s), and Cloudflare.
     * For HTTPS destinations RouterOS can't inject the captive portal's login
     * redirect (it can't rewrite an encrypted response), so a host that isn't
     * allow-listed gets its connection reset outright rather than redirected --
     * this is what makes checkout unreachable after switching gateways until the
     * new gateway's host is added. Safe and cheap to re-run any time the shop's
     * gateway changes (each step is independently idempotent via runSteps()), and
     * is the piece `provisionHotspot()` reuses rather than duplicating.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function syncWalledGarden(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'RouterOS API credentials', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        $portalUrl = $this->provisioning->portalUrl();
        $portalHost = parse_url($portalUrl, PHP_URL_HOST) ?: config('services.mikrotik.hotspot_dns_name');

        $steps = [
            'Add walled-garden entry (portal)' => (new Query('/ip/hotspot/walled-garden/add'))
                ->equal('dst-host', (string) $portalHost)
                ->equal('action', 'allow'),
        ];

        foreach (PaymentGatewayCatalog::walledGardenHosts($router->shop?->paymentGateway()) as $host) {
            $steps['Add walled-garden entry ('.$host.')'] = (new Query('/ip/hotspot/walled-garden/add'))
                ->equal('dst-host', $host)
                ->equal('action', 'allow');
        }

        $steps['Add walled-garden entry (*.cloudflare.com)'] = (new Query('/ip/hotspot/walled-garden/add'))
            ->equal('dst-host', '*.cloudflare.com')
            ->equal('action', 'allow');

        return $this->runSteps($router, $steps);
    }

    /**
     * Pushes the PPPoE/RADIUS side of `MikroTikProvisioningService::generatePppoeScript()`
     * live over the API. See provisionHotspot() for the per-step failure model.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function provisionPppoe(Router $router, string $pppoeInterface = 'bridge1'): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'RouterOS API credentials', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        $steps = [
            'Add RADIUS client' => (new Query('/radius/add'))
                ->equal('address', (string) config('services.radius.server_ip'))
                ->equal('secret', (string) $router->shared_secret)
                ->equal('service', 'ppp')
                ->equal('authentication-port', (string) config('services.radius.auth_port'))
                ->equal('accounting-port', (string) config('services.radius.acct_port'))
                ->equal('timeout', '1000ms'),
            'Enable RADIUS for PPP' => (new Query('/ppp/aaa/set'))
                ->equal('use-radius', 'yes')
                ->equal('accounting', 'yes')
                ->equal('interim-update', '5m'),
            'Add PPPoE profile' => (new Query('/ppp/profile/add'))
                ->equal('name', 'mms-pppoe-profile')
                ->equal('only-one', 'yes')
                ->equal('change-tcp-mss', 'yes'),
            'Add PPPoE server' => (new Query('/interface/pppoe-server/server/add'))
                ->equal('interface', $pppoeInterface)
                ->equal('service-name', 'mms-radius')
                ->equal('default-profile', 'mms-pppoe-profile')
                ->equal('authentication', 'pap,chap,mschap1,mschap2')
                ->equal('disabled', 'no'),
        ];

        return $this->runSteps($router, $steps);
    }

    /**
     * @param  array<string, Query>  $steps
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    private function runSteps(Router $router, array $steps): array
    {
        try {
            $client = $this->client($router, 8);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'steps' => collect($steps)->keys()->map(
                    fn (string $label): array => ['label' => $label, 'success' => false, 'error' => $e->getMessage()]
                )->all(),
            ];
        }

        $results = [];
        $allSucceeded = true;

        foreach ($steps as $label => $query) {
            try {
                $client->query($query)->read();
                $results[] = ['label' => $label, 'success' => true, 'error' => null];
            } catch (\Throwable $e) {
                $allSucceeded = false;
                $results[] = ['label' => $label, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => $allSucceeded, 'steps' => $results];
    }

    /**
     * Points any existing hotspot server(s) at the RADIUS-integrated profile
     * `provisionHotspot()` creates. We deliberately never CREATE a hotspot
     * server ourselves here -- its interface/address-pool are router-specific
     * and normally come from MikroTik's own `/ip hotspot setup` wizard (or a
     * router configured before this feature existed). If no hotspot server
     * exists yet, this is a no-op reported as informational rather than a
     * failure, since there's nothing broken -- just a manual step still
     * needed on the router.
     *
     * @return array{label: string, success: bool, error: ?string}
     */
    private function applyHotspotProfile(Router $router, string $profile = 'saas-prof'): array
    {
        $label = 'Point hotspot server at "'.$profile.'"';

        try {
            $client = $this->client($router, 8);
            $hotspots = collect($client->query(new Query('/ip/hotspot/print'))->read())
                ->filter(fn ($row) => is_array($row) && filled($row['.id'] ?? null));

            if ($hotspots->isEmpty()) {
                return [
                    'label' => $label,
                    'success' => true,
                    'error' => 'No hotspot server found on this router yet. Run "/ip hotspot setup" (or the MikroTik hotspot wizard) on the router first, then re-run this step.',
                ];
            }

            foreach ($hotspots as $hotspot) {
                $client->query((new Query('/ip/hotspot/set'))->equal('numbers', $hotspot['.id'])->equal('profile', $profile))->read();
            }

            return ['label' => $label, 'success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['label' => $label, 'success' => false, 'error' => $e->getMessage()];
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
