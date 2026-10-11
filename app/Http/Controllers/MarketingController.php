<?php

namespace App\Http\Controllers;

use App\Models\BillingPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('admin.dashboard');
        }

        $plans = BillingPlan::where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        return view('marketing.home', ['plans' => $plans]);
    }
}
