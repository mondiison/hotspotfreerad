<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        abort_if($user->isSuperAdmin(), 403, 'Super admins manage tenant wallets from the Wallet Withdrawals queue.');

        $tenant = Tenant::with('currentBillingSubscription.billingPlan', 'wallet')->findOrFail($user->tenant_id);

        return view('admin.wallet.index', [
            'tenant' => $tenant,
        ]);
    }
}
