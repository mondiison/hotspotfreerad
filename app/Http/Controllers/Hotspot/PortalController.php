<?php

namespace App\Http\Controllers\Hotspot;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyHotspotPaymentWebhook;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\FlutterwaveService;
use App\Services\HotspotPaymentConfirmationService;
use App\Services\RadiusProvisioningService;
use App\Support\PaymentCommission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

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
                ...PaymentCommission::forShop($router->shop, (float) $package->price),
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
        $checkoutUnavailableReason = 'missing_credentials';

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
                $checkoutUnavailableReason = 'missing_checkout_url';
            } catch (Throwable $exception) {
                $checkoutUnavailableReason = 'initialization_failed';

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
            'credentialSource' => $credentialSource,
            'checkoutUnavailableReason' => $checkoutUnavailableReason,
            'macAddress' => $validated['mac'],
            'loginUrl' => $validated['link-login'] ?? null,
            'originalUrl' => $validated['link-orig'] ?? null,
        ]);
    }

    public function callback(Request $request, HotspotPaymentConfirmationService $payments): View
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
            $subscription = $payments->verifyAndGrant(
                $payment,
                (string) $providerReference,
                $this->paymentResourceType((string) $providerReference, $request->query('type'))
            );
        } catch (Throwable $exception) {
            Log::warning('Flutterwave callback verification failed', [
                'payment_id' => $payment->id,
                'tx_ref' => $payment->tx_ref,
                'message' => $exception->getMessage(),
            ]);

            return view('hotspot.payment-failed', compact('payment'));
        }

        if (! $subscription) {
            return view('hotspot.payment-failed', compact('payment'));
        }

        $payment->refresh();

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

    public function webhook(Request $request, FlutterwaveService $flutterwave): Response
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

        VerifyHotspotPaymentWebhook::dispatch(
            $payment->id,
            (string) $providerReference,
            $this->paymentResourceType((string) $providerReference, data_get($payload, 'event'))
        );

        return response('ok', 200);
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
