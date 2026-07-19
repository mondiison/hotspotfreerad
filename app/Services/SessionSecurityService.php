<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionSecurityService
{
    public function sessionsFor(User $user, ?string $currentSessionId): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::table($this->table())
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->limit(10)
            ->get()
            ->map(function (object $session) use ($currentSessionId): array {
                return [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address ?: 'Unknown IP',
                    'device' => $this->deviceName((string) $session->user_agent),
                    'user_agent' => (string) $session->user_agent,
                    'last_active' => Carbon::createFromTimestamp((int) $session->last_activity),
                    'is_current' => hash_equals((string) $currentSessionId, (string) $session->id),
                ];
            });
    }

    public function logoutOtherSessions(User $user, string $currentSessionId): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table($this->table())
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function deviceName(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown browser';
        }

        $browser = match (true) {
            Str::contains($userAgent, 'Edg/') => 'Microsoft Edge',
            Str::contains($userAgent, 'Chrome/') => 'Chrome',
            Str::contains($userAgent, 'Firefox/') => 'Firefox',
            Str::contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            Str::contains($userAgent, 'Windows') => 'Windows',
            Str::contains($userAgent, 'Macintosh') => 'macOS',
            Str::contains($userAgent, 'Android') => 'Android',
            Str::contains($userAgent, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($userAgent, 'Linux') => 'Linux',
            default => 'Device',
        };

        return $browser.' on '.$platform;
    }
}
