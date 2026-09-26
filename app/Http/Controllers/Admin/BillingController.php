<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyPlatformBillingWebhook;
use App\Models\BillingPlan;
use App\Models\PlatformBillingPayment;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Services\BillingPlanManagementService;
use App\Services\Payments\PlatformHostedCheckoutManager;
use App\Services\PlatformBillingConfirmationService;
use App\Services\PlatformPaymentSettingsService;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $platformPayments = PlatformBillingPayment::with(['tenant', 'billingPlan'])
                ->latest()
                ->paginate(10, ['*'], 'payments_page');

            return view('admin.billing.index', [
                'plans' => BillingPlan::orderBy('monthly_price')->get(),
                'tenants' => Tenant::with('currentBillingSubscription.billingPlan')->orderBy('company_name')->get(),
                'subscriptions' => TenantBillingSubscription::with(['tenant', 'billingPlan'])
                    ->latest()
                    ->paginate(15),
                'platformPayments' => $platformPayments,
                'platformPaymentSummary' => $this->platformPaymentSummary(),
                'platformFlutterwaveConfigured' => $this->platformGatewayIsConfigured(),
                'platformGateway' => PaymentGatewayCatalog::platformProvider(),
                'platformWebhookUrl' => route('billing.payment.webhook'),
                'platformCallbackUrl' => route('admin.billing.payments.callback'),
            ]);
        }

        $tenant = Tenant::with('currentBillingSubscription.billingPlan')->findOrFail($user->tenant_id);

        return view('admin.billing.index', [
            'plans' => BillingPlan::where('is_active', true)->orderBy('monthly_price')->get(),
            'tenant' => $tenant,
            'currentSubscription' => $tenant->currentBillingSubscription,
            'subscriptions' => $tenant->billingSubscriptions()->with('billingPlan')->latest()->paginate(15),
            'platformPayments' => PlatformBillingPayment::with(['billingPlan'])
                ->where('tenant_id', $tenant->id)
                ->latest()
                ->paginate(10, ['*'], 'payments_page'),
            'platformPaymentSummary' => $this->platformPaymentSummary($tenant->id),
            'platformFlutterwaveConfigured' => $this->platformGatewayIsConfigured(),
            'platformGateway' => PaymentGatewayCatalog::platformProvider(),
            'platformWebhookUrl' => route('billing.payment.webhook'),
            'platformCallbackUrl' => route('admin.billing.payments.callback'),
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

    public function createPlan(Request $request, BillingPlanManagementService $plans): View
    {
        $plans->assertSuperAdmin($request->user());

        return view('admin.billing.plan-form', [
            'plan' => new BillingPlan,
        ]);
    }

    public function storePlan(Request $request, BillingPlanManagementService $plans): RedirectResponse
    {
        $plans->create($plans->validated($request), $request->user());

        return redirect()->route('admin.billing.index')->with('status', 'Billing plan created.');
    }

    public function editPlan(Request $request, BillingPlan $billingPlan, BillingPlanManagementService $plans): View
    {
        $plans->assertSuperAdmin($request->user());

        return view('admin.billing.plan-form', [
            'plan' => $billingPlan,
        ]);
    }

    public function updatePlan(Request $request, BillingPlan $billingPlan, BillingPlanManagementService $plans): RedirectResponse
    {
        $plans->update($billingPlan, $plans->validated($request, $billingPlan), $request->user());

        return redirect()->route('admin.billing.index')->with('status', 'Billing plan updated.');
    }

    public function destroyPlan(Request $request, BillingPlan $billingPlan, BillingPlanManagementService $plans): RedirectResponse
    {
        try {
            $plans->delete($billingPlan, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('admin.billing.index')
                ->withErrors($exception->errors());
        }

        return redirect()->route('admin.billing.index')->with('status', 'Billing plan deleted.');
    }

    public function checkout(Request $request, PlatformHostedCheckoutManager $hostedGateways): RedirectResponse
    {
        $platformSettings = app(PlatformPaymentSettingsService::class);
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

        if (! $platformSettings->activeGatewayIsImplemented()) {
            return redirect()
                ->route('admin.billing.index')
                ->withErrors(['billing' => $platformSettings->activeGatewayName().' checkout adapter is not live yet. Choose a live platform gateway before starting checkout.']);
        }

        $gateway = $platformSettings->activeGateway();

        if (! $hostedGateways->isConfigured($gateway)) {
            return redirect()
                ->route('admin.billing.index')
                ->withErrors(['billing' => $hostedGateways->isFlutterwaveCard($gateway)
                    ? 'Card checkout needs the platform Flutterwave Secret Key (v3 card checkout). Add it under Platform Billing settings.'
                    : 'Default platform gateway credentials are not configured yet.']);
        }

        $payment = PlatformBillingPayment::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'provider' => $gateway,
            'tx_ref' => 'PBF-'.now()->format('YmdHis').'-'.str()->upper(str()->random(8)),
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'status' => 'pending',
            'payload' => [
                'started_by' => $request->user()->email,
                'plan_name' => $plan->name,
                'platform_gateway' => $gateway,
                'platform_gateway_name' => $platformSettings->activeGatewayName(),
            ],
        ]);

        $result = $hostedGateways->start($payment);

        if (filled($result['checkout_url'])) {
            return redirect()->away($result['checkout_url']);
        }

        return redirect()
            ->route('admin.billing.index')
            ->withErrors(['billing' => 'Unable to start platform billing checkout. Please try again.']);
    }

    public function callback(Request $request, PlatformBillingConfirmationService $billing): RedirectResponse
    {
        $txRef = $request->query('tx_ref') ?: $request->query('paymentReference') ?: $request->query('transaction_ref') ?: $request->query('reference');
        $payment = PlatformBillingPayment::with(['tenant', 'billingPlan'])
            ->where('tx_ref', $txRef)
            ->first();

        if (! $payment) {
            Log::warning('Platform billing callback payment lookup failed', [
                'tx_ref' => $txRef,
                'query' => $request->query(),
            ]);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Could not find the returned gateway payment reference. Please check billing history or contact support if money was debited.']);
        }

        abort_unless($request->user()->isSuperAdmin() || $request->user()->tenant_id === $payment->tenant_id, 403);

        // Monnify's own redirect convention appends paymentReference/paymentStatus,
        // never a plain "status" param -- gating on $request->query('status') for
        // Monnify would reject every successful payment outright before ever
        // reaching real API verification below, the same exclusion the hotspot-side
        // callback() already applies to Monnify/Paystack/Squad/Stripe. This list
        // previously only had Monnify/Stripe despite the comment already saying
        // otherwise -- harmless while Paystack/Squad had no platform adapter to
        // reach this code at all, but would have rejected every real callback for
        // either the moment one existed.
        if (! in_array($payment->provider, [PaymentGatewayCatalog::MONNIFY, PaymentGatewayCatalog::STRIPE, PaymentGatewayCatalog::PAYSTACK, PaymentGatewayCatalog::SQUAD], true) && ! $this->statusIsSuccessful($request->query('status'))) {
            $payment->update(['status' => $request->query('status', 'failed')]);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Platform billing payment was not successful.']);
        }

        $providerReference = $this->providerReferenceFromRequest($request) ?: $payment->provider_reference;

        if (blank($providerReference)) {
            $payment->update(['status' => 'verification_failed']);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Payment reference was missing from Flutterwave callback.']);
        }

        try {
            $confirmed = $billing->verifyAndActivate(
                $payment,
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

        if (! $confirmed) {
            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Gateway verification did not match this billing payment.']);
        }

        return redirect()->route('admin.billing.index')->with('status', 'Platform subscription payment confirmed.');
    }

    public function verify(Request $request, PlatformBillingPayment $payment, PlatformBillingConfirmationService $billing): RedirectResponse
    {
        $payment->loadMissing(['tenant', 'billingPlan']);

        abort_unless($request->user()->isSuperAdmin() || $request->user()->tenant_id === $payment->tenant_id, 403);

        if ($payment->status === 'successful' && $payment->tenant_billing_subscription_id) {
            return redirect()->route('admin.billing.index')->with('status', 'Platform payment is already confirmed.');
        }

        if (blank($payment->provider_reference)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], [
                    'manual_verification_error' => 'Provider reference is missing.',
                    'manual_verified_at' => now()->toDateTimeString(),
                    'manual_verified_by' => $request->user()->email,
                ]),
            ]);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'This billing payment has no provider reference to verify. Ask the tenant to retry checkout if money was not debited.']);
        }

        try {
            $confirmed = $billing->verifyAndActivate(
                $payment,
                (string) $payment->provider_reference,
                $this->paymentResourceType((string) $payment->provider_reference, data_get($payment->payload, 'flutterwave_resource_type'))
            );
        } catch (\Throwable $exception) {
            $payment->update([
                'payload' => array_merge($payment->payload ?? [], [
                    'manual_verification_error' => $exception->getMessage(),
                    'manual_verified_at' => now()->toDateTimeString(),
                    'manual_verified_by' => $request->user()->email,
                ]),
            ]);

            Log::warning('Platform billing manual verification failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'Could not verify this platform payment. Check the platform gateway credentials and try again.']);
        }

        if (! $confirmed) {
            return redirect()->route('admin.billing.index')->withErrors(['billing' => 'The payment gateway did not confirm this platform payment yet.']);
        }

        return redirect()->route('admin.billing.index')->with('status', 'Platform payment verified and subscription activated.');
    }

    public function webhook(Request $request, PlatformHostedCheckoutManager $hostedGateways): Response
    {
        $payload = $request->all();
        $txRef = data_get($payload, 'data.reference')
            ?: data_get($payload, 'data.tx_ref')
            ?: data_get($payload, 'data.transaction_ref')
            ?: data_get($payload, 'eventData.paymentReference')
            ?: data_get($payload, 'data.object.client_reference_id')
            ?: data_get($payload, 'data.object.metadata.payment_reference');

        if (blank($txRef)) {
            return response('ignored', 200);
        }

        $payment = PlatformBillingPayment::with(['tenant', 'billingPlan'])
            ->where('tx_ref', $txRef)
            ->first();

        if (! $payment) {
            if (! $hostedGateways->webhookIsValid($request, app(PlatformPaymentSettingsService::class)->activeGateway())) {
                abort(401);
            }

            return response('ignored', 200);
        }

        if (! $hostedGateways->webhookIsValid($request, $payment->provider)) {
            abort(401);
        }

        if ($payment->status === 'successful' && $payment->tenant_billing_subscription_id) {
            return response('ok', 200);
        }

        $providerReference = data_get($payload, 'data.id')
            ?: data_get($payload, 'data.order.id')
            ?: data_get($payload, 'data.order_id')
            ?: data_get($payload, 'data.transaction_ref')
            ?: data_get($payload, 'eventData.transactionReference')
            ?: data_get($payload, 'data.object.id')
            ?: $payment->provider_reference;

        if (blank($providerReference)) {
            return response('ignored', 200);
        }

        VerifyPlatformBillingWebhook::dispatch(
            $payment->id,
            (string) $providerReference,
            $this->paymentResourceType((string) $providerReference, data_get($payload, 'type'))
        );

        return response('ok', 200);
    }

    private function providerReferenceFromRequest(Request $request): ?string
    {
        // Deliberately no "reference" key here -- unlike the hotspot-side
        // equivalent, an existing test here already uses "reference" as the
        // tx_ref *lookup* param for a Flutterwave-style callback, which isn't
        // the provider reference to verify against. Paystack doesn't need an
        // entry here anyway: its checkout_url response already echoes back
        // the real reference (equal to tx_ref), stored as provider_reference
        // at checkout time, so the fallback below already resolves it correctly.
        foreach (['session_id', 'id', 'order_id', 'charge_id', 'transaction_id'] as $key) {
            if (filled($request->query($key))) {
                return (string) $request->query($key);
            }
        }

        return null;
    }

    private function paymentResourceType(string $providerReference, mixed $hint = null): string
    {
        if (str_starts_with(strtolower($providerReference), 'chg')) {
            return 'charge';
        }

        if (str_starts_with(strtolower($providerReference), 'ord')) {
            return 'order';
        }

        $hint = strtolower((string) $hint);

        if (str_contains($hint, 'charge')) {
            return 'charge';
        }

        return 'order';
    }

    private function statusIsSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['success', 'successful', 'succeeded', 'completed'], true);
    }

    private function platformPaymentSummary(?int $tenantId = null): array
    {
        $query = PlatformBillingPayment::query()
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId));

        return [
            'count' => (clone $query)->count(),
            'successful' => (clone $query)->where('status', 'successful')->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'failed' => (clone $query)->whereIn('status', ['failed', 'verification_failed'])->count(),
            'revenue' => (clone $query)->where('status', 'successful')->sum('amount'),
        ];
    }

    private function platformGatewayIsConfigured(): bool
    {
        $settings = app(PlatformPaymentSettingsService::class);

        if (! $settings->activeGatewayIsImplemented()) {
            return false;
        }

        return app(PlatformHostedCheckoutManager::class)->isConfigured($settings->activeGateway());
    }
}
