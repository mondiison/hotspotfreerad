<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentReportService
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
            'status' => ['nullable', 'in:attention,pending,successful,failed,verification_failed'],
            'provider' => ['nullable', 'in:flutterwave'],
        ])->validate();

        $preset = $data['preset'] ?? null;

        if ($preset) {
            [$from, $to] = $this->presetRange($preset);
        } else {
            $from = filled($data['from'] ?? null)
                ? Carbon::parse($data['from'])->startOfDay()
                : now()->startOfMonth();
            $to = filled($data['to'] ?? null)
                ? Carbon::parse($data['to'])->endOfDay()
                : now()->endOfDay();
        }

        return [
            'preset' => $preset,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_date' => $from,
            'to_date' => $to,
            'search' => $data['search'] ?? null,
            'status' => $data['status'] ?? null,
            'provider' => $data['provider'] ?? null,
        ];
    }

    public function query(User $user, array $filters)
    {
        return TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package', 'customer', 'subscription']),
            $user
        )
            ->whereBetween('created_at', [$filters['from_date'], $filters['to_date']])
            ->when(filled($filters['search']), function ($query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('tx_ref', 'like', "%{$search}%")
                        ->orWhere('provider_reference', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('mac_address', 'like', "%{$search}%"))
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('package', fn ($package) => $package->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] === 'attention', fn ($query) => $query->whereIn('status', ['pending', 'failed', 'verification_failed']))
            ->when(filled($filters['status']) && $filters['status'] !== 'attention', fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['provider']), fn ($query) => $query->where('provider', $filters['provider']));
    }

    public function summary($query): array
    {
        $summaryQuery = clone $query;
        $successfulQuery = (clone $summaryQuery)->where('status', 'successful');
        $pendingQuery = (clone $summaryQuery)->where('status', 'pending');
        $failedQuery = (clone $summaryQuery)->whereIn('status', ['failed', 'verification_failed']);

        return [
            'count' => (clone $summaryQuery)->count(),
            'successful_count' => (clone $summaryQuery)->where('status', 'successful')->count(),
            'pending_count' => (clone $summaryQuery)->where('status', 'pending')->count(),
            'failed_count' => (clone $summaryQuery)->whereIn('status', ['failed', 'verification_failed'])->count(),
            'successful_revenue' => (clone $successfulQuery)->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)')),
            'pending_value' => (clone $pendingQuery)->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)')),
            'failed_value' => (clone $failedQuery)->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)')),
            'platform_fee' => (clone $successfulQuery)->sum('platform_fee_amount'),
            'tenant_net' => (clone $successfulQuery)->sum(DB::raw('coalesce(nullif(tenant_net_amount, 0), coalesce(nullif(gross_amount, 0), amount) - platform_fee_amount)')),
        ];
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'attention' => 'Needs attention',
            'verification_failed' => 'Verification failed',
            'successful' => 'Successful',
            'pending' => 'Pending',
            'failed' => 'Failed',
            default => 'All',
        };
    }

    public function queryParams(array $filters): array
    {
        return array_filter([
            'preset' => $filters['preset'],
            'from' => $filters['preset'] ? null : $filters['from'],
            'to' => $filters['preset'] ? null : $filters['to'],
            'search' => $filters['search'],
            'status' => $filters['status'],
            'provider' => $filters['provider'],
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
