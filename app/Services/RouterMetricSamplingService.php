<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterMetricSample;
use Illuminate\Support\Facades\Process;

class RouterMetricSamplingService
{
    public function __construct(private readonly RouterOsConnectionService $routerOs) {}

    /**
     * Samples one router: latency (ICMP ping from this host to the router's
     * WireGuard IP -- no RouterOS API needed) plus, if API credentials are
     * configured, CPU/RAM/disk/uptime and hardware health. Always stores a
     * row, even if every value comes back null, so gaps are visible in the
     * history rather than silently missing.
     */
    public function sample(Router $router): RouterMetricSample
    {
        $data = [
            'router_id' => $router->id,
            'latency_ms' => $this->pingLatencyMs($router->wireguard_internal_ip),
            'sampled_at' => now(),
        ];

        if ($this->routerOs->isConfigured($router)) {
            $resource = $this->routerOs->systemResource($router);

            if ($resource['success']) {
                $data['cpu_percent'] = $resource['cpu_percent'];
                $data['ram_used_bytes'] = $resource['ram_used_bytes'];
                $data['ram_total_bytes'] = $resource['ram_total_bytes'];
                $data['disk_used_bytes'] = $resource['disk_used_bytes'];
                $data['disk_total_bytes'] = $resource['disk_total_bytes'];
                $data['uptime_seconds'] = $resource['uptime_seconds'];
            }

            $health = $this->routerOs->systemHealth($router);

            if ($health['success'] && filled($health['fields'])) {
                $data['health'] = $health['fields'];
            }
        }

        return RouterMetricSample::create($data);
    }

    /**
     * Shells out to the system `ping` binary (works as an unprivileged user
     * on both Linux and Windows, since raw ICMP normally needs root/admin
     * and the ping binary itself carries that permission) and parses the
     * round-trip time. Returns null on any failure -- an unreachable router
     * is a valid, expected sample, not an error to throw over.
     */
    public function pingLatencyMs(?string $host): ?int
    {
        if (blank($host)) {
            return null;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', '2000', $host]
            : ['ping', '-c', '1', '-W', '2', $host];

        try {
            $result = Process::timeout(5)->run($command);
        } catch (\Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        if (preg_match('/time[=<]([\d.]+)\s*ms/i', $result->output(), $matches)) {
            return (int) round((float) $matches[1]);
        }

        return null;
    }
}
