<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SchedulerHealth
{
    public const CACHE_KEY = 'hotspot.scheduler.last_run_at';

    public function record(): void
    {
        Cache::forever(self::CACHE_KEY, now()->toISOString());
    }

    public function summary(): array
    {
        $lastRunAt = Cache::get(self::CACHE_KEY);
        $lastRun = $lastRunAt ? Carbon::parse($lastRunAt) : null;
        $isHealthy = $lastRun && $lastRun->greaterThanOrEqualTo(now()->subMinutes(3));

        return [
            'last_run_at' => $lastRun,
            'is_healthy' => $isHealthy,
            'label' => match (true) {
                $isHealthy => 'Healthy',
                $lastRun !== null => 'Delayed',
                default => 'Not seen yet',
            },
            'description' => match (true) {
                $isHealthy => 'Cron active. Expiry cleanup and maintenance jobs are running.',
                $lastRun !== null => 'The scheduler has reported before, but not in the last few minutes.',
                default => 'No scheduler heartbeat has been recorded yet. Check cron on the Pi.',
            },
            'last_seen' => $lastRun?->diffForHumans(),
            'command' => '* * * * * cd /var/www/hotspotfreerad && php artisan schedule:run >> /dev/null 2>&1',
        ];
    }
}
