<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $query = TenantAccess::scopeSubscriptions(
            Subscription::query()->with(['shop.tenant', 'package', 'payment']),
            $request->user()
        )
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('mac_address', 'like', "%{$search}%")
                        ->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('package', fn ($package) => $package->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('payment', fn ($payment) => $payment->where('tx_ref', 'like', "%{$search}%"));
                });
            })
            ->when($request->string('status')->toString() === 'active', fn ($query) => $query->where('expires_at', '>', now()))
            ->when($request->string('status')->toString() === 'expired', fn ($query) => $query->where('expires_at', '<=', now()))
            ->when($request->string('source')->toString() === 'paid', fn ($query) => $query->whereNotNull('payment_id'))
            ->when($request->string('source')->toString() === 'test', fn ($query) => $query->whereNull('payment_id'))
            ->when($request->string('throttled')->toString() === '1', fn ($query) => $query->where('is_throttled', true));

        $summaryQuery = clone $query;

        return view('admin.subscriptions.index', [
            'subscriptions' => $query->latest('expires_at')->paginate(20)->withQueryString(),
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
}
