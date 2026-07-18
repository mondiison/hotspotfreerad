<?php

namespace App\Http\Controllers\Hotspot;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\FlutterwaveService;
use App\Services\RadiusProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PortalController extends Controller
{
    private const TEST_ACCESS_PASSWORD = 'authenticated_device_pass';

    public function show(Request $request): View
    {
        $validated = $request->validate([
            'mac' => ['nullable', 'string', 'max:64'],
            'nasid' => ['nullable', 'string', 'max:255'],
            'link-login' => ['nullable', 'string', 'max:2048'],
            'link-orig' => ['nullable', 'string', 'max:2048'],
        ]);

        if (blank($validated['mac'] ?? null) || blank($validated['nasid'] ?? null)) {
            return view('hotspot.missing-parameters', [
                'macAddress' => $validated['mac'] ?? null,
                'nasIdentifier' => $validated['nasid'] ?? null,
            ]);
        }

        $router = Router::query()
            ->with(['shop.tenant', 'shop.packages' => fn ($query) => $query->where('is_active', true)->orderBy('price')])
            ->where('nas_identifier', $validated['nasid'])
            ->first();

        if (! $router) {
            return view('hotspot.unknown-router', [
                'macAddress' => $validated['mac'],
                'nasIdentifier' => $validated['nasid'],
            ]);
        }

        return view('hotspot.portal', [
            'router' => $router,
            'shop' => $router->shop,
            'packages' => $router->shop->packages,
            'macAddress' => $validated['mac'],
            'loginUrl' => $validated['link-login'] ?? null,
            'originalUrl' => $validated['link-orig'] ?? null,
        ]);
    }

    public function grant(Request $request, RadiusProvisioningService $radius): RedirectResponse|View
    {
        $validated = $request->validate([
            'mac' => ['required', 'string', 'max:64'],
            'nasid' => ['required', 'string', 'max:255'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'link-login' => ['nullable', 'string', 'max:2048'],
            'link-orig' => ['nullable', 'string', 'max:2048'],
        ]);

        $router = Router::query()
            ->with('shop.tenant')
            ->where('nas_identifier', $validated['nasid'])
            ->first();

        if (! $router) {
            return view('hotspot.unknown-router', [
                'macAddress' => $validated['mac'],
                'nasIdentifier' => $validated['nasid'],
            ]);
        }

        $package = Package::query()
            ->where('shop_id', $router->shop_id)
            ->where('is_active', true)
            ->findOrFail($validated['package_id']);

        $subscription = DB::transaction(function () use ($router, $package, $validated, $radius) {
            Customer::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $validated['mac'],
                ],
                []
            );

            $subscription = Subscription::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $validated['mac'],
                ],
                [
                    'package_id' => $package->id,
                    'starts_at' => now(),
                    'expires_at' => now()->addSeconds($package->limit_uptime_seconds),
                    'is_throttled' => false,
                ]
            );

            $radius->grantSubscriptionAccess($subscription, self::TEST_ACCESS_PASSWORD);

            return $subscription;
        });

        return view('hotspot.access-granted', [
            'router' => $router,
            'package' => $package,
            'subscription' => $subscription,
            'macAddress' => $validated['mac'],
            'username' => $validated['mac'],
            'password' => self::TEST_ACCESS_PASSWORD,
            'loginUrl' => $validated['link-login'] ?? null,
            'originalUrl' => $validated['link-orig'] ?? null,
        ]);
    }

    public function pay(Request $request, FlutterwaveService $flutterwave): RedirectResponse|View
    {
        $validated = $request->validate([
            'mac' => ['required', 'string', 'max:64'],
            'nasid' => ['required', 'string', 'max:255'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'link-login' => ['nullable', 'string', 'max:2048'],
            'link-orig' => ['nullable', 'string', 'max:2048'],
        ]);

        $router = Router::query()
            ->with('shop.tenant')
            ->where('nas_identifier', $validated['nasid'])
            ->first();

        if (! $router) {
            return view('hotspot.unknown-router', [
                'macAddress' => $validated['mac'],
                'nasIdentifier' => $validated['nasid'],
            ]);
        }

        $package = Package::query()
            ->where('shop_id', $router->shop_id)
            ->where('is_active', true)
            ->findOrFail($validated['package_id']);

        $payment = DB::transaction(function () use ($router, $package, $validated) {
            $customer = Customer::updateOrCreate(
                [
                    'shop_id' => $router->shop_id,
                    'mac_address' => $validated['mac'],
                ],
                [
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                ]
            );

            return Payment::create([
                'shop_id' => $router->shop_id,
                'package_id' => $package->id,
                'customer_id' => $customer->id,
                'provider' => 'flutterwave',
                'tx_ref' => 'HSF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
                'amount' => $package->price,
                'currency' => $package->currency,
                'status' => 'pending',
                'payload' => [
                    'mac' => $validated['mac'],
                    'nasid' => $validated['nasid'],
                    'link_login' => $validated['link-login'] ?? null,
                    'link_orig' => $validated['link-orig'] ?? null,
                ],
            ]);
        });

        $payment->load(['shop.tenant', 'package', 'customer']);
        $credentialSource = $flutterwave->credentialSource($payment);

        if ($flutterwave->isConfiguredFor($payment)) {
            try {
                $checkout = $flutterwave->initializeCheckout(
                    $payment,
                    [
                        'email' => $validated['email'] ?? null,
                        'phone' => $validated['phone'] ?? null,
                        'name' => 'Hotspot Customer',
                    ],
                    route('hotspot.payment.callback')
                );

                $payment->update([
                    'provider_reference' => $checkout['provider_reference'],
                    'payload' => array_merge($payment->payload ?? [], [
                        'checkout_url' => $checkout['checkout_url'],
                        'flutterwave_account' => $credentialSource,
                        'flutterwave_init_response' => $checkout['response'],
                    ]),
                ]);

                if (filled($checkout['checkout_url'])) {
                    return redirect()->away($checkout['checkout_url']);
                }
            } catch (\Throwable $exception) {
                Log::warning('Flutterwave checkout initialization failed', [
                    'payment_id' => $payment->id,
                    'tx_ref' => $payment->tx_ref,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return view('hotspot.checkout', [
            'router' => $router,
            'shop' => $router->shop,
            'package' => $package,
            'payment' => $payment,
            'macAddress' => $validated['mac'],
            'loginUrl' => $validated['link-login'] ?? null,
            'originalUrl' => $validated['link-orig'] ?? null,
        ]);
    }

    public function callback(Request $request, FlutterwaveService $flutterwave, RadiusProvisioningService $radius): View
    {
        $txRef = $request->query('tx_ref') ?: $request->query('reference');

        $payment = Payment::with(['shop.tenant', 'package'])
            ->where('tx_ref', $txRef)
            ->firstOrFail();

        if (! $this->statusIsSuccessful($request->query('status'))) {
            $payment->update(['status' => $request->query('status', 'failed')]);

            return view('hotspot.payment-failed', compact('payment'));
        }

        $providerReference = $this->providerReferenceFromRequest($request) ?: $payment->provider_reference;

        if (blank($providerReference)) {
            $payment->update(['status' => 'verification_failed']);

            return view('hotspot.payment-failed', compact('payment'));
        }

        try {
            $verification = $flutterwave->verifyPayment(
                $payment,
                (string) $providerReference,
                $this->paymentResourceType((string) $providerReference, $request->query('type'))
            );
        } catch (\Throwable $exception) {
            Log::warning('Flutterwave callback verification failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
            ]);

            return view('hotspot.payment-failed', compact('payment'));
        }

        if (! $this->verificationMatchesPayment($verification, $payment)) {
            $payment->update([
                'status' => 'verification_failed',
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            return view('hotspot.payment-failed', compact('payment'));
        }

        $subscription = $this->markPaidAndGrantAccess($payment, $verification, $radius);

        return view('hotspot.access-granted', [
            'router' => Router::with('shop.tenant')->where('shop_id', $payment->shop_id)->first(),
            'package' => $payment->package,
            'subscription' => $subscription,
            'macAddress' => $payment->payload['mac'],
            'username' => $payment->payload['mac'],
            'password' => self::TEST_ACCESS_PASSWORD,
            'loginUrl' => $payment->payload['link_login'] ?? null,
            'originalUrl' => $payment->payload['link_orig'] ?? null,
        ]);
    }

    public function webhook(Request $request, FlutterwaveService $flutterwave, RadiusProvisioningService $radius): \Illuminate\Http\Response
    {
        $payload = $request->all();
        $txRef = data_get($payload, 'data.reference') ?: data_get($payload, 'data.tx_ref');

        if (blank($txRef)) {
            return response('ignored', 200);
        }

        $payment = Payment::with(['shop.tenant', 'package'])->where('tx_ref', $txRef)->first();

        if (! $payment) {
            return response('ignored', 200);
        }

        if (! $flutterwave->webhookIsValid($request->header('verif-hash'), $payment)) {
            abort(401);
        }

        $providerReference = data_get($payload, 'data.id')
            ?: data_get($payload, 'data.order.id')
            ?: data_get($payload, 'data.order_id')
            ?: $payment->provider_reference;

        if (blank($providerReference)) {
            return response('ignored', 200);
        }

        try {
            $verification = $flutterwave->verifyPayment(
                $payment,
                (string) $providerReference,
                $this->paymentResourceType((string) $providerReference, data_get($payload, 'event'))
            );
        } catch (\Throwable $exception) {
            Log::warning('Flutterwave webhook verification failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
            ]);

            return response('ok', 200);
        }

        if ($this->verificationMatchesPayment($verification, $payment)) {
            $this->markPaidAndGrantAccess($payment, $verification, $radius);
        }

        return response('ok', 200);
    }

    private function verificationMatchesPayment(array $verification, Payment $payment): bool
    {
        return in_array(strtolower((string) data_get($verification, 'status')), ['success', 'successful', 'succeeded'], true)
            && $this->statusIsSuccessful(data_get($verification, 'data.status'))
            && (data_get($verification, 'data.reference') === $payment->tx_ref || data_get($verification, 'data.tx_ref') === $payment->tx_ref)
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($payment->currency)
            && (float) data_get($verification, 'data.amount') >= (float) $payment->amount;
    }

    private function markPaidAndGrantAccess(Payment $payment, array $verification, RadiusProvisioningService $radius): Subscription
    {
        return DB::transaction(function () use ($payment, $verification, $radius) {
            $payment->update([
                'status' => 'successful',
                'provider_reference' => (string) (data_get($verification, 'data.id') ?: $payment->provider_reference),
                'paid_at' => now(),
                'payload' => array_merge($payment->payload ?? [], ['verification' => $verification]),
            ]);

            $subscription = Subscription::updateOrCreate(
                [
                    'shop_id' => $payment->shop_id,
                    'mac_address' => $payment->payload['mac'],
                ],
                [
                    'package_id' => $payment->package_id,
                    'payment_id' => $payment->id,
                    'starts_at' => now(),
                    'expires_at' => now()->addSeconds($payment->package->limit_uptime_seconds),
                    'is_throttled' => false,
                ]
            );

            $radius->grantSubscriptionAccess($subscription, self::TEST_ACCESS_PASSWORD);

            return $subscription;
        });
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
