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
            ->with('shop')
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

        if (filled(config('services.flutterwave.secret_key'))) {
            try {
                $checkoutUrl = $flutterwave->initializeCheckout(
                    $payment->load(['shop.tenant', 'package', 'customer']),
                    [
                        'email' => $validated['email'] ?? null,
                        'phone' => $validated['phone'] ?? null,
                        'name' => 'Hotspot Customer',
                    ],
                    route('hotspot.payment.callback')
                );

                $payment->update([
                    'payload' => array_merge($payment->payload ?? [], ['checkout_url' => $checkoutUrl]),
                ]);

                return redirect()->away($checkoutUrl);
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
        $payment = Payment::with(['shop.tenant', 'package'])
            ->where('tx_ref', $request->query('tx_ref'))
            ->firstOrFail();

        if ($request->query('status') !== 'successful' || blank($request->query('transaction_id'))) {
            $payment->update(['status' => $request->query('status', 'failed')]);

            return view('hotspot.payment-failed', compact('payment'));
        }

        try {
            $verification = $flutterwave->verifyTransaction((string) $request->query('transaction_id'));
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
            'router' => Router::where('shop_id', $payment->shop_id)->first(),
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
        if (! $flutterwave->webhookIsValid($request->header('verif-hash'))) {
            abort(401);
        }

        $payload = $request->all();
        $txRef = data_get($payload, 'data.tx_ref');
        $transactionId = data_get($payload, 'data.id');

        if (blank($txRef) || blank($transactionId)) {
            return response('ignored', 200);
        }

        $payment = Payment::with(['shop.tenant', 'package'])->where('tx_ref', $txRef)->first();

        if (! $payment) {
            return response('ignored', 200);
        }

        $verification = $flutterwave->verifyTransaction((string) $transactionId);

        if ($this->verificationMatchesPayment($verification, $payment)) {
            $this->markPaidAndGrantAccess($payment, $verification, $radius);
        }

        return response('ok', 200);
    }

    private function verificationMatchesPayment(array $verification, Payment $payment): bool
    {
        return data_get($verification, 'status') === 'success'
            && data_get($verification, 'data.status') === 'successful'
            && data_get($verification, 'data.tx_ref') === $payment->tx_ref
            && strtoupper((string) data_get($verification, 'data.currency')) === strtoupper($payment->currency)
            && (float) data_get($verification, 'data.amount') >= (float) $payment->amount;
    }

    private function markPaidAndGrantAccess(Payment $payment, array $verification, RadiusProvisioningService $radius): Subscription
    {
        return DB::transaction(function () use ($payment, $verification, $radius) {
            $payment->update([
                'status' => 'successful',
                'provider_reference' => (string) data_get($verification, 'data.id'),
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
}
