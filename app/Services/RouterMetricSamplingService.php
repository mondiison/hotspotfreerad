<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterMetricSample;
use App\Models\User;
use App\Notifications\RouterAlertNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

class RouterMetricSamplingService
{
    private const CPU_ALERT_THRESHOLD = 85;

    private const RAM_ALERT_THRESHOLD = 85;

    public function __construct(private readonly RouterOsConnectionService $routerOs) {}

    /**
     * Samples one router: latency (ICMP ping from this host to the router's
     * WireGuard IP -- no RouterOS API needed) plus, if API credentials are
     * configured, CPU/RAM/disk/uptime and hardware health. Always stores a
     * row, even if every value comes back null, so gaps are visible in the
     * history rather than silently missing. Fires alerts on state
     * transitions (see evaluateAlerts()) after the sample is saved.
     */
    public function sample(Router $router): RouterMetricSample
    {
        $previous = RouterMetricSample::query()
            ->where('router_id', $router->id)
            ->latest('sampled_at')
            ->first();

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

        $sample = RouterMetricSample::create($data);

        $this->evaluateAlerts($router, $previous, $sample);

        return $sample;
    }

    /**
     * Edge-triggered alerting: only notify when a condition *starts* (the
     * previous sample didn't have it, this one does), not on every sample
     * while it persists. This avoids re-notifying every 5 minutes for as
     * long as, say, a router stays offline -- at the cost of not sending
     * periodic reminders for a still-ongoing issue. There is no separate
     * cooldown/dedup table; the transition check against the immediately
     * preceding sample is what prevents repeat notifications.
     */
    private function evaluateAlerts(Router $router, ?RouterMetricSample $previous, RouterMetricSample $current): void
    {
        $wasReachable = $previous !== null && $previous->latency_ms !== null;
        $isReachable = $current->latency_ms !== null;

        if ($wasReachable && ! $isReachable) {
            $this->notify($router, RouterAlertNotification::TYPE_OFFLINE, "{$router->name} stopped responding to ping.");
        } elseif ($previous !== null && ! $wasReachable && $isReachable) {
            $this->notify($router, RouterAlertNotification::TYPE_ONLINE, "{$router->name} is responding to ping again.");
        }

        $previousCpu = $previous?->cpu_percent;
        $currentCpu = $current->cpu_percent;

        if ($currentCpu !== null && $currentCpu >= self::CPU_ALERT_THRESHOLD && ($previousCpu === null || $previousCpu < self::CPU_ALERT_THRESHOLD)) {
            $this->notify($router, RouterAlertNotification::TYPE_HIGH_CPU, "{$router->name} CPU usage is {$currentCpu}%.");
        }

        $previousRam = $previous?->ramUsagePercent();
        $currentRam = $current->ramUsagePercent();

        if ($currentRam !== null && $currentRam >= self::RAM_ALERT_THRESHOLD && ($previousRam === null || $previousRam < self::RAM_ALERT_THRESHOLD)) {
            $this->notify($router, RouterAlertNotification::TYPE_HIGH_RAM, "{$router->name} RAM usage is {$currentRam}%.");
        }
    }

    private function notify(Router $router, string $type, string $message): void
    {
        $recipients = $this->recipients($router);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new RouterAlertNotification($router, $type, $message));
    }

    /**
     * Every super admin, plus the tenant admins for this router's tenant.
     */
    private function recipients(Router $router): Collection
    {
        $router->loadMissing('shop');

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($router): void {
                $query->where('role', 'super_admin')
                    ->orWhere(function ($query) use ($router): void {
                        $query->where('role', 'tenant_admin')->where('tenant_id', $router->shop?->tenant_id);
                    });
            })
            ->get();
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
