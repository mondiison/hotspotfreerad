<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class SubscriptionReportService
{
    public function presets(): array
    {
        return [
            'today' => 'Today',
            'last_7_days' => '7 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_year' => 'This year',
        ];
    }

    public function filters(array $input): array
    {
        $data = Validator::make($input, [
            'preset' => ['nullable', 'in:today,last_7_days,this_month,last_month,this_year'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,expired'],
            'source' => ['nullable', 'in:paid,test'],
            'throttled' => ['nullable', 'in:1'],
        ])->validate();

        $preset = $data['preset'] ?? null;
        $from = null;
        $to = null;

        if ($preset) {
            [$from, $to] = $this->presetRange($preset);
        } elseif (filled($data['from'] ?? null) || filled($data['to'] ?? null)) {
            $from = filled($data['from'] ?? null)
                ? Carbon::parse($data['from'])->startOfDay()
                : now()->startOfMonth();
            $to = filled($data['to'] ?? null)
                ? Carbon::parse($data['to'])->endOfDay()
                : now()->endOfDay();
        }

        return [
            'preset' => $preset,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'from_date' => $from,
            'to_date' => $to,
            'search' => $data['search'] ?? null,
            'status' => $data['status'] ?? null,
            'source' => $data['source'] ?? null,
            'throttled' => $data['throttled'] ?? null,
        ];
    }

    public function query(User $user, array $filters)
    {
        return TenantAccess::scopeSubscriptions(
            Subscription::query()->with(['shop.tenant', 'package', 'payment']),
            $user
        )
            ->when($filters['from_date'] && $filters['to_date'], fn ($query) => $query->whereBetween('starts_at', [$filters['from_date'], $filters['to_date']]))
            ->when(filled($filters['search']), function ($query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('mac_address', 'like', "%{$search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('package', fn ($package) => $package->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('payment', fn ($payment) => $payment->where('tx_ref', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] === 'active', fn ($query) => $query->where('expires_at', '>', now()))
            ->when($filters['status'] === 'expired', fn ($query) => $query->where('expires_at', '<=', now()))
            ->when($filters['source'] === 'paid', fn ($query) => $query->whereNotNull('payment_id'))
            ->when($filters['source'] === 'test', fn ($query) => $query->whereNull('payment_id'))
            ->when($filters['throttled'] === '1', fn ($query) => $query->where('is_throttled', true));
    }

    public function summary($query): array
    {
        $summaryQuery = clone $query;

        return [
            'count' => (clone $summaryQuery)->count(),
            'active_count' => (clone $summaryQuery)->where('expires_at', '>', now())->count(),
            'expired_count' => (clone $summaryQuery)->where('expires_at', '<=', now())->count(),
            'paid_count' => (clone $summaryQuery)->whereNotNull('payment_id')->count(),
            'test_count' => (clone $summaryQuery)->whereNull('payment_id')->count(),
            'throttled_count' => (clone $summaryQuery)->where('is_throttled', true)->count(),
        ];
    }

    public function queryParams(array $filters): array
    {
        return array_filter([
            'preset' => $filters['preset'],
            'from' => $filters['preset'] ? null : $filters['from'],
            'to' => $filters['preset'] ? null : $filters['to'],
            'search' => $filters['search'],
            'status' => $filters['status'],
            'source' => $filters['source'],
            'throttled' => $filters['throttled'],
        ], fn ($value) => filled($value));
    }

    private function presetRange(string $preset): array
    {
        $today = now();

        return match ($preset) {
            'today' => [
                $today->copy()->startOfDay(),
                $today->copy()->endOfDay(),
            ],
            'last_7_days' => [
                $today->copy()->subDays(6)->startOfDay(),
                $today->copy()->endOfDay(),
            ],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => [
                $today->copy()->startOfYear(),
                $today->copy()->endOfDay(),
            ],
            default => [
                $today->copy()->startOfMonth(),
                $today->copy()->endOfDay(),
            ],
        };
    }
}
