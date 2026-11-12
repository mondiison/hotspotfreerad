<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientBillingPlan;
use App\Services\ClientBillingPlanManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClientBillingPlanController extends Controller
{
    public function index(Request $request): View
    {
        $plans = ClientBillingPlan::where('tenant_id', $request->user()->tenant_id)
            ->withCount('clientSubscriptions')
            ->orderBy('price')
            ->get();

        return view('admin.client-billing-plans.index', [
            'plans' => $plans,
        ]);
    }

    public function create(): View
    {
        return view('admin.client-billing-plans.form', [
            'plan' => new ClientBillingPlan,
        ]);
    }

    public function store(Request $request, ClientBillingPlanManagementService $plans): RedirectResponse
    {
        $plans->create($plans->validated($request), $request->user());

        return redirect()->route('admin.client-billing-plans.index')->with('status', 'Plan created.');
    }

    public function edit(Request $request, ClientBillingPlan $clientBillingPlan, ClientBillingPlanManagementService $plans): View
    {
        $plans->assertOwner($clientBillingPlan, $request->user());

        return view('admin.client-billing-plans.form', [
            'plan' => $clientBillingPlan,
        ]);
    }

    public function update(Request $request, ClientBillingPlan $clientBillingPlan, ClientBillingPlanManagementService $plans): RedirectResponse
    {
        $plans->update($clientBillingPlan, $plans->validated($request), $request->user());

        return redirect()->route('admin.client-billing-plans.index')->with('status', 'Plan updated.');
    }

    public function destroy(Request $request, ClientBillingPlan $clientBillingPlan, ClientBillingPlanManagementService $plans): RedirectResponse
    {
        try {
            $plans->delete($clientBillingPlan, $request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('admin.client-billing-plans.index')->withErrors($exception->errors());
        }

        return redirect()->route('admin.client-billing-plans.index')->with('status', 'Plan deleted.');
    }
}
