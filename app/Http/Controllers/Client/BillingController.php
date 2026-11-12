<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientBillingPayment;
use App\Services\ClientBillingConfirmationService;
use App\Services\Payments\ClientHostedCheckoutManager;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class BillingController extends Controller
{
    /**
     * Client-to-Tenant checkout is scoped to Monnify/Paystack/Squad/Stripe
     * only -- Flutterwave has no tenant-level credential storage yet.
     */
    private const SUPPORTED_GATEWAYS = [
        PaymentGatewayCatalog::MONNIFY,
        PaymentGatewayCatalog::PAYSTACK,
        PaymentGatewayCatalog::SQUAD,
        PaymentGatewayCatalog::STRIPE,
    ];

    public function index(Request $request): View
    {
        $client = $request->user()->client->load(['tenant', 'currentBillingSubscription.clientBillingPlan']);

        return view('client.billing', [
            'client' => $client,
            'gateway' => $client->tenant->client_checkout_gateway,
        ]);
    }

    public function checkout(Request $request, ClientHostedCheckoutManager $hostedGateways): RedirectResponse
    {
        $client = $request->user()->client->load(['tenant', 'currentBillingSubscription.clientBillingPlan']);
        $tenant = $client->tenant;
        $subscription = $client->currentBillingSubscription;
        $plan = $subscription?->clientBillingPlan;
        $gateway = $tenant->client_checkout_gateway;

        if (! $plan) {
            return redirect()->route('client.billing.index')->withErrors(['billing' => 'No billing plan is attached to your account. Contact your provider.']);
        }

        if (! in_array($gateway, self::SUPPORTED_GATEWAYS, true)) {
            return redirect()->route('client.billing.index')->withErrors(['billing' => 'Your provider has not configured a payment method yet. Contact them directly.']);
        }

        $payment = ClientBillingPayment::create([
            'client_id' => $client->id,
            'client_billing_plan_id' => $plan->id,
            'provider' => $gateway,
            'tx_ref' => 'CBF-'.now()->format('YmdHis').'-'.str()->upper(str()->random(8)),
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'status' => 'pending',
            'payload' => [
                'started_by' => $request->user()->email,
                'plan_name' => $plan->name,
            ],
        ]);

        if (! $hostedGateways->isConfigured($payment, $gateway)) {
            return redirect()->route('client.billing.index')->withErrors(['billing' => 'Your provider\'s payment credentials are not configured yet. Contact them directly.']);
        }

        $result = $hostedGateways->start($payment);

        if (filled($result['checkout_url'])) {
            return redirect()->away($result['checkout_url']);
        }

        return redirect()->route('client.billing.index')->withErrors(['billing' => 'Unable to start checkout. Please try again.']);
    }

    public function callback(Request $request, ClientBillingConfirmationService $billing): RedirectResponse
    {
        $txRef = $request->query('tx_ref') ?: $request->query('paymentReference') ?: $request->query('transaction_ref') ?: $request->query('reference');
        $payment = ClientBillingPayment::with(['client.tenant', 'clientBillingPlan'])
            ->where('tx_ref', $txRef)
            ->first();

        if (! $payment) {
            Log::warning('Client billing callback payment lookup failed', ['tx_ref' => $txRef, 'query' => $request->query()]);

            return redirect()->route('client.billing.index')->withErrors(['billing' => 'Could not find the returned payment reference. Contact support if money was debited.']);
        }

        abort_unless($request->user()->client_id === $payment->client_id, 403);

        // Same Monnify/Paystack/Squad/Stripe exclusion as every other
        // callback in this codebase -- none of them send a plain "status"
        // query param, so gating on it would reject every real success
        // before ever reaching verification.
        if (! in_array($payment->provider, self::SUPPORTED_GATEWAYS, true)) {
            $payment->update(['status' => 'failed']);

            return redirect()->route('client.billing.index')->withErrors(['billing' => 'Payment was not successful.']);
        }

        $providerReference = $this->providerReferenceFromRequest($request) ?: $payment->provider_reference;

        if (blank($providerReference)) {
            $payment->update(['status' => 'verification_failed']);

            return redirect()->route('client.billing.index')->withErrors(['billing' => 'Payment reference was missing from the gateway callback.']);
        }

        try {
            $confirmed = $billing->verifyAndActivate($payment, (string) $providerReference);
        } catch (\Throwable $exception) {
            Log::warning('Client billing callback verification failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('client.billing.index')->withErrors(['billing' => 'Could not verify payment.']);
        }

        if (! $confirmed) {
            return redirect()->route('client.billing.index')->withErrors(['billing' => 'Gateway verification did not match this payment.']);
        }

        return redirect()->route('client.billing.index')->with('status', 'Payment confirmed. Your subscription is active.');
    }

    public function webhook(Request $request, ClientBillingConfirmationService $billing, ClientHostedCheckoutManager $hostedGateways): Response
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

        $payment = ClientBillingPayment::with(['client.tenant', 'clientBillingPlan'])->where('tx_ref', $txRef)->first();

        if (! $payment || ! $hostedGateways->webhookIsValid($payment, $request)) {
            abort(401);
        }

        if ($payment->status === 'successful' && $payment->client_billing_subscription_id) {
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

        try {
            $billing->verifyAndActivate($payment, (string) $providerReference);
        } catch (\Throwable $exception) {
            Log::warning('Client billing webhook verification failed', [
                'payment_id' => $payment->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return response('ok', 200);
    }

    private function providerReferenceFromRequest(Request $request): ?string
    {
        foreach (['session_id', 'id', 'order_id', 'charge_id', 'transaction_id'] as $key) {
            if (filled($request->query($key))) {
                return (string) $request->query($key);
            }
        }

        return null;
    }
}
