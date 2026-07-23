<?php

namespace App\Services;

use App\Models\PppoeSubscriber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PppoeSubscriberReportService
{
    private const GIGAWORD_BYTES = 4294967296;

    public function attachUsage(Collection $subscribers): Collection
    {
        $subscribers->each(fn (PppoeSubscriber $subscriber) => $subscriber->setAttribute('radius_usage', [
            'upload_bytes' => 0,
            'download_bytes' => 0,
            'total_bytes' => 0,
            'session_count' => 0,
            'open_session_count' => 0,
            'last_seen_at' => null,
            'available' => Schema::hasTable('radacct'),
        ]));

        if ($subscribers->isEmpty() || ! Schema::hasTable('radacct')) {
            return $subscribers;
        }

        $usernames = $subscribers
            ->pluck('username')
            ->filter()
            ->map(fn (string $username) => strtolower($username))
            ->unique()
            ->values();

        if ($usernames->isEmpty()) {
            return $subscribers;
        }

        $sessions = $this->baseQuery()
            ->whereIn(DB::raw('LOWER(username)'), $usernames)
            ->get();

        $sessionsByUsername = $sessions->groupBy(fn ($session) => strtolower((string) $session->username));

        $subscribers->each(function (PppoeSubscriber $subscriber) use ($sessionsByUsername): void {
            $sessions = $sessionsByUsername->get(strtolower($subscriber->username), collect());
            $usage = $subscriber->getAttribute('radius_usage');

            foreach ($sessions as $session) {
                $upload = $this->bytesFromSession($session, 'input');
                $download = $this->bytesFromSession($session, 'output');

                $usage['upload_bytes'] += $upload;
                $usage['download_bytes'] += $download;
                $usage['total_bytes'] += $upload + $download;
                $usage['session_count']++;

                if (! $session->acctstoptime) {
                    $usage['open_session_count']++;
                }

                $lastSeenAt = $session->acctupdatetime ?: $session->acctstoptime ?: $session->acctstarttime;

                if ($lastSeenAt && (! $usage['last_seen_at'] || Carbon::parse($lastSeenAt)->greaterThan($usage['last_seen_at']))) {
                    $usage['last_seen_at'] = Carbon::parse($lastSeenAt);
                }
            }

            $subscriber->setAttribute('radius_usage', $usage);
        });

        return $subscribers;
    }

    public function sessionsFor(PppoeSubscriber $subscriber): Collection
    {
        if (! Schema::hasTable('radacct')) {
            return collect();
        }

        return $this->baseQuery()
            ->where(DB::raw('LOWER(username)'), strtolower($subscriber->username))
            ->orderByRaw('COALESCE(acctupdatetime, acctstoptime, acctstarttime) desc')
            ->limit(50)
            ->get()
            ->map(function ($session): object {
                $session->upload_bytes = $this->bytesFromSession($session, 'input');
                $session->download_bytes = $this->bytesFromSession($session, 'output');
                $session->total_bytes = $session->upload_bytes + $session->download_bytes;

                return $session;
            });
    }

    private function baseQuery()
    {
        $columns = [
            'acctsessionid',
            'acctuniqueid',
            'username',
            'nasipaddress',
            'framedipaddress',
            'callingstationid',
            'acctstarttime',
            'acctupdatetime',
            'acctstoptime',
            'acctsessiontime',
            'acctinputoctets',
            'acctoutputoctets',
            'acctterminatecause',
        ];

        $select = collect($columns)
            ->map(fn (string $column) => Schema::hasColumn('radacct', $column) ? $column : DB::raw("null as {$column}"))
            ->all();

        $select[] = Schema::hasColumn('radacct', 'acctinputgigawords')
            ? DB::raw('COALESCE(acctinputgigawords, 0) as acctinputgigawords')
            : DB::raw('0 as acctinputgigawords');
        $select[] = Schema::hasColumn('radacct', 'acctoutputgigawords')
            ? DB::raw('COALESCE(acctoutputgigawords, 0) as acctoutputgigawords')
            : DB::raw('0 as acctoutputgigawords');

        return DB::table('radacct')
            ->select($select);
    }

    private function bytesFromSession(object $session, string $direction): int
    {
        $octets = (int) ($direction === 'input' ? $session->acctinputoctets : $session->acctoutputoctets);
        $gigawords = (int) ($direction === 'input' ? $session->acctinputgigawords : $session->acctoutputgigawords);

        return $octets + ($gigawords * self::GIGAWORD_BYTES);
    }
}
