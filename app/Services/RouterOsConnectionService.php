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
     *
     * For a router with a ZeroTier fallback configured (`tunnel_mode`), this
     * tries each candidate host in order and returns the first one that
     * actually connects -- `evilfreelancer/routeros-api-php`'s Client
     * connects synchronously in its constructor (confirmed), so a failed
     * attempt throws immediately and this can just try the next host. Every
     * caller in this file already wraps its whole client()+query() sequence
     * in one try/catch, so this fallback needed zero changes anywhere else.
     * Worst case for a fully-unreachable dual-mode router, this roughly
     * doubles the wait before reporting failure -- accepted deliberately
     * over splitting the timeout budget per host, which would risk a false
     * "offline" verdict on a slow-but-working link.
     */
    public function client(Router $router, int $timeoutSeconds = 5): Client
    {
        $lastException = null;

        foreach (self::candidateHosts($router) as $host) {
            try {
                return new Client(new Config([
                    'host' => $host,
                    'user' => (string) $router->api_username,
                    'pass' => (string) $router->api_password,
                    'port' => (int) ($router->api_port ?: Router::API_PORT),
                    'timeout' => $timeoutSeconds,
                    'socket_timeout' => $timeoutSeconds,
                    'attempts' => 1,
                ]));
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('No tunnel host configured for this router.');
    }

    /**
     * Which host(s) to try reaching this router on, in order, based on its
     * `tunnel_mode`. Pure branch, pulled out so it's unit testable without a
     * live connection -- matches this file's existing static-helper pattern
     * (mapLeaseRows(), missingWalledGardenEntries()).
     *
     * @return list<string>
     */
    public static function candidateHosts(Router $router): array
    {
        return match ($router->tunnel_mode) {
            'zerotier' => array_values(array_filter([$router->zerotier_ip])),
            'wireguard_zerotier' => array_values(array_filter([$router->wireguard_internal_ip, $router->zerotier_ip])),
            default => array_values(array_filter([$router->wireguard_internal_ip])),
        };
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
     * DHCP leases for a network, optionally filtered to one DHCP server
     * (e.g. "dhcp-mgmt"). Deliberately DHCP-based rather than reading the
     * wireless/wifi registration-table -- a registration-table query would
     * only ever show clients associated to the MikroTik's own radio, which
     * is useless once a network's AP is an external device (e.g. a Ruijie
     * AP bridged into the same VLAN) that never associates to the MikroTik
     * radio at all. DHCP leases are recorded by the router regardless of
     * which physical AP the client came through, as long as it's bridged
     * into the router-managed network -- see the fixed dhcp-mgmt/dhcp-staff/
     * dhcp-pos/dhcp-hotspot server names in MikroTikProvisioningService.
     *
     * @return array{success: bool, leases?: list<array{mac_address: string, ip_address: ?string, hostname: ?string, status: ?string, last_seen: ?string, server: ?string}>, error?: string}
     */
    public function dhcpLeases(Router $router, ?string $server = null): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        try {
            $query = new Query('/ip/dhcp-server/lease/print');

            if ($server !== null) {
                $query->where('server', $server);
            }

            $response = $this->client($router)->query($query)->read();

            return ['success' => true, 'leases' => self::mapLeaseRows($response, $server)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pure row-mapping logic pulled out of dhcpLeases() so it can be unit
     * tested without a live RouterOS connection.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array{mac_address: string, ip_address: ?string, hostname: ?string, status: ?string, last_seen: ?string, server: ?string}>
     */
    public static function mapLeaseRows(array $rows, ?string $server = null): array
    {
        return collect($rows)
            ->filter(fn ($row) => is_array($row) && filled($row['mac-address'] ?? null))
            ->map(fn (array $row): array => [
                'mac_address' => (string) $row['mac-address'],
                'ip_address' => $row['active-address'] ?? $row['address'] ?? null,
                'hostname' => $row['host-name'] ?? null,
                'status' => $row['status'] ?? null,
                'last_seen' => $row['last-seen'] ?? null,
                'server' => $row['server'] ?? $server,
            ])
            ->values()
            ->all();
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

        $apiRestrictionResult = $this->syncApiServiceRestriction($router);
        $radiusResult = $this->syncRadiusClients($router, 'hotspot,ppp');
        $zeroTierResult = $this->syncZeroTierNetworkMembership($router);

        $steps = [
            'Add hotspot profile' => (new Query('/ip/hotspot/profile/add'))
                ->equal('name', 'saas-prof')
                ->equal('use-radius', 'yes')
                ->equal('login-by', 'http-chap,cookie,mac-cookie')
                ->equal('html-directory', 'flash/hotspot')
                ->equal('dns-name', (string) config('services.mikrotik.hotspot_dns_name'))
                ->equal('radius-accounting', 'yes'),
        ];

        $result = $this->runSteps($router, $steps);
        $result['steps'] = array_merge($apiRestrictionResult['steps'], $radiusResult['steps'], $zeroTierResult['steps'], $result['steps']);
        $result['success'] = $apiRestrictionResult['success'] && $radiusResult['success'] && $zeroTierResult['success'] && $result['success'];

        $walledGardenResult = $this->syncWalledGarden($router);
        $loginPageResult = $this->pushHotspotLoginPage($router);
        $profileStep = $this->applyHotspotProfile($router);

        $result['steps'] = array_merge($result['steps'], $walledGardenResult['steps'], $loginPageResult['steps']);
        $result['steps'][] = $profileStep;
        $result['success'] = $result['success'] && $walledGardenResult['success'] && $loginPageResult['success'] && $profileStep['success'];

        return $result;
    }

    /**
     * The directory this app's own hotspot profile (`saas-prof`, created by
     * `provisionHotspot()`) uses -- correct as a default for a router being
     * freshly onboarded through this app, but NOT guaranteed for a router
     * that already had a hotspot server set up another way (MikroTik's own
     * `/ip hotspot setup` wizard, a differently-named profile, or an older
     * RouterOS version that doesn't prefix paths with "flash/" at all --
     * confirmed live: the same file shows as "hotspot/login.html" on some
     * routers, not "flash/hotspot/login.html"). listHotspotDirectories()
     * lets an admin see what's actually on THIS router and override this
     * default via pushHotspotLoginPage()'s $directory parameter instead of
     * guessing wrong and silently writing to a directory nothing serves.
     */
    public const DEFAULT_HOTSPOT_DIRECTORY = 'flash/hotspot';

    /**
     * Replaces a router's local login.html with the redirect stub from
     * MikroTikProvisioningService::hotspotLoginPageHtml(), fetched directly
     * by the router itself over `/tool fetch` rather than written through
     * the API -- RouterOS's binary API has no reliable way to write
     * arbitrary file content directly across versions, but every router
     * already has outbound reach to the portal host (it's the same host the
     * walled-garden entries above allow customer devices to reach), so
     * having the router pull the file itself is both simpler and more
     * reliable than trying to push bytes through the API connection.
     * Without this, a router keeps serving MikroTik's stock local hotspot
     * login form forever -- customers would "log in" against RouterOS
     * directly and never reach this app's portal, payment gateways, or
     * RADIUS provisioning at all. The API user's policy already grants
     * "test", which is what `/tool fetch` needs (see
     * MikroTikProvisioningService::apiUserProvisioningLines()).
     *
     * $directory defaults to DEFAULT_HOTSPOT_DIRECTORY (correct for this
     * app's own hotspot profile) -- pass the router's actual directory
     * (see listHotspotDirectories()) when it already has a different
     * hotspot setup, so the file lands where RouterOS is actually serving
     * login pages from rather than an unused directory.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function pushHotspotLoginPage(Router $router, ?string $directory = null): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'RouterOS API credentials', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        $steps = [
            'Push hotspot login page' => (new Query('/tool/fetch'))
                ->equal('url', $this->provisioning->loginPageUrl())
                ->equal('dst-path', self::resolveHotspotDirectory($directory).'/login.html')
                ->equal('mode', 'https')
                ->equal('check-certificate', 'no'),
        ];

        return $this->runSteps($router, $steps);
    }

    /**
     * Defaults and trims a caller-supplied directory -- pulled out of
     * pushHotspotLoginPage() so the resolution logic itself (default
     * fallback, leading/trailing slash trimming) can be unit tested without
     * a live RouterOS connection.
     */
    public static function resolveHotspotDirectory(?string $directory): string
    {
        return trim($directory ?? self::DEFAULT_HOTSPOT_DIRECTORY, '/');
    }

    /**
     * Every directory RouterOS's own file storage actually has, so an admin
     * can see the real layout of a router instead of guessing whether
     * pushHotspotLoginPage()'s default (DEFAULT_HOTSPOT_DIRECTORY) is
     * correct for THIS router -- see the caveat on that constant. `/file
     * print` already covers whatever storage the router has (just "flash"
     * on most boards); this only keeps entries RouterOS itself reports as
     * type=directory, not individual files.
     *
     * @return array{success: bool, directories?: list<string>, error?: string}
     */
    public function listHotspotDirectories(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return ['success' => false, 'error' => 'RouterOS API credentials not generated yet.'];
        }

        try {
            $response = $this->client($router)->query(new Query('/file/print'))->read();

            return ['success' => true, 'directories' => self::mapDirectoryRows($response)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pure row-mapping logic pulled out of listHotspotDirectories() so it
     * can be unit tested without a live RouterOS connection.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    public static function mapDirectoryRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row) => is_array($row) && ($row['type'] ?? null) === 'directory' && filled($row['name'] ?? null))
            ->map(fn (array $row): string => (string) $row['name'])
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Pushes only the walled-garden allow-list live: the portal host, the shop's
     * *currently active* payment gateway's hosted-checkout domain(s), and Cloudflare.
     * For HTTPS destinations RouterOS can't inject the captive portal's login
     * redirect (it can't rewrite an encrypted response), so a host that isn't
     * allow-listed gets its connection reset outright rather than redirected --
     * this is what makes checkout unreachable after switching gateways until the
     * new gateway's host is added. Safe and cheap to re-run any time the shop's
     * gateway changes -- lists the router's current walled-garden entries first
     * and only sends `/add` for hosts that aren't already there, instead of
     * unconditionally re-adding (and duplicating, since RouterOS doesn't dedupe
     * dst-host on its own) every entry on every call. Reused as-is by
     * `provisionHotspot()` rather than duplicated.
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

        $entries = ['Add walled-garden entry (portal)' => (string) $portalHost];

        foreach (PaymentGatewayCatalog::walledGardenHosts($router->shop?->paymentGateway()) as $host) {
            $entries['Add walled-garden entry ('.$host.')'] = $host;
        }

        $entries['Add walled-garden entry (*.cloudflare.com)'] = '*.cloudflare.com';

        $existingHosts = $this->existingWalledGardenHosts($router);
        $missingEntries = self::missingWalledGardenEntries($entries, $existingHosts);

        $queries = collect($missingEntries)->map(
            fn (string $host): Query => (new Query('/ip/hotspot/walled-garden/add'))->equal('dst-host', $host)->equal('action', 'allow')
        )->all();

        $added = $queries === [] ? ['success' => true, 'steps' => []] : $this->runSteps($router, $queries);
        $addedByLabel = collect($added['steps'])->keyBy('label');

        $steps = [];
        foreach ($entries as $label => $host) {
            $steps[] = array_key_exists($label, $missingEntries)
                ? $addedByLabel[$label]
                : ['label' => $label, 'success' => true, 'error' => 'Already present on the router, skipped.'];
        }

        return ['success' => $added['success'], 'steps' => $steps];
    }

    /**
     * Which of `$entries` (label => dst-host) still need a live `/add` --
     * pure filtering logic pulled out of syncWalledGarden() so it can be
     * unit tested without a live RouterOS connection.
     *
     * @param  array<string,string>  $entries
     * @param  list<string>  $existingHosts
     * @return array<string,string>
     */
    public static function missingWalledGardenEntries(array $entries, array $existingHosts): array
    {
        return array_filter($entries, fn (string $host): bool => ! in_array($host, $existingHosts, true));
    }

    /**
     * The dst-host values already on the router's walled garden, so
     * syncWalledGarden() can skip re-adding ones that are already there.
     * Falls back to an empty list -- meaning every entry gets attempted, the
     * same behavior as before this existed -- if the router can't be reached
     * to list them; a failed listing shouldn't block the sync itself.
     *
     * @return list<string>
     */
    private function existingWalledGardenHosts(Router $router): array
    {
        try {
            $rows = $this->client($router, 8)->query(new Query('/ip/hotspot/walled-garden/print'))->read();

            return collect($rows)
                ->filter(fn ($row) => is_array($row) && filled($row['dst-host'] ?? null))
                ->map(fn (array $row): string => (string) $row['dst-host'])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
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

        $apiRestrictionResult = $this->syncApiServiceRestriction($router);
        $radiusResult = $this->syncRadiusClients($router, 'ppp');
        $zeroTierResult = $this->syncZeroTierNetworkMembership($router);

        $steps = [
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

        $result = $this->runSteps($router, $steps);
        $result['steps'] = array_merge($apiRestrictionResult['steps'], $radiusResult['steps'], $zeroTierResult['steps'], $result['steps']);
        $result['success'] = $apiRestrictionResult['success'] && $radiusResult['success'] && $zeroTierResult['success'] && $result['success'];

        return $result;
    }

    /**
     * The RouterOS ZeroTier instance name this app always uses -- matches
     * the literal "zt1" MikroTikProvisioningService::zeroTierLines() emits
     * in the generated script (that method assumes an instance named "zt1"
     * already exists by default on the router, which held true on the real
     * hardware this was confirmed against 2026-08-19 -- RouterOS's zerotier
     * package ships with one pre-created default instance under that name).
     */
    private const ZEROTIER_INSTANCE_NAME = 'zt1';

    /**
     * Reconciles this router's `/radius` client entries against what its
     * `tunnel_mode` currently needs -- the live-API equivalent of
     * MikroTikProvisioningService::radiusClientLines(), except idempotent:
     * changing a router's tunnel_mode after it's already been provisioned
     * (e.g. WireGuard-only -> wireguard_zerotier) used to need the WHOLE
     * script re-pasted by hand, since the old version of this method always
     * blindly ran `/radius/add` regardless of what already existed --
     * confirmed live 2026-08-19: switching tunnel_mode and re-provisioning
     * would have added a genuine duplicate WireGuard entry alongside the new
     * ZeroTier one. There is no `priority` property on RouterOS's `/radius`
     * menu at all (confirmed live the same day: RouterOS rejected it outright
     * with "unknown parameter priority", an assumption -- also baked into
     * radiusClientLines() until this fix -- that had never actually been
     * exercised against a real router before that report) -- failover order
     * is determined purely by list position, tried top to bottom, so
     * "WireGuard before ZeroTier" falls out for free as long as WireGuard's
     * entry (added first, whether by the original bootstrap script or an
     * earlier run of this method) is never removed and blindly re-added.
     * Lists what's actually on the router first (existingRadiusClients()),
     * diffs it against the desired set (planRadiusClientChanges()), and only
     * adds/removes what's actually wrong -- the same "list first, only touch
     * what's missing" shape as syncWalledGarden(). Removal is scoped to
     * entries whose address matches one of this app's own two known
     * endpoints (services.radius.server_ip / services.zerotier.pi_ip) --
     * mirroring the `proto` tag safety idea in WireGuardRouteSyncService --
     * so a RADIUS client an admin added by hand for something else entirely
     * is never touched, regardless of what tunnel_mode says.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function syncRadiusClients(Router $router, string $service): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'RADIUS client sync', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        $desired = self::desiredRadiusClients($router, $service);
        $existing = $this->existingRadiusClients($router);
        $managedAddresses = array_values(array_filter([
            (string) config('services.radius.server_ip'),
            (string) config('services.zerotier.pi_ip'),
        ]));

        $plan = self::planRadiusClientChanges($desired, $existing, $managedAddresses);

        $steps = [];

        foreach ($plan['add'] as $label => $fields) {
            $query = new Query('/radius/add');
            foreach ($fields as $key => $value) {
                $query->equal($key, (string) $value);
            }
            $steps[$label] = $query;
        }

        foreach ($plan['remove'] as $label => $id) {
            $steps[$label] = (new Query('/radius/remove'))->equal('numbers', $id);
        }

        $result = $steps === [] ? ['success' => true, 'steps' => []] : $this->runSteps($router, $steps);

        foreach ($plan['unchanged'] as $label) {
            $result['steps'][] = ['label' => $label, 'success' => true, 'error' => 'Already correct on the router, skipped.'];
        }

        return $result;
    }

    /**
     * What this router's RADIUS clients SHOULD be, keyed by tunnel
     * ('wireguard'/'zerotier') -- pure, pulled out of syncRadiusClients() so
     * it's unit testable without a live connection. Iteration/insertion order
     * matters here: WireGuard is always listed before ZeroTier, so a fresh
     * dual-tunnel router provisioned via this method alone still ends up
     * with WireGuard first in RouterOS's own `/radius print` list -- the
     * only thing that actually determines failover order (see
     * syncRadiusClients()'s docblock).
     *
     * @return array<string, array{address: string, secret: string, service: string, authentication-port: string, accounting-port: string, timeout: string}>
     */
    public static function desiredRadiusClients(Router $router, string $service): array
    {
        $includesWireguard = in_array($router->tunnel_mode, ['wireguard', 'wireguard_zerotier'], true);
        $includesZeroTier = in_array($router->tunnel_mode, ['wireguard_zerotier', 'zerotier'], true);
        $desired = [];

        if ($includesWireguard) {
            $desired['wireguard'] = [
                'address' => (string) config('services.radius.server_ip'),
                'secret' => (string) $router->shared_secret,
                'service' => $service,
                'authentication-port' => (string) config('services.radius.auth_port'),
                'accounting-port' => (string) config('services.radius.acct_port'),
                'timeout' => '1000ms',
            ];
        }

        if ($includesZeroTier) {
            $desired['zerotier'] = [
                'address' => (string) config('services.zerotier.pi_ip'),
                'secret' => (string) $router->shared_secret,
                'service' => $service,
                'authentication-port' => (string) config('services.radius.auth_port'),
                'accounting-port' => (string) config('services.radius.acct_port'),
                'timeout' => '1000ms',
            ];
        }

        return $desired;
    }

    /**
     * Diffs desired RADIUS clients against what's actually on the router --
     * pure, pulled out of syncRadiusClients() so it's unit testable without
     * a live connection. Matches an existing entry to a desired one purely
     * by `address` (RouterOS matches a NAS/client definition by the
     * request's source IP, so address is the only field that meaningfully
     * identifies "which tunnel is this entry for") -- if a match is found,
     * the entry is left alone entirely, since there's nothing meaningful
     * left to reconcile once `priority` turned out not to be a real
     * property (see syncRadiusClients()'s docblock).
     *
     * @param  array<string, array{address: string, secret: string, service: string, authentication-port: string, accounting-port: string, timeout: string}>  $desired
     * @param  list<array<string,mixed>>  $existing  raw "/radius print" rows
     * @param  list<string>  $managedAddresses  every address this app could ever desire (WireGuard + ZeroTier Pi IPs) -- an existing entry is only ever a remove candidate if its address is in this list, so a RADIUS client an admin added by hand for something unrelated is never touched
     * @return array{add: array<string, array>, remove: array<string, string>, unchanged: list<string>}
     */
    public static function planRadiusClientChanges(array $desired, array $existing, array $managedAddresses): array
    {
        $names = ['wireguard' => 'WireGuard', 'zerotier' => 'ZeroTier'];
        $existingAddresses = collect($existing)->pluck('address')->map(fn ($address) => (string) $address)->all();

        $add = [];
        $unchanged = [];

        foreach ($desired as $key => $fields) {
            $name = $names[$key] ?? ucfirst($key);

            if (in_array($fields['address'], $existingAddresses, true)) {
                $unchanged[] = 'RADIUS client ('.$name.')';

                continue;
            }

            $add['Add RADIUS client ('.$name.')'] = $fields;
        }

        $desiredAddresses = collect($desired)->pluck('address')->all();
        $remove = [];

        foreach ($existing as $row) {
            $address = (string) ($row['address'] ?? '');

            if ($address === '' || in_array($address, $desiredAddresses, true) || ! in_array($address, $managedAddresses, true)) {
                continue;
            }

            $remove['Remove stale RADIUS client ('.$address.')'] = (string) ($row['.id'] ?? '');
        }

        return ['add' => $add, 'remove' => $remove, 'unchanged' => $unchanged];
    }

    /**
     * The RADIUS clients actually on this router right now. Falls back to an
     * empty list -- meaning every desired entry gets attempted as an "add",
     * the same behavior as before syncRadiusClients() existed -- if the
     * router can't be reached to list them; a failed listing shouldn't block
     * the sync itself (mirrors existingWalledGardenHosts()).
     *
     * @return list<array<string,mixed>>
     */
    private function existingRadiusClients(Router $router): array
    {
        try {
            $rows = $this->client($router, 8)->query(new Query('/radius/print'))->read();

            return collect($rows)->filter(fn ($row) => is_array($row) && filled($row['address'] ?? null))->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Keeps this router's RouterOS API service (`/ip service` "api" entry)
     * trusting the right tunnel subnet(s) for its current tunnel_mode --
     * this restriction is otherwise only ever set once, at script time
     * (MikroTikProvisioningService::apiServiceAddressRestriction(), reused
     * here directly rather than duplicated), so a router that had ZeroTier
     * added to it *after* its script was last pasted keeps trusting only
     * the WireGuard subnet forever. Confirmed live 2026-08-19: a router
     * moved to a genuinely remote site (no longer reachable over WireGuard
     * at all, exactly the scenario ZeroTier exists for) still failed every
     * API call over its now-working ZeroTier tunnel with a timeout --
     * RouterOS silently drops a connection from an address outside this
     * list rather than refusing it outright, which is indistinguishable
     * from the tunnel itself being down without checking this specifically.
     * As long as *either* tunnel is currently trusted, running this over
     * that working path pushes a restriction covering both -- so a router
     * that still has working WireGuard right now gets ZeroTier pre-trusted
     * before it's ever needed, and a router already reachable only via
     * ZeroTier gets WireGuard's subnet added back for whenever it returns.
     * A router trusted on *neither* currently-working path can't be reached
     * to fix this at all -- see docs/wireguard-server-setup.md /
     * docs/zerotier-fallback-setup.md for the one-time manual console fix
     * that's the only way out of that specific corner.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function syncApiServiceRestriction(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'API service address restriction', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        $desired = $this->provisioning->apiServiceAddressRestriction($router);

        try {
            $client = $this->client($router, 8);

            $apiServiceRow = collect($client->query(new Query('/ip/service/print'))->read())
                ->first(fn ($row) => is_array($row) && ($row['name'] ?? null) === 'api');
        } catch (\Throwable $e) {
            return ['success' => false, 'steps' => [['label' => 'API service address restriction', 'success' => false, 'error' => $e->getMessage()]]];
        }

        if ($apiServiceRow === null) {
            return [
                'success' => false,
                'steps' => [['label' => 'API service address restriction', 'success' => false, 'error' => 'No "api" entry found under /ip/service -- this should always exist on a router with the RouterOS API enabled.']],
            ];
        }

        $current = (string) ($apiServiceRow['address'] ?? '');

        if (self::normalizeAddressList($current) === self::normalizeAddressList($desired)) {
            return ['success' => true, 'steps' => [['label' => 'API service address restriction', 'success' => true, 'error' => 'Already correct on the router, skipped.']]];
        }

        $steps = [
            'Update API service address restriction' => (new Query('/ip/service/set'))
                ->equal('numbers', (string) ($apiServiceRow['.id'] ?? ''))
                ->equal('address', $desired),
        ];

        return $this->runSteps($router, $steps);
    }

    /**
     * A comma-separated CIDR list, order-independent and whitespace-trimmed
     * -- pure, pulled out of syncApiServiceRestriction() so "is the router
     * already correct" can be unit tested without a live connection and
     * without false "differs" results just because RouterOS or this app
     * happened to list the same two subnets in a different order.
     *
     * @return list<string>
     */
    public static function normalizeAddressList(string $addresses): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $addresses))));
        sort($list);

        return $list;
    }

    /**
     * Makes sure this router has actually joined the ZeroTier network its
     * tunnel_mode requires -- a no-op (zero steps, no connection attempt)
     * for a router that doesn't use ZeroTier at all. Enabling the ZeroTier
     * service and joining a specific network are two separate RouterOS
     * steps (confirmed live 2026-08-19: a router can have ZeroTier enabled
     * with a real node identity yet never have run "/zerotier interface add"
     * at all, leaving it authorized on the controller but never actually
     * connected) -- this mirrors MikroTikProvisioningService::zeroTierLines()
     * (`/zerotier enable zt1` + `/zerotier interface add network=... instance=zt1`)
     * but checks the router's actual current state first via
     * existingZeroTierState(), so re-running this after the network is
     * already joined is a safe no-op rather than a second, likely-rejected
     * "add" attempt.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function syncZeroTierNetworkMembership(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'ZeroTier network membership', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        if (! in_array($router->tunnel_mode, ['wireguard_zerotier', 'zerotier'], true)) {
            return ['success' => true, 'steps' => []];
        }

        $instance = self::ZEROTIER_INSTANCE_NAME;

        try {
            $state = $this->existingZeroTierState($router, $instance);
        } catch (\Throwable $e) {
            return ['success' => false, 'steps' => [['label' => 'ZeroTier network membership', 'success' => false, 'error' => $e->getMessage()]]];
        }

        if ($state['instance_id'] === null) {
            return [
                'success' => false,
                'steps' => [['label' => 'ZeroTier network membership', 'success' => false, 'error' => 'No ZeroTier instance named "'.$instance.'" found on this router -- confirm the "zerotier" package is installed (RouterOS 7.5+, ARM/ARM64 hardware only).']],
            ];
        }

        $actions = self::zeroTierActionsNeeded($state);

        if ($actions === []) {
            return ['success' => true, 'steps' => [['label' => 'ZeroTier network membership', 'success' => true, 'error' => 'Already enabled and joined, skipped.']]];
        }

        $steps = [];

        if (in_array('enable', $actions, true)) {
            $steps['Enable ZeroTier ('.$instance.')'] = (new Query('/zerotier/enable'))->equal('numbers', $state['instance_id']);
        }

        if (in_array('join', $actions, true)) {
            $steps['Join ZeroTier network'] = (new Query('/zerotier/interface/add'))
                ->equal('network', (string) config('services.zerotier.network_id'))
                ->equal('instance', $instance);
        }

        return $this->runSteps($router, $steps);
    }

    /**
     * Which of ['enable', 'join'] this router's ZeroTier instance still
     * needs -- pure, pulled out of syncZeroTierNetworkMembership() so it's
     * unit testable without a live connection.
     *
     * @param  array{instance_id: ?string, instance_disabled: bool, network_joined: bool}  $state
     * @return list<string>
     */
    public static function zeroTierActionsNeeded(array $state): array
    {
        if ($state['instance_id'] === null) {
            return [];
        }

        $actions = [];

        if ($state['instance_disabled']) {
            $actions[] = 'enable';
        }

        if (! $state['network_joined']) {
            $actions[] = 'join';
        }

        return $actions;
    }

    /**
     * @return array{instance_id: ?string, instance_disabled: bool, network_joined: bool}
     */
    private function existingZeroTierState(Router $router, string $instance): array
    {
        $client = $this->client($router, 8);

        $instanceRow = collect($client->query(new Query('/zerotier/print'))->read())
            ->first(fn ($row) => is_array($row) && ($row['name'] ?? null) === $instance);

        $networkId = (string) config('services.zerotier.network_id');

        $joined = collect($client->query(new Query('/zerotier/interface/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['network'] ?? null) === $networkId);

        return self::mapZeroTierState($instanceRow, $joined);
    }

    /**
     * Pure row-mapping logic pulled out of existingZeroTierState() so it can
     * be unit tested without a live connection. RouterOS's API frequently
     * omits a boolean property entirely when it's at its "no"/false value
     * rather than echoing it back explicitly -- confirmed live 2026-08-19: a
     * router with a genuinely enabled instance still triggered an
     * unnecessary (if harmless) "Enable" step here, traced to defaulting a
     * missing "disabled" key to 'true' instead of 'no'.
     *
     * @param  array<string,mixed>|null  $instanceRow  the "/zerotier print" row matching this app's instance name, or null if none matched
     * @return array{instance_id: ?string, instance_disabled: bool, network_joined: bool}
     */
    public static function mapZeroTierState(?array $instanceRow, bool $joined): array
    {
        return [
            'instance_id' => $instanceRow['.id'] ?? null,
            'instance_disabled' => $instanceRow !== null && ($instanceRow['disabled'] ?? 'no') === 'yes',
            'network_joined' => $joined,
        ];
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
                $raw = $client->query($query)->read(false);

                if ($trapMessage = self::extractTrapMessage($raw)) {
                    throw new \RuntimeException($trapMessage);
                }

                $results[] = ['label' => $label, 'success' => true, 'error' => null];
            } catch (\Throwable $e) {
                $allSucceeded = false;
                $results[] = ['label' => $label, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => $allSucceeded, 'steps' => $results];
    }

    /**
     * RouterOS's binary API signals a rejected command with a `!trap` block
     * (e.g. "no such command or not enough permissions to run the command")
     * followed by a normal `!done` that closes the reply -- the client
     * library (`evilfreelancer/routeros-api-php`) parses `!trap` and `!done`
     * identically and never throws, so a rejected write silently looks like
     * a success unless the raw response is inspected for `!trap` directly.
     * Pass the raw (unparsed, `read(false)`) response here -- not the
     * parsed one, which has already lost the `!trap`/`!done` marker.
     *
     * @param  list<string>  $rawResponse
     */
    public static function extractTrapMessage(array $rawResponse): ?string
    {
        if (! in_array('!trap', $rawResponse, true)) {
            return null;
        }

        foreach ($rawResponse as $line) {
            if (is_string($line) && str_starts_with($line, '=message=')) {
                return substr($line, strlen('=message='));
            }
        }

        return 'RouterOS rejected this command (insufficient permissions or invalid parameters).';
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
                $raw = $client->query((new Query('/ip/hotspot/set'))->equal('numbers', $hotspot['.id'])->equal('profile', $profile))->read(false);

                if ($trapMessage = self::extractTrapMessage($raw)) {
                    throw new \RuntimeException($trapMessage);
                }
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
