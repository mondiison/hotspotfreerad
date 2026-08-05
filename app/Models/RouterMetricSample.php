<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterMetricSample extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'health' => 'array',
            'sampled_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function cpuUsagePercent(): ?float
    {
        return $this->cpu_percent;
    }

    public function ramUsagePercent(): ?float
    {
        if (! $this->ram_total_bytes) {
            return null;
        }

        return round(($this->ram_used_bytes / $this->ram_total_bytes) * 100, 1);
    }

    public function diskUsagePercent(): ?float
    {
        if (! $this->disk_total_bytes) {
            return null;
        }

        return round(($this->disk_used_bytes / $this->disk_total_bytes) * 100, 1);
    }
}
