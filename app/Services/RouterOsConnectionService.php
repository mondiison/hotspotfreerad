<?php

namespace App\Services;

use App\Models\Router;
use App\Models\TrustedWifiDevice;
use App\Support\PaymentGatewayCatalog;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class RouterOsConnectionService
{
    public function __construct(private readonly MikroTikProvisioningService $provisioning) {}

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

        // Confirmed live 2026-09-22: this was a plain, unconditional /add -- the router's
        // very first successful provisionHotspot() run created "saas-prof" fine, but every
        // subsequent "Provision via API" click (or auto-provisioning retry) then failed this
        // one step forever after with "server profile with such name already exists", even
        // though nothing was actually wrong. Checks first and updates in place if it's
        // already there, matching the idempotent shape every other step here already has.
        $existingProfileId = $this->existingHotspotProfileId($router, 'saas-prof');

        // Confirmed live 2026-09-22, alongside the idempotency fix above: this also
        // hardcoded "flash/hotspot" regardless of where pushHotspotLoginPage() (below) was
        // actually told to push the file -- a router with a saved hotspot_login_directory
        // override had its login.html living in one directory while this profile still
        // pointed RouterOS's HTTP server at a different one, so nobody could ever log in.
        $htmlDirectory = filled($router->hotspot_login_directory) ? $router->hotspot_login_directory : self::DEFAULT_HOTSPOT_DIRECTORY;

        // Confirmed live 2026-09-22: this hardcoded login-by omitted "http-pap" entirely,
        // unlike both script generators (MikroTikProvisioningService::generateScript()/
        // generateFreshInfrastructureScript()), which correctly include it. http-pap is the
        // method that accepts a plain, directly-submitted username/password -- exactly what
        // hotspot.access-granted.blade.php's login form sends -- while http-chap requires a
        // router-issued challenge/response handshake the form never performs. With http-pap
        // missing, RouterOS never recognized the login submission as valid at all and just
        // re-served the static login.html page, before ever reaching a RADIUS check -- this
        // was the actual root cause of a redirect loop that survived several other genuine
        // fixes (DNS name, link-login-only capture, GET vs POST) because none of them
        // addressed this. Every router provisioned live via "Provision via API" since this
        // profile-management code was written has been missing http-pap.
        $loginBy = 'http-pap,http-chap,cookie,mac-cookie';

        $profileQuery = $existingProfileId !== null
            ? (new Query('/ip/hotspot/profile/set'))
                ->equal('numbers', $existingProfileId)
                ->equal('use-radius', 'yes')
                ->equal('login-by', $loginBy)
                ->equal('html-directory', $htmlDirectory)
                ->equal('dns-name', (string) config('services.mikrotik.hotspot_dns_name'))
                ->equal('radius-accounting', 'yes')
            : (new Query('/ip/hotspot/profile/add'))
                ->equal('name', 'saas-prof')
                ->equal('use-radius', 'yes')
                ->equal('login-by', $loginBy)
                ->equal('html-directory', $htmlDirectory)
                ->equal('dns-name', (string) config('services.mikrotik.hotspot_dns_name'))
                ->equal('radius-accounting', 'yes');

        $steps = [
            'Add hotspot profile' => $profileQuery,
        ];

        $result = $this->runSteps($router, $steps);
        $result['steps'] = array_merge($apiRestrictionResult['steps'], $radiusResult['steps'], $zeroTierResult['steps'], $result['steps']);
        $result['success'] = $apiRestrictionResult['success'] && $radiusResult['success'] && $zeroTierResult['success'] && $result['success'];

        $walledGardenResult = $this->syncWalledGarden($router);
        // $router->hotspot_login_directory is null for a router freshly onboarded through
        // this app, which is exactly when pushHotspotLoginPage()'s own default
        // (DEFAULT_HOTSPOT_DIRECTORY) is correct -- passing it through here just means a
        // router whose admin saved a different directory (via the "Hotspot Login Page"
        // section on the Live tab) keeps getting THAT directory on every future
        // "Provision via API"/auto-provisioning push too, instead of this method silently
        // reverting to the wrong default every time.
        $loginPageResult = $this->pushHotspotLoginPage($router, $router->hotspot_login_directory);
        $profileStep = $this->applyHotspotProfile($router);

        $result['steps'] = array_merge($result['steps'], $walledGardenResult['steps'], $loginPageResult['steps']);
        $result['steps'][] = $profileStep;
        $result['success'] = $result['success'] && $walledGardenResult['success'] && $loginPageResult['success'] && $profileStep['success'];

        return $result;
    }

    /**
     * Confirmed live 2026-09-23: the POS VLAN's WPA2/WPA3 SSID had a shared
     * password but no per-device enforcement at all -- any device that knew
     * the password got a DHCP lease and full internet access on the POS
     * VLAN, completely independent of whether it was registered (or paid)
     * as a PosDevice. RadiusProvisioningService::provisionPosDevice() has
     * always written a correct RADIUS MAC-auth record (radcheck username =
     * MAC, password = MAC) on register/renew, but nothing on the router
     * side ever queried it -- MikroTikProvisioningService's own POS hotspot
     * binding existed only as commented-out lines in the script generator,
     * and this live-API path had no equivalent step at all. This closes
     * that gap the same way provisionHotspot() closes it for the customer
     * hotspot: an idempotent add-or-set of a `login-by=mac use-radius=yes`
     * hotspot profile, then a hotspot server object bound to it. Unlike the
     * customer hotspot (whose interface/pool are router-specific and can
     * come from a manual `/ip hotspot setup`), the POS VLAN's `vlan-pos`/
     * `pool-pos` names are always exactly what this app's own script
     * generator creates, so this is safe to also CREATE the hotspot server
     * object itself, not just re-point an existing one -- see
     * applyPosHotspotServer() below.
     *
     * A sibling of provisionHotspot()/provisionPppoe(), not nested inside
     * either -- called from its own "POS Script" tab button
     * (RouterController::provisionPos()) and from
     * RouterAutoProvisioningService (conditionally on enable_pos, mirroring
     * how that service already calls provisionPppoe() conditionally on
     * enable_pppoe), rather than always tagging along inside
     * provisionHotspot(). Deliberately never adds its own `/radius` client
     * entry -- POS uses the "hotspot" RADIUS service, which provisionHotspot()
     * (via syncRadiusClients()) already establishes, and a second `/radius add`
     * for the same service would just be a genuine duplicate RouterOS never
     * dedupes on its own. This does mean a router that's never had
     * provisionHotspot() succeed at least once will fail RADIUS auth for POS
     * MAC-auth too, even if this method's own steps report success.
     *
     * A no-op (not a failure) for a router with POS disabled, or missing
     * `vlan-pos`/`pool-pos` (a router that has never had the fresh
     * infrastructure script applied since POS was added) -- RouterOS will
     * reject the hotspot-server add in that case, which applyPosHotspotServer()
     * surfaces as a normal failed step rather than something this method
     * needs to detect in advance.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function provisionPos(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'POS MAC-auth hotspot', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        if (! (bool) (((array) $router->provisioning_settings)['enable_pos'] ?? true)) {
            return ['success' => true, 'steps' => []];
        }

        $infraResult = $this->ensurePosInfrastructure($router);

        $existingProfileId = $this->existingHotspotProfileId($router, 'mms-pos-profile');

        // mac-auth-password is set explicitly rather than left blank --
        // confirmed live 2026-09-23 via freeradius -X that RouterOS's own
        // "defaults to the client's MAC" blank behavior doesn't actually
        // send a CHAP-hashable password matching the MAC in radcheck,
        // rejecting every MAC-auth attempt ("password is incorrect") even
        // though the username/group lookup succeeded. Matches the fixed
        // value RadiusProvisioningService::provisionPosDevice() now stores
        // as every POS device's Cleartext-Password.
        $profileQuery = $existingProfileId !== null
            ? (new Query('/ip/hotspot/profile/set'))
                ->equal('numbers', $existingProfileId)
                ->equal('use-radius', 'yes')
                ->equal('login-by', 'mac')
                ->equal('mac-auth-password', RadiusProvisioningService::POS_MAC_AUTH_PASSWORD)
                ->equal('radius-accounting', 'yes')
            : (new Query('/ip/hotspot/profile/add'))
                ->equal('name', 'mms-pos-profile')
                ->equal('use-radius', 'yes')
                ->equal('login-by', 'mac')
                ->equal('mac-auth-password', RadiusProvisioningService::POS_MAC_AUTH_PASSWORD)
                ->equal('radius-accounting', 'yes');

        $result = $this->runSteps($router, ['Add POS MAC-auth hotspot profile' => $profileQuery]);
        $result['steps'] = array_merge($infraResult['steps'], $result['steps']);
        $result['success'] = $infraResult['success'] && $result['success'];

        $serverStep = $this->applyPosHotspotServer($router);
        $result['steps'][] = $serverStep;
        $result['success'] = $result['success'] && $serverStep['success'];

        return $result;
    }

    /**
     * Confirmed live 2026-09-23 from a router (bebeji-router01) where
     * enable_pos was flipped on *after* Fresh Infrastructure Script had
     * already been applied once: since that script is explicitly not safe
     * to re-run, the router had no vlan-pos interface, pool-pos, or POS DHCP
     * server at all, and applyPosHotspotServer()'s `/ip/hotspot/add
     * interface=vlan-pos` step failed outright with RouterOS's "input does
     * not match any value of interface" -- previously only recoverable by
     * hand-deriving and pasting the missing pieces individually on the
     * router console. Closes that gap live over the API: an idempotent
     * list-then-create of the VLAN interface, its IP address, the address
     * pool, and the DHCP server/network -- the same objects
     * generatePosScript() now also creates for the paste-by-hand path (see
     * that method's own docblock). Reads the exact same defaulted settings
     * generatePosScript() uses via MikroTikProvisioningService::
     * provisioningSettings() (made public for exactly this) rather than a
     * second, narrower copy that could drift from it.
     *
     * Deliberately scoped to just these four simple, uniquely-named objects
     * -- each is a single "does an object with this name/interface already
     * exist" check, safe to run on every provisionPos() call (including the
     * unconditional one every 5-minute auto-provisioning cycle makes).
     * Bridge-vlan table entries, the virtual POS Wi-Fi SSID, and extra
     * untagged access ports are deliberately NOT auto-created here -- unlike
     * these four, they can't be reduced to a single "does this exist"
     * check without a much larger bridge-vlan reconciliation system (a
     * bridge-vlan row can already exist covering other VLANs and just need
     * POS's ID added to it, the same complexity generateFreshInfrastructureScript()'s
     * own bridge-vlan-line logic has to handle for the paste-by-hand case).
     * A router missing those still needs the Fresh Infrastructure Script
     * (or generatePosScript()'s now-more-complete output) pasted by hand for
     * that part.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    private function ensurePosInfrastructure(Router $router): array
    {
        $settings = $this->provisioning->provisioningSettings($router, (string) (((array) $router->provisioning_settings)['profile'] ?? 'starlink_plaza'));

        $posVlan = (string) $settings['pos_vlan'];
        $posGateway = (string) $settings['pos_gateway'];
        $posNetwork = (string) $settings['pos_network'];
        $posPool = (string) $settings['pos_pool'];
        $posGatewayIp = str($posGateway)->before('/')->toString();

        try {
            $client = $this->client($router, 8);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'steps' => [['label' => 'Check POS VLAN infrastructure', 'success' => false, 'error' => $e->getMessage()]],
            ];
        }

        $steps = [];

        $vlanExists = collect($client->query(new Query('/interface/vlan/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['name'] ?? null) === 'vlan-pos');

        if (! $vlanExists) {
            $steps['Create POS VLAN interface'] = (new Query('/interface/vlan/add'))
                ->equal('interface', 'bridge-lan')
                ->equal('name', 'vlan-pos')
                ->equal('vlan-id', $posVlan);
        }

        $addressExists = collect($client->query(new Query('/ip/address/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['interface'] ?? null) === 'vlan-pos');

        if (! $addressExists) {
            $steps['Create POS VLAN IP address'] = (new Query('/ip/address/add'))
                ->equal('address', $posGateway)
                ->equal('interface', 'vlan-pos');
        }

        $poolExists = collect($client->query(new Query('/ip/pool/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['name'] ?? null) === 'pool-pos');

        if (! $poolExists) {
            $steps['Create POS address pool'] = (new Query('/ip/pool/add'))
                ->equal('name', 'pool-pos')
                ->equal('ranges', $posPool);
        }

        $dhcpServerExists = collect($client->query(new Query('/ip/dhcp-server/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['name'] ?? null) === 'dhcp-pos');

        if (! $dhcpServerExists) {
            $steps['Create POS DHCP server'] = (new Query('/ip/dhcp-server/add'))
                ->equal('name', 'dhcp-pos')
                ->equal('interface', 'vlan-pos')
                ->equal('address-pool', 'pool-pos')
                ->equal('lease-time', '12h')
                ->equal('disabled', 'no');
        }

        $dhcpNetworkExists = collect($client->query(new Query('/ip/dhcp-server/network/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['address'] ?? null) === $posNetwork);

        if (! $dhcpNetworkExists) {
            $steps['Create POS DHCP network'] = (new Query('/ip/dhcp-server/network/add'))
                ->equal('address', $posNetwork)
                ->equal('gateway', $posGatewayIp)
                ->equal('dns-server', $posGatewayIp);
        }

        if ($steps === []) {
            return ['success' => true, 'steps' => []];
        }

        return $this->runSteps($router, $steps);
    }

    /**
     * Points the POS hotspot server (interface=vlan-pos) at mms-pos-profile,
     * creating it first if it doesn't exist yet -- unlike applyHotspotProfile()
     * for the customer hotspot, this is safe to create because vlan-pos/
     * pool-pos are fixed names this app's own script generator always uses,
     * never a router-specific choice from a manual `/ip hotspot setup`.
     *
     * @return array{label: string, success: bool, error: ?string}
     */
    private function applyPosHotspotServer(Router $router): array
    {
        $label = 'Point POS hotspot server at "mms-pos-profile"';

        try {
            $client = $this->client($router, 8);
            $existing = collect($client->query(new Query('/ip/hotspot/print'))->read())
                ->first(fn ($row) => is_array($row) && ($row['interface'] ?? null) === 'vlan-pos');

            $query = $existing !== null
                ? (new Query('/ip/hotspot/set'))
                    ->equal('numbers', $existing['.id'])
                    ->equal('profile', 'mms-pos-profile')
                : (new Query('/ip/hotspot/add'))
                    ->equal('name', 'mms-pos')
                    ->equal('interface', 'vlan-pos')
                    ->equal('address-pool', 'pool-pos')
                    ->equal('profile', 'mms-pos-profile')
                    ->equal('disabled', 'no');

            $raw = $client->query($query)->read(false);

            if ($trapMessage = self::extractTrapMessage($raw)) {
                throw new \RuntimeException($trapMessage);
            }

            return ['label' => $label, 'success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['label' => $label, 'success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The live-API counterpart to MikroTikProvisioningService::
     * generateStaffScript(), scoped the same deliberately-narrower way
     * provisionPos()'s own live-API side is scoped relative to
     * generatePosScript(): the VLAN/pool/DHCP existence checks
     * (ensureStaffInfrastructure()) are simple, safe-to-run-every-cycle
     * "does this exist" checks, but creating the virtual Wi-Fi SSID itself
     * (security + configuration + interface + bridge port) is NOT attempted
     * live here, matching POS's own precedent of deferring that harder,
     * bridge-vlan-table-adjacent work to the paste-by-hand script. If the
     * SSID doesn't exist yet, syncWifiAccessList()'s step below will just
     * fail plainly (interface not found) rather than silently no-opping.
     *
     * The trusted-device access list itself IS synced live here, though --
     * unlike SSID creation, it reduces to a single per-interface list-then-
     * replace operation (well-scoped, no bridge-vlan reconciliation needed),
     * and closing the "not pushed to the router automatically" gap
     * documented in docs/staff-wifi-access.md is this method's whole reason
     * for existing.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function provisionStaffWifi(Router $router): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'Staff/Management Wi-Fi trusted-device access list', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        $settings = (array) $router->provisioning_settings;
        $enableBuiltinWifi = (bool) ($settings['enable_builtin_wifi'] ?? false);
        $enableStaff = (bool) ($settings['enable_staff'] ?? true);
        $enableMgmtWifi = (bool) ($settings['enable_mgmt_wifi'] ?? false);

        if (! $enableBuiltinWifi || (! $enableStaff && ! $enableMgmtWifi)) {
            return ['success' => true, 'steps' => []];
        }

        $result = ['success' => true, 'steps' => []];

        if ($enableStaff) {
            $infraResult = $this->ensureStaffInfrastructure($router);
            $result['steps'] = array_merge($result['steps'], $infraResult['steps']);
            $result['success'] = $result['success'] && $infraResult['success'];

            $step = $this->syncWifiAccessList($router, 'wifi-staff', 'MMS Staff', TrustedWifiDevice::NETWORK_STAFF);
            $result['steps'][] = $step;
            $result['success'] = $result['success'] && $step['success'];
        }

        if ($enableMgmtWifi) {
            $step = $this->syncWifiAccessList($router, 'wifi-mgmt', 'MMS Mgmt', TrustedWifiDevice::NETWORK_MGMT);
            $result['steps'][] = $step;
            $result['success'] = $result['success'] && $step['success'];
        }

        return $result;
    }

    /**
     * Mirrors ensurePosInfrastructure() exactly, for Staff instead of POS --
     * see that method's own docblock for why this is scoped to just these
     * four simple, uniquely-named objects. Management has no equivalent:
     * vlan-mgmt/pool-mgmt/dhcp-mgmt are core infrastructure
     * generateFreshInfrastructureScript() always creates unconditionally,
     * so a router reachable over the API at all has almost certainly
     * already had them created some other way.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    private function ensureStaffInfrastructure(Router $router): array
    {
        $settings = $this->provisioning->provisioningSettings($router, (string) (((array) $router->provisioning_settings)['profile'] ?? 'starlink_plaza'));

        $staffVlan = (string) $settings['staff_vlan'];
        $staffGateway = (string) $settings['staff_gateway'];
        $staffNetwork = (string) $settings['staff_network'];
        $staffPool = (string) $settings['staff_pool'];
        $staffGatewayIp = str($staffGateway)->before('/')->toString();

        try {
            $client = $this->client($router, 8);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'steps' => [['label' => 'Check Staff VLAN infrastructure', 'success' => false, 'error' => $e->getMessage()]],
            ];
        }

        $steps = [];

        $vlanExists = collect($client->query(new Query('/interface/vlan/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['name'] ?? null) === 'vlan-staff');

        if (! $vlanExists) {
            $steps['Create Staff VLAN interface'] = (new Query('/interface/vlan/add'))
                ->equal('interface', 'bridge-lan')
                ->equal('name', 'vlan-staff')
                ->equal('vlan-id', $staffVlan);
        }

        $addressExists = collect($client->query(new Query('/ip/address/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['interface'] ?? null) === 'vlan-staff');

        if (! $addressExists) {
            $steps['Create Staff VLAN IP address'] = (new Query('/ip/address/add'))
                ->equal('address', $staffGateway)
                ->equal('interface', 'vlan-staff');
        }

        $poolExists = collect($client->query(new Query('/ip/pool/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['name'] ?? null) === 'pool-staff');

        if (! $poolExists) {
            $steps['Create Staff address pool'] = (new Query('/ip/pool/add'))
                ->equal('name', 'pool-staff')
                ->equal('ranges', $staffPool);
        }

        $dhcpServerExists = collect($client->query(new Query('/ip/dhcp-server/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['name'] ?? null) === 'dhcp-staff');

        if (! $dhcpServerExists) {
            $steps['Create Staff DHCP server'] = (new Query('/ip/dhcp-server/add'))
                ->equal('name', 'dhcp-staff')
                ->equal('interface', 'vlan-staff')
                ->equal('address-pool', 'pool-staff')
                ->equal('lease-time', '8h')
                ->equal('disabled', 'no');
        }

        $dhcpNetworkExists = collect($client->query(new Query('/ip/dhcp-server/network/print'))->read())
            ->contains(fn ($row) => is_array($row) && ($row['address'] ?? null) === $staffNetwork);

        if (! $dhcpNetworkExists) {
            $steps['Create Staff DHCP network'] = (new Query('/ip/dhcp-server/network/add'))
                ->equal('address', $staffNetwork)
                ->equal('gateway', $staffGatewayIp)
                ->equal('dns-server', $staffGatewayIp);
        }

        if ($steps === []) {
            return ['success' => true, 'steps' => []];
        }

        return $this->runSteps($router, $steps);
    }

    /**
     * Reconciles one SSID's `/interface/wifi/access-list` against MMS
     * Radius's current Trusted Wi-Fi Devices for that shop/network --
     * removes every existing entry for this interface (one at a time, by
     * `.id`, rather than a single bulk `numbers=` call, matching this file's
     * existing per-row pattern elsewhere) and re-adds the current desired
     * state: one accept entry per active, non-expired device, followed by a
     * single catch-all reject -- unless zero devices are registered, in
     * which case no reject is added at all, so a brand-new SSID doesn't
     * silently lock out every device before anything has been registered
     * (mirrors MikroTikProvisioningService::wifiAccessListLines()'s own
     * "no devices yet" behavior exactly). A full remove-then-rebuild is used
     * instead of a true diff deliberately -- ordering matters here (the
     * reject entry must always sort last, or a device added after it would
     * never be reached), and a real-world trusted-device list is small
     * enough that this is cheap and avoids an entire class of ordering bugs
     * a partial diff would need to get right. Not yet confirmed against real
     * hardware -- in particular, whether `/interface/wifi/access-list/print`
     * actually surfaces `interface` the way this assumes, the same "not yet
     * confirmed" caveat this file already carries for other freshly-added
     * RouterOS behavior.
     *
     * Confirmed live 2026-09-23: on a router where enable_staff/
     * enable_builtin_wifi were both on but the wireless SSID itself had
     * never actually been pasted (only the VLAN/pool/DHCP infrastructure had,
     * via ensureStaffInfrastructure()'s own live create step), this used to
     * attempt the `/interface/wifi/access-list/add` below anyway and let
     * RouterOS reject it with a raw, unhelpful "input does not match any
     * value of interface" -- true, but the actual list-then-diff design
     * intentionally never creates the wireless SSID itself live (see this
     * method's own docblock above and provisionStaffWifi()'s), so this
     * failure mode was always expected, just not reported clearly. Now
     * checks the interface's actual live existence first via
     * `/interface/wifi/print` and fails fast with an actionable message
     * instead of a confusing RouterOS trap -- the config-level
     * enable_builtin_wifi/enable_staff/enable_mgmt_wifi toggles only say
     * whether a wireless SSID is *supposed* to exist, not whether it
     * *actually* does yet on this specific router.
     *
     * @return array{label: string, success: bool, error: ?string}
     */
    private function syncWifiAccessList(Router $router, string $interfaceName, string $ssidLabel, string $network): array
    {
        $label = "Sync {$ssidLabel} trusted-device access list";

        try {
            $client = $this->client($router, 8);

            $interfaceExists = collect($client->query(new Query('/interface/wifi/print'))->read())
                ->contains(fn ($row) => is_array($row) && ($row['name'] ?? null) === $interfaceName);

            if (! $interfaceExists) {
                return [
                    'label' => $label,
                    'success' => false,
                    'error' => "The {$ssidLabel} Wi-Fi interface ({$interfaceName}) doesn't exist on this router yet -- paste the wireless SSID lines from the Staff Script tab (security, configuration, interface, and bridge port) first, then retry.",
                ];
            }

            $existingIds = collect($client->query(new Query('/interface/wifi/access-list/print'))->read())
                ->filter(fn ($row) => is_array($row) && ($row['interface'] ?? null) === $interfaceName)
                ->pluck('.id')
                ->filter()
                ->values();

            foreach ($existingIds as $id) {
                $raw = $client->query((new Query('/interface/wifi/access-list/remove'))->equal('numbers', $id))->read(false);

                if ($trapMessage = self::extractTrapMessage($raw)) {
                    throw new \RuntimeException($trapMessage);
                }
            }

            $devices = TrustedWifiDevice::query()
                ->where('shop_id', $router->shop_id)
                ->where('network', $network)
                ->get()
                ->filter(fn (TrustedWifiDevice $device): bool => $device->isCurrentlyActive());

            foreach ($devices as $device) {
                $comment = trim($device->device_name.($device->owner_name ? ' ('.$device->owner_name.')' : ''));
                $raw = $client->query(
                    (new Query('/interface/wifi/access-list/add'))
                        ->equal('interface', $interfaceName)
                        ->equal('mac-address', $device->mac_address)
                        ->equal('action', 'accept')
                        ->equal('comment', $comment)
                )->read(false);

                if ($trapMessage = self::extractTrapMessage($raw)) {
                    throw new \RuntimeException($trapMessage);
                }
            }

            if ($devices->isNotEmpty()) {
                $raw = $client->query(
                    (new Query('/interface/wifi/access-list/add'))
                        ->equal('interface', $interfaceName)
                        ->equal('action', 'reject')
                        ->equal('comment', "Default-deny: only registered {$ssidLabel} devices may join")
                )->read(false);

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
     * The `.id` of an existing `/ip/hotspot/profile` row named `$name`, or
     * null if none exists yet (or the router can't currently be reached --
     * failing open to "doesn't exist" here just means provisionHotspot()
     * falls back to attempting an `/add`, the same behavior this had before
     * this check existed).
     */
    private function existingHotspotProfileId(Router $router, string $name): ?string
    {
        try {
            $row = collect($this->client($router, 8)->query(new Query('/ip/hotspot/profile/print'))->read())
                ->first(fn ($r) => is_array($r) && ($r['name'] ?? null) === $name);

            return $row['.id'] ?? null;
        } catch (\Throwable) {
            return null;
        }
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

    private const FRESH_INFRASTRUCTURE_SCRIPT_NAME = 'mms-radius-fresh-infra';

    /**
     * Pushes MikroTikProvisioningService::generateFreshInfrastructureScript()'s
     * output (VLANs, bridge, management/hotspot/POS/staff networks, QoS,
     * firewall -- everything a fresh router needs beyond what the Bootstrap
     * Script/provisionHotspot()/provisionPppoe() cover) and runs it live,
     * instead of it being copy-pasted onto the router console by hand.
     *
     * Deliberately NOT idempotent, unlike every other live-API action in
     * this file. Most of what the generated script does (bridge VLAN
     * entries, DHCP servers, the hotspot profile/server, queue types,
     * firewall rules) are plain "add" commands with no "does this already
     * exist?" check -- the same as pasting the script by hand. Running this
     * twice on the same router creates duplicate objects, exactly as
     * re-pasting the script manually would; there is no equivalent of
     * syncWalledGarden()'s "list what's there, only add what's missing"
     * here. This is meant for a router that hasn't had it applied yet, not
     * a repeatable sync.
     *
     * Implemented via RouterOS's own /system script object rather than
     * translating the generated script's ~50 lines (:global variables, :if
     * guards) into individual API Query calls one-for-one -- uploading and
     * running it through /system/script/add + /system/script/run executes
     * the EXACT same script RouterOS's own terminal would from a paste, with
     * identical behavior, and needs no per-line reimplementation. Any
     * leftover script object from a previous attempt that didn't finish
     * cleanly (e.g. the connection dropped mid-run) is removed first so this
     * always starts from a known state; the object is removed again after a
     * successful run so nothing is left behind on the router either way.
     *
     * Not yet confirmed live -- RouterOS's exact required `policy=` for a
     * script object executing this range of commands (interface/ip/queue
     * add/set) hasn't been exercised against real hardware. The policy
     * string below mirrors this app's own API user's granted policy
     * (apiUserProvisioningLines()) minus the rights that user is itself
     * denied (reboot, winbox, password, web, sniff, romon, rest-api, ssh,
     * telnet, local, policy) -- a script's policy can't exceed what the
     * running user already has, so this is the widest set that could
     * possibly be granted.
     *
     * @return array{success: bool, steps: list<array{label: string, success: bool, error: ?string}>}
     */
    public function pushFreshInfrastructureScript(Router $router, string $scriptContent): array
    {
        if (! $this->isConfigured($router)) {
            return [
                'success' => false,
                'steps' => [['label' => 'Push Fresh Infrastructure Script', 'success' => false, 'error' => 'No RouterOS API credentials generated for this router yet.']],
            ];
        }

        try {
            $client = $this->client($router, 30);
        } catch (\Throwable $e) {
            return ['success' => false, 'steps' => [['label' => 'Push Fresh Infrastructure Script', 'success' => false, 'error' => $e->getMessage()]]];
        }

        $steps = [];
        $scriptName = self::FRESH_INFRASTRUCTURE_SCRIPT_NAME;

        try {
            $leftover = collect($client->query(new Query('/system/script/print'))->read())
                ->first(fn ($row) => is_array($row) && ($row['name'] ?? null) === $scriptName);

            if ($leftover !== null) {
                $client->query((new Query('/system/script/remove'))->equal('numbers', $leftover['.id']))->read(false);
            }

            $addRaw = $client->query(
                (new Query('/system/script/add'))
                    ->equal('name', $scriptName)
                    ->equal('policy', 'read,write,test,sensitive,ftp,api')
                    ->equal('source', $scriptContent)
            )->read(false);

            if ($trapMessage = self::extractTrapMessage($addRaw)) {
                throw new \RuntimeException('Could not upload the script: '.$trapMessage);
            }

            $steps[] = ['label' => 'Upload Fresh Infrastructure Script', 'success' => true, 'error' => null];

            $uploaded = collect($client->query(new Query('/system/script/print'))->read())
                ->first(fn ($row) => is_array($row) && ($row['name'] ?? null) === $scriptName);

            if ($uploaded === null) {
                throw new \RuntimeException('Script was uploaded but could not be found afterward to run it.');
            }

            $runRaw = $client->query((new Query('/system/script/run'))->equal('numbers', $uploaded['.id']))->read(false);

            if ($trapMessage = self::extractTrapMessage($runRaw)) {
                throw new \RuntimeException('The script ran but reported an error: '.$trapMessage);
            }

            $steps[] = ['label' => 'Run Fresh Infrastructure Script', 'success' => true, 'error' => null];

            $client->query((new Query('/system/script/remove'))->equal('numbers', $uploaded['.id']))->read(false);

            return ['success' => true, 'steps' => $steps];
        } catch (\Throwable $e) {
            $steps[] = ['label' => 'Push Fresh Infrastructure Script', 'success' => false, 'error' => $e->getMessage()];

            return ['success' => false, 'steps' => $steps];
        }
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
        $entries['Add walled-garden entry (wa.me)'] = 'wa.me';
        $entries['Add walled-garden entry (*.wa.me)'] = '*.wa.me';

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
     * The interface name RouterOS auto-assigns the first (and, for this app's
     * purposes, only) `/zerotier interface add` -- matches the same literal
     * MikroTikProvisioningService::zeroTierLines() now assumes, confirmed on
     * the same real hardware.
     */
    private const ZEROTIER_INTERFACE_NAME = 'zerotier1';

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

        $needsJoin = in_array('join', $actions, true);
        $needsRejoin = in_array('rejoin', $actions, true);

        // Confirmed live 2026-09-21: RouterOS caches a network's authorization state on its
        // own interface object and never re-requests it on its own just because the controller
        // authorized the node afterward -- a router that was ACCESS_DENIED the moment it first
        // joined (the normal case: it joins before its node ID has been approved in MMS Radius)
        // stays ACCESS_DENIED forever otherwise, even once nothing else is actually wrong.
        // Removing and re-adding the interface is what forces a fresh request, mirroring the
        // leave/rejoin fix this same bug needed on the Pi's own ZeroTier membership.
        if ($needsRejoin) {
            $steps['Remove stale ZeroTier network join'] = (new Query('/zerotier/interface/remove'))
                ->equal('numbers', (string) $state['interface_id']);
        }

        if ($needsJoin || $needsRejoin) {
            $steps['Join ZeroTier network'] = (new Query('/zerotier/interface/add'))
                ->equal('network', (string) config('services.zerotier.network_id'))
                ->equal('instance', $instance);
        }

        // Skip the connection entirely rather than opening one just to run zero steps --
        // the common case going forward is a router that's already enabled and joined,
        // only ever missing its address.
        $result = $steps === [] ? ['success' => true, 'steps' => []] : $this->runSteps($router, $steps);

        if (! in_array('address', $actions, true) || blank($router->zerotier_ip)) {
            return $result;
        }

        // Confirmed live 2026-09-21: "/zerotier/interface/add" returns before RouterOS has
        // actually finished registering the resulting interface -- addressing it in the same
        // breath the join step just ran in was rejected outright ("input does not match any
        // value of interface") even though the interface name itself was correct. Only needed
        // when this call is the one that just (re)joined; an already-joined router's interface
        // has had plenty of time to exist by now. A rejoin also invalidates whatever address was
        // previously bound (removing the interface removes its addresses too), which is exactly
        // why zeroTierActionsNeeded() always forces 'address' back on whenever 'rejoin' fires.
        if ($needsJoin || $needsRejoin) {
            sleep(3);
        }

        $addressResult = $this->runSteps($router, [
            'Assign ZeroTier IP' => (new Query('/ip/address/add'))
                ->equal('address', $router->zerotier_ip.'/24')
                ->equal('interface', self::ZEROTIER_INTERFACE_NAME),
        ]);

        $result['steps'] = array_merge($result['steps'], $addressResult['steps']);
        $result['success'] = $result['success'] && $addressResult['success'];

        return $result;
    }

    /**
     * Which of ['enable', 'join', 'rejoin', 'address'] this router's ZeroTier
     * instance still needs -- pure, pulled out of syncZeroTierNetworkMembership()
     * so it's unit testable without a live connection. 'address' comes last
     * deliberately: the interface it targets (ZEROTIER_INTERFACE_NAME) only
     * exists once 'join'/'rejoin' has actually run, and runSteps() executes
     * steps in this order within one connection. 'rejoin' always forces
     * 'address' back on too -- confirmed live 2026-09-21: removing the
     * interface (what 'rejoin' does) also removes any address bound to it, so
     * an already-address_assigned router still needs it re-applied afterward.
     *
     * @param  array{instance_id: ?string, instance_disabled: bool, network_joined: bool, network_authorized: bool, interface_id: ?string, address_assigned: bool}  $state
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

        $needsRejoin = false;

        if (! $state['network_joined']) {
            $actions[] = 'join';
        } elseif (! $state['network_authorized']) {
            // Confirmed live 2026-09-21: a router that joined before its node ID was
            // approved in MMS Radius (the normal order of events) sits on RouterOS's own
            // cached ACCESS_DENIED forever, even once the controller authorizes it --
            // RouterOS never re-requests network status on its own. Removing and
            // re-adding the interface is what forces a fresh request.
            $actions[] = 'rejoin';
            $needsRejoin = true;
        }

        if (! $state['address_assigned'] || $needsRejoin) {
            $actions[] = 'address';
        }

        return $actions;
    }

    /**
     * @return array{instance_id: ?string, instance_disabled: bool, network_joined: bool, network_authorized: bool, interface_id: ?string, address_assigned: bool}
     */
    private function existingZeroTierState(Router $router, string $instance): array
    {
        $client = $this->client($router, 8);

        $instanceRow = collect($client->query(new Query('/zerotier/print'))->read())
            ->first(fn ($row) => is_array($row) && ($row['name'] ?? null) === $instance);

        $networkId = (string) config('services.zerotier.network_id');

        $interfaceRow = collect($client->query(new Query('/zerotier/interface/print'))->read())
            ->first(fn ($row) => is_array($row) && ($row['network'] ?? null) === $networkId);

        // Confirmed live 2026-09-21: joining the network alone never puts an IP address
        // on the resulting interface -- the controller's own ipAssignments value is only
        // auto-pushed when the network's v4AssignMode.zt is on, which this app deliberately
        // leaves off (IPs are assigned explicitly via $router->zerotier_ip instead). Without
        // this, the tunnel and controller authorization can both look completely fine while
        // nothing can actually reach the router's RouterOS API over it.
        $addressAssigned = blank($router->zerotier_ip) || collect($client->query(new Query('/ip/address/print'))->read())
            ->contains(fn ($row) => is_array($row) && str_starts_with((string) ($row['address'] ?? ''), $router->zerotier_ip.'/'));

        return self::mapZeroTierState($instanceRow, $interfaceRow, $addressAssigned);
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
     * $interfaceRow's own "status" property (confirmed live 2026-09-21 against
     * a real RouterOS 7 router: `/zerotier interface print detail` -- the raw
     * API property is `status`, value uppercase `"OK"` when genuinely
     * authorized) drives 'network_authorized' separately from 'network_joined'
     * -- the interface can exist (joined) while still sitting on a cached
     * ACCESS_DENIED from before the controller approved it.
     *
     * @param  array<string,mixed>|null  $instanceRow  the "/zerotier print" row matching this app's instance name, or null if none matched
     * @param  array<string,mixed>|null  $interfaceRow  the "/zerotier interface print" row matching this router's configured network, or null if not joined at all
     * @return array{instance_id: ?string, instance_disabled: bool, network_joined: bool, network_authorized: bool, interface_id: ?string, address_assigned: bool}
     */
    public static function mapZeroTierState(?array $instanceRow, ?array $interfaceRow, bool $addressAssigned = false): array
    {
        return [
            'instance_id' => $instanceRow['.id'] ?? null,
            'instance_disabled' => $instanceRow !== null && ($instanceRow['disabled'] ?? 'no') === 'yes',
            'network_joined' => $interfaceRow !== null,
            'network_authorized' => $interfaceRow !== null && strtoupper((string) ($interfaceRow['status'] ?? '')) === 'OK',
            'interface_id' => $interfaceRow['.id'] ?? null,
            'address_assigned' => $addressAssigned,
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
            // Excludes the POS MAC-auth hotspot server (bound to interface=vlan-pos,
            // see provisionPos()/applyPosHotspotServer() below) -- before that exclusion
            // was added, a router with both hotspot servers present would have this loop
            // blindly reassign the POS one back to $profile (the customer-facing
            // "saas-prof"/http-pap profile) every single "Provision via API" run, silently
            // undoing POS's MAC-auth enforcement. Not yet confirmed live that RouterOS's
            // /ip/hotspot/print actually surfaces "interface" the same way this API
            // wrapper already relies on ".id" -- if it doesn't, this filter is a no-op and
            // the pre-existing "reassign everything" behavior is unchanged, not made worse.
            $hotspots = collect($client->query(new Query('/ip/hotspot/print'))->read())
                ->filter(fn ($row) => is_array($row) && filled($row['.id'] ?? null) && ($row['interface'] ?? null) !== 'vlan-pos');

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
