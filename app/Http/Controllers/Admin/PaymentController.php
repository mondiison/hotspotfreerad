<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $query = TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package', 'customer', 'subscription']),
            $request->user()
        )
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();

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
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('provider'), fn ($query) => $query->where('provider', $request->string('provider')->toString()));

        $summaryQuery = clone $query;
        $successfulQuery = (clone $summaryQuery)->where('status', 'successful');

        return view('admin.payments.index', [
            'payments' => $query->latest()->paginate(20)->withQueryString(),
            'summary' => [
                'count' => (clone $summaryQuery)->count(),
                'successful_count' => (clone $summaryQuery)->where('status', 'successful')->count(),
                'pending_count' => (clone $summaryQuery)->where('status', 'pending')->count(),
                'successful_revenue' => (clone $successfulQuery)->sum(DB::raw('coalesce(nullif(gross_amount, 0), amount)')),
                'platform_fee' => (clone $successfulQuery)->sum('platform_fee_amount'),
                'tenant_net' => (clone $successfulQuery)->sum(DB::raw('coalesce(nullif(tenant_net_amount, 0), coalesce(nullif(gross_amount, 0), amount) - platform_fee_amount)')),
            ],
        ]);
    }
}
