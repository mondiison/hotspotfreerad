<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->filteredSubscriptions($request, $filters);

        $summaryQuery = clone $query;

        return view('admin.subscriptions.index', [
            'subscriptions' => $query->latest('expires_at')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'summary' => [
                'count' => (clone $summaryQuery)->count(),
                'active_count' => (clone $summaryQuery)->where('expires_at', '>', now())->count(),
                'expired_count' => (clone $summaryQuery)->where('expires_at', '<=', now())->count(),
                'paid_count' => (clone $summaryQuery)->whereNotNull('payment_id')->count(),
                'test_count' => (clone $summaryQuery)->whereNull('payment_id')->count(),
                'throttled_count' => (clone $summaryQuery)->where('is_throttled', true)->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'access-report-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Access Report']);
            fputcsv($handle, ['Status', $filters['status'] ?: 'All']);
            fputcsv($handle, ['Source', $filters['source'] ?: 'All']);
            fputcsv($handle, ['Throttled', $filters['throttled'] === '1' ? 'Yes' : 'All']);
            fputcsv($handle, ['Search', $filters['search'] ?: '']);
            fputcsv($handle, []);
            fputcsv($handle, [
                'MAC Address',
                'Package',
                'Shop',
                'Tenant',
                'Source',
                'Payment Ref',
                'Status',
                'Started At',
                'Expires At',
                'Throttled',
                'Speed Profile',
                'Created At',
            ]);

            $this->filteredSubscriptions($request, $filters)
                ->latest('expires_at')
                ->chunk(200, function ($subscriptions) use ($handle): void {
                    foreach ($subscriptions as $subscription) {
                        $isActive = $subscription->expires_at->isFuture();

                        fputcsv($handle, [
                            $subscription->mac_address,
                            $subscription->package?->name ?? 'Deleted package',
                            $subscription->shop?->name ?? 'Deleted shop',
                            $subscription->shop?->tenant?->company_name,
                            $subscription->payment ? 'Paid' : 'Test',
                            $subscription->payment?->tx_ref,
                            $isActive ? 'Active' : 'Expired',
                            $subscription->starts_at?->toDateTimeString(),
                            $subscription->expires_at?->toDateTimeString(),
                            $subscription->is_throttled ? 'Yes' : 'No',
                            $subscription->package?->speed_limit_profile,
                            $subscription->created_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function filteredSubscriptions(Request $request, array $filters)
    {
        return TenantAccess::scopeSubscriptions(
            Subscription::query()->with(['shop.tenant', 'package', 'payment']),
            $request->user()
        )
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

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,expired'],
            'source' => ['nullable', 'in:paid,test'],
            'throttled' => ['nullable', 'in:1'],
        ]);

        return [
            'search' => $data['search'] ?? null,
            'status' => $data['status'] ?? null,
            'source' => $data['source'] ?? null,
            'throttled' => $data['throttled'] ?? null,
        ];
    }
}
