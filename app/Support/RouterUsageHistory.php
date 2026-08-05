<?php

namespace App\Support;

use App\Models\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RouterUsageHistory
{
    /**
     * Daily download/upload totals for a router over the trailing $days days,
     * built from FreeRADIUS accounting (radacct). A session's bytes are
     * attributed to the calendar day it started on, not spread across the
     * days it may have run through — accurate enough for a usage trend, not
     * a precise per-day billing figure.
     *
     * @return list<array{date: string, download_bytes: int, upload_bytes: int}>
     */
    public function daily(Router $router, int $days = 30): array
    {
        if (! Schema::hasTable('radacct')) {
            return [];
        }

        $since = now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('radacct')
            ->where('nasipaddress', $router->wireguard_internal_ip)
            ->where('acctstarttime', '>=', $since)
            ->selectRaw('DATE(acctstarttime) as day, SUM(COALESCE(acctoutputoctets, 0)) as download_bytes, SUM(COALESCE(acctinputoctets, 0)) as upload_bytes')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $rows->get($date);

            $series[] = [
                'date' => $date,
                'download_bytes' => (int) ($row->download_bytes ?? 0),
                'upload_bytes' => (int) ($row->upload_bytes ?? 0),
            ];
        }

        return $series;
    }
}
