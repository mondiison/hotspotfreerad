<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\PlatformBillingPayment;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Services\PlatformFlutterwaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                'platformFlutterwaveConfigured' => app(PlatformFlutterwaveService::class)->isConfigured(),
            ]);
        }

        $tenant = Tenant::with('currentBillingSubscription.billingPlan')->findOrFail($user->tenant_id);

        return view('admin.billing.index', [
            'plans' => BillingPlan::where('is_active', true)->orderBy('monthly_price')->get(),
            'tenant' => $tenant,
            'currentSubscription' => $tenant->currentBillingSubscription,
            'subscriptions' => $tenant->billingSubscriptions()->with('billingPlan')->latest()->paginate(15),
            'platformFlutterwaveConfigured' => app(PlatformFlutterwaveService::class)->isConfigured(),
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

    public function checkout(Request $request, PlatformFlutterwaveService $flutterwave): RedirectResponse
    {
        $data = $request->validate([
            'billing_plan_id' => ['required', 'exists:billing_plans,id'],
            'tenant_id' => ['nullable', 'exists:tenants,id'],
        ]);

        $tenant = $request->user()->isSuperAdmin()
            ? Tenant::findOrFail($data['tenant_id'] ?? null)
            : Tenant::findOrFail($request->user()->tenant_id);

        $plan = BillingPlan::query()
            ->where('is_active', true)
            ->findOrFail($data['billing_plan_id']);

        if (! $flutterwave->isConfigured()) {
            return redirect()
                ->route('admin.billing.index')
                ->withErrors(['billing' => 'Platform Flutterwave credentials are not configured yet.']);
        }

        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'tx_ref' => 'PBF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
            'payload' => [
                'started_by' => $request->user()->email,
                'plan_name' => $plan->name,
            ],
        ]);

        try {
            $checkout = $flutterwave->initializeCheckout(
                $payment->load(['tenant', 'billingPlan']),
                route('admin.billing.payments.callback')
            );

            $payment->update([
                'provider_reference' => $checkout['provider_reference'],
                'payload' => array_merge($payment->payload ?? [], [
                    'checkout_url' => $checkout['checkout_url'],
                    'flutterwave_init_response' => $checkout['response'],
                ]),
            ]);

            if (filled($checkout['checkout_url'])) {
                return redirect()->away($checkout['checkout_url']);
            }
        } catch (\Throwable $exception) {
            Log::warning('Platform billing checkout initialization failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.billing.index')
            ->withErrors(['billing' => 'Unable to start platform billing checkout. Please try again.']);
    }

    public function callback(Request $request, PlatformFlutterwaveService $flutterwave): RedirectResponse
    {
        $txRef = $request->query('tx_ref') ?: $request->query('reference');
        $payment = PlatformBillingPayment::with(['tenant', 'billingPlan'])
            ->where('tx_ref', $txRef)
            ->firstOrFail();

        abort_unless($request->user()->isSuperAdmin() || $request->user()->tenant_id === $payment->tenant_id, 403);

        if (! $this->statusIsSuccessful($request->query('status'))) {
            $payment->update(['status' => $request->query('status', 'failed')]);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Platform billing payment was not successful.']);
        }

        $providerReference = $this->providerReferenceFromRequest($request) ?: $payment->provider_reference;

        if (blank($providerReference)) {
            $payment->update(['status' => 'verification_failed']);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Payment reference was missing from Flutterwave callback.']);
        }

        try {
            $verification = $flutterwave->verifyPayment(
                (string) $providerReference,
                $this->paymentResourceType((string) $providerReference, $request->query('type'))
            );
        } catch (\Throwable $exception) {
            Log::warning('Platform billing callback verification failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Could not verify platform billing payment.']);
        }

        if (! $this->verificationMatchesPayment($verification, $payment)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Flutterwave verification did not match this billing payment.']);
        }

        DB::transaction(function () use ($payment, $verification): void {
            $subscription = TenantBillingSubscription::create([
                'tenant_id' => $payment->tenant_id,
                'billing_plan_id' => $payment->billing_plan_id,
                'status' => 'active',
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'current_period_starts_at' => now(),
                'current_period_ends_at' => now()->addMonth(),
                'provider' => $payment->provider,
                'provider_reference' => (string) (data_get($verification, 'data.id') ?: $payment->provider_reference),
                'payload' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->tx_ref,
                ],
            ]);

            $payment->update([
                'tenant_billing_subscription_id' => $subscription->id,
                'status' => 'successful',
                'provider_reference' => (string) (data_get($verification, 'data.id') ?: $payment->provider_reference),
                'paid_at' => now(),
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);
        });

        return redirect()->route('admin.billing.index')->with('status', 'Platform subscription payment confirmed.');
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

    private function verificationMatchesPayment(array $verification, PlatformBillingPayment $payment): bool
    {
        return in_array(strtolower((string) data_get($verification, 'status')), ['success', 'successful', 'succeeded'], true)
            && $this->statusIsSuccessful(data_get($verification, 'data.status'))
            && (data_get($verification, 'data.reference') === $payment->tx_ref || data_get($verification, 'data.tx_ref') === $payment->tx_ref)
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($payment->currency)
            && (float) data_get($verification, 'data.amount') >= (float) $payment->amount;
    }

    private function providerReferenceFromRequest(Request $request): ?string
    {
        foreach (['id', 'order_id', 'charge_id', 'transaction_id'] as $key) {
            if (filled($request->query($key))) {
                return (string) $request->query($key);
            }
        }

        return null;
    }

    private function paymentResourceType(string $providerReference, mixed $hint = null): string
    {
        $hint = strtolower((string) $hint);

        if (str_contains($hint, 'charge') || str_starts_with(strtolower($providerReference), 'chg')) {
            return 'charge';
        }

        return 'order';
    }

    private function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed'], true);
    }
}
