<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return view('admin.billing.index', [
                'plans' => BillingPlan::orderBy('monthly_price')->get(),
                'tenants' => Tenant::with('currentBillingSubscription.billingPlan')->orderBy('company_name')->get(),
                'subscriptions' => TenantBillingSubscription::with(['tenant', 'billingPlan'])
                    ->latest()
                    ->paginate(15),
            ]);
        }

        $tenant = Tenant::with('currentBillingSubscription.billingPlan')->findOrFail($user->tenant_id);

        return view('admin.billing.index', [
            'plans' => BillingPlan::where('is_active', true)->orderBy('monthly_price')->get(),
            'tenant' => $tenant,
            'currentSubscription' => $tenant->currentBillingSubscription,
            'subscriptions' => $tenant->billingSubscriptions()->with('billingPlan')->latest()->paginate(15),
        ]);
    }

    public function storeSubscription(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'billing_plan_id' => ['required', 'exists:billing_plans,id'],
            'status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'canceled'])],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_starts_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date', 'after_or_equal:current_period_starts_at'],
        ]);

        $plan = BillingPlan::findOrFail($data['billing_plan_id']);

        TenantBillingSubscription::create([
            'tenant_id' => $data['tenant_id'],
            'billing_plan_id' => $plan->id,
            'status' => $data['status'],
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'trial_ends_at' => $data['trial_ends_at'] ?? null,
            'current_period_starts_at' => $data['current_period_starts_at'] ?? now(),
            'current_period_ends_at' => $data['current_period_ends_at'] ?? now()->addMonth(),
            'payload' => [
                'created_by' => $request->user()->email,
                'note' => 'Manual platform billing assignment.',
            ],
        ]);

        return redirect()->route('admin.billing.index')->with('status', 'Tenant billing subscription recorded.');
    }

    public function createPlan(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.billing.plan-form', [
            'plan' => new BillingPlan(),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        BillingPlan::create($this->validatedPlan($request));

        return redirect()->route('admin.billing.index')->with('status', 'Billing plan created.');
    }

    public function editPlan(Request $request, BillingPlan $billingPlan): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.billing.plan-form', [
            'plan' => $billingPlan,
        ]);
    }

    public function updatePlan(Request $request, BillingPlan $billingPlan): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $billingPlan->update($this->validatedPlan($request, $billingPlan));

        return redirect()->route('admin.billing.index')->with('status', 'Billing plan updated.');
    }

    public function destroyPlan(Request $request, BillingPlan $billingPlan): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        if ($billingPlan->tenantSubscriptions()->exists()) {
            return redirect()
                ->route('admin.billing.index')
                ->withErrors(['billing_plan' => 'This billing plan is already used by tenant subscriptions. Hide it instead of deleting it.']);
        }

        $billingPlan->delete();

        return redirect()->route('admin.billing.index')->with('status', 'Billing plan deleted.');
    }

    private function validatedPlan(Request $request, ?BillingPlan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('billing_plans', 'slug')->ignore($plan?->id),
            ],
            'monthly_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'shop_limit' => ['nullable', 'integer', 'min:1'],
            'router_limit' => ['nullable', 'integer', 'min:1'],
            'package_limit' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'is_active' => false,
        ];

        $data['slug'] = Str::slug(($data['slug'] ?? null) ?: $data['name']);
        $data['currency'] = strtoupper($data['currency']);
        $data['features'] = collect(preg_split('/\r\n|\r|\n/', (string) ($data['features'] ?? '')))
            ->map(fn (string $feature): string => trim($feature))
            ->filter()
            ->values()
            ->all();

        return $data;
    }
}
