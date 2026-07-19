<?php

namespace App\Services;

use App\Models\SecurityActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SecurityActivityService
{
    public function log(User $user, string $action, string $label, array $metadata = [], ?Request $request = null): SecurityActivity
    {
        $request ??= request();

        return SecurityActivity::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'action' => $action,
            'label' => $label,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'metadata' => $metadata ?: null,
        ]);
    }

    public function recentFor(User $user, int $limit = 10): Collection
    {
        return SecurityActivity::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();
    }
}
