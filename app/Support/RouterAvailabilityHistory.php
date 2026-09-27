<?php

namespace App\Support;

use App\Models\Router;
use App\Models\RouterMetricSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Derives online/offline/no-data windows for a router over a date range from
 * the existing router_metric_samples heartbeat data -- there is no separate
 * "incident" table (RouterMetricSamplingService::evaluateAlerts() only ever
 * fires an instantaneous, edge-triggered notification on a state change, it
 * never persists a start/end interval anywhere), so this reconstructs those
 * windows by scanning consecutive samples' latency_ms nullness and stitching
 * same-status runs together.
 */
class RouterAvailabilityHistory
{
    /**
     * Samples are written every 5 minutes for every router (even when every
     * value is null), so a gap meaningfully larger than that cadence means
     * the scheduler wasn't running (or the router didn't exist yet) rather
     * than the router having genuinely failed every ping across that whole
     * span -- such a gap is reported as its own "no_data" segment instead of
     * being folded into the neighboring up/down run.
     */
    private const GAP_THRESHOLD_MINUTES = 15;

    /**
     * @return array{
     *     range_start: Carbon,
     *     range_end: Carbon,
     *     segments: list<array{status: string, start: Carbon, end: Carbon, duration_seconds: int}>,
     *     downtime_incidents: list<array{status: string, start: Carbon, end: Carbon, duration_seconds: int}>,
     *     uptime_percent: ?float,
     *     uptime_seconds: int,
     *     downtime_seconds: int,
     *     has_samples: bool,
     * }
     */
    public function summary(Router $router, Carbon $from, Carbon $to): array
    {
        $samples = RouterMetricSample::query()
            ->where('router_id', $router->id)
            ->whereBetween('sampled_at', [$from, $to])
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'latency_ms']);

        $segments = $this->buildSegments($samples, $from, $to);

        $uptimeSeconds = 0;
        $downtimeSeconds = 0;

        foreach ($segments as $segment) {
            if ($segment['status'] === 'up') {
                $uptimeSeconds += $segment['duration_seconds'];
            } elseif ($segment['status'] === 'down') {
                $downtimeSeconds += $segment['duration_seconds'];
            }
        }

        $monitoredSeconds = $uptimeSeconds + $downtimeSeconds;

        return [
            'range_start' => $from,
            'range_end' => $to,
            'segments' => $segments,
            'downtime_incidents' => array_values(array_filter(
                $segments,
                fn (array $segment): bool => $segment['status'] === 'down'
            )),
            'uptime_percent' => $monitoredSeconds > 0 ? round(($uptimeSeconds / $monitoredSeconds) * 100, 2) : null,
            'uptime_seconds' => $uptimeSeconds,
            'downtime_seconds' => $downtimeSeconds,
            'has_samples' => $samples->isNotEmpty(),
        ];
    }

    /**
     * @param  Collection<int, RouterMetricSample>  $samples
     * @return list<array{status: string, start: Carbon, end: Carbon, duration_seconds: int}>
     */
    private function buildSegments(Collection $samples, Carbon $from, Carbon $to): array
    {
        if ($samples->isEmpty()) {
            return [$this->segment('no_data', $from->copy(), $to->copy())];
        }

        $segments = [];
        $current = null;
        $previousSample = null;

        /** @var RouterMetricSample $first */
        $first = $samples->first();

        if ($from->diffInMinutes($first->sampled_at) > self::GAP_THRESHOLD_MINUTES) {
            $segments[] = $this->segment('no_data', $from->copy(), $first->sampled_at->copy());
        }

        foreach ($samples as $sample) {
            $status = $sample->latency_ms !== null ? 'up' : 'down';

            if ($previousSample !== null && $previousSample->sampled_at->diffInMinutes($sample->sampled_at) > self::GAP_THRESHOLD_MINUTES) {
                if ($current !== null) {
                    $segments[] = $this->closeSegment($current, $previousSample->sampled_at->copy());
                    $current = null;
                }

                $segments[] = $this->segment('no_data', $previousSample->sampled_at->copy(), $sample->sampled_at->copy());
            }

            if ($current === null) {
                $current = ['status' => $status, 'start' => $sample->sampled_at->copy()];
            } elseif ($current['status'] !== $status) {
                $segments[] = $this->closeSegment($current, $sample->sampled_at->copy());
                $current = ['status' => $status, 'start' => $sample->sampled_at->copy()];
            }

            $previousSample = $sample;
        }

        /** @var RouterMetricSample $last */
        $last = $samples->last();
        $trailingGapMinutes = $last->sampled_at->diffInMinutes($to);
        $currentEnd = $trailingGapMinutes > self::GAP_THRESHOLD_MINUTES ? $last->sampled_at->copy() : $to->copy();

        $segments[] = $this->closeSegment($current, $currentEnd);

        if ($trailingGapMinutes > self::GAP_THRESHOLD_MINUTES) {
            $segments[] = $this->segment('no_data', $last->sampled_at->copy(), $to->copy());
        }

        return array_values(array_filter($segments, fn (array $segment): bool => $segment['duration_seconds'] > 0));
    }

    /**
     * @param  array{status: string, start: Carbon}  $current
     * @return array{status: string, start: Carbon, end: Carbon, duration_seconds: int}
     */
    private function closeSegment(array $current, Carbon $end): array
    {
        return $this->segment($current['status'], $current['start'], $end);
    }

    /**
     * @return array{status: string, start: Carbon, end: Carbon, duration_seconds: int}
     */
    private function segment(string $status, Carbon $start, Carbon $end): array
    {
        return [
            'status' => $status,
            'start' => $start,
            'end' => $end,
            'duration_seconds' => max(0, (int) round($start->diffInSeconds($end))),
        ];
    }
}
