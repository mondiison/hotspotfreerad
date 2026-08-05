<?php

namespace App\Support;

use App\Models\Router;
use App\Models\RouterMetricSample;
use Illuminate\Support\Carbon;

class RouterMetricHistory
{
    /**
     * CPU/RAM/latency series for a router between two timestamps. Buckets
     * hourly for windows of 2 days or less, daily otherwise. Bucketing
     * happens in PHP rather than SQL date-truncation functions, which
     * differ between SQLite (tests) and MySQL (production) -- the sample
     * volume here (one row per router per 5 minutes) is small enough that
     * this doesn't need to happen in the database.
     *
     * @return list<array{label: string, cpu_percent: ?float, ram_percent: ?float, latency_ms: ?float}>
     */
    public function series(Router $router, Carbon $from, Carbon $to): array
    {
        $samples = RouterMetricSample::query()
            ->where('router_id', $router->id)
            ->whereBetween('sampled_at', [$from, $to])
            ->orderBy('sampled_at')
            ->get();

        $bucketFormat = $from->diffInHours($to) <= 48 ? 'Y-m-d H:00' : 'Y-m-d';

        return $samples
            ->groupBy(fn (RouterMetricSample $sample): string => $sample->sampled_at->format($bucketFormat))
            ->map(function ($bucket, string $label): array {
                $cpuValues = $bucket->pluck('cpu_percent')->filter(fn ($v) => $v !== null);
                $ramValues = $bucket->map->ramUsagePercent()->filter(fn ($v) => $v !== null);
                $latencyValues = $bucket->pluck('latency_ms')->filter(fn ($v) => $v !== null);

                return [
                    'label' => $label,
                    'cpu_percent' => $cpuValues->isNotEmpty() ? round($cpuValues->avg(), 1) : null,
                    'ram_percent' => $ramValues->isNotEmpty() ? round($ramValues->avg(), 1) : null,
                    'latency_ms' => $latencyValues->isNotEmpty() ? round($latencyValues->avg(), 1) : null,
                ];
            })
            ->values()
            ->all();
    }
}
