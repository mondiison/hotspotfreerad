<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 2 (2026-11-13): a Client's own sales/usage summary -- a genuinely
 * smaller, Client-scale sibling of SalesReportService, not a reuse of it.
 * That service is hardcoded to TenantAccess internally (too permissive
 * for a Client, same reason PackageManagementService/VoucherManagementService
 * aren't reused directly either -- see Client\PackagesIndex's docblock),
 * and its accounting-grade expense/budget tracking has no Client-facing
 * equivalent to scope it to anyway. This covers what a Client actually
 * needs: how much their own shop(s) sold, and the package breakdown.
 */
class ClientSalesReportService
{
    public function build(User $user, array $input): array
    {
        $filters = $this->filters($input);
        $from = $filters['from_date'];
        $to = $filters['to_date'];

        $payments = ClientAccess::scopePayments(
            Payment::query()->with(['shop', 'package'])->where('status', 'successful'),
            $user
        )
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($query) use ($from, $to): void {
                        $query->whereNull('paid_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->get();

        $revenue = $payments->sum(fn (Payment $payment) => $this->grossAmount($payment));

        $shopRows = $payments
            ->groupBy(fn (Payment $payment) => $payment->shop?->name ?? 'Deleted shop')
            ->map(fn ($grouped, string $shopName): array => [
                'shop' => $shopName,
                'sales_count' => $grouped->count(),
                'revenue' => $grouped->sum(fn (Payment $payment) => $this->grossAmount($payment)),
            ])
            ->sortByDesc('revenue')
            ->values();

        $packageRows = $payments
            ->groupBy(fn (Payment $payment) => $payment->package_id ?: 'deleted')
            ->map(function ($grouped) {
                $payment = $grouped->first();

                return [
                    'package' => $payment->package?->name ?? 'Deleted package',
                    'sales_count' => $grouped->count(),
                    'revenue' => $grouped->sum(fn (Payment $payment) => $this->grossAmount($payment)),
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        return [
            'filters' => [
                'preset' => $filters['preset'],
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'presets' => $this->presets(),
            'summary' => [
                'sales_count' => $payments->count(),
                'revenue' => $revenue,
                'average_sale' => $payments->avg(fn (Payment $payment) => $this->grossAmount($payment)) ?? 0,
            ],
            'shopRows' => $shopRows,
            'packageRows' => $packageRows,
        ];
    }

    public function filters(array $input): array
    {
        $data = Validator::make($input, [
            'preset' => ['nullable', 'in:today,last_7_days,this_month,last_month'],
        ])->validate();

        [$from, $to] = $this->presetRange($data['preset'] ?? 'this_month');

        return [
            'preset' => $data['preset'] ?? 'this_month',
            'from_date' => $from,
            'to_date' => $to,
        ];
    }

    public function presets(): array
    {
        return [
            'today' => 'Today',
            'last_7_days' => '7 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
        ];
    }

    /**
     * `gross_amount` is only ever populated for a payment that went
     * through PaymentCommission (wallet-mode/commission-split charges) --
     * for every other payment it's genuinely NULL in the database. Eloquent's
     * decimal cast casts a null underlying value through `(float) null` on
     * read, so `$payment->gross_amount` comes back as the STRING "0.00",
     * not null -- and "0.00" is truthy in PHP, so a plain `?:` fallback
     * (what SalesReportService::grossAmount() does, and what a first draft
     * of this method did too) never falls through to `amount` for an
     * ordinary payment, silently reporting every such sale as 0 revenue.
     * PaymentReportService's own raw SQL already works around the exact
     * same gotcha with `coalesce(nullif(gross_amount, 0), amount)`; this is
     * that same fix expressed in PHP over an already-fetched Collection.
     */
    private function grossAmount(Payment $payment): float
    {
        $gross = (float) $payment->gross_amount;

        return $gross > 0.0 ? $gross : (float) $payment->amount;
    }

    private function presetRange(string $preset): array
    {
        $today = now();

        return match ($preset) {
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'last_7_days' => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
        };
    }
}
