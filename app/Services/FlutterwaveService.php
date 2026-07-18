<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class FlutterwaveService
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(Payment $payment, array $customer, string $redirectUrl): string
    {
        $response = Http::withToken((string) config('services.flutterwave.secret_key'))
            ->acceptJson()
            ->post(rtrim((string) config('services.flutterwave.base_url'), '/').'/payments', [
                'tx_ref' => $payment->tx_ref,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'redirect_url' => $redirectUrl,
                'customer' => [
                    'email' => $customer['email'] ?: 'guest-'.$payment->id.'@hotspot.local',
                    'phonenumber' => $customer['phone'] ?? null,
                    'name' => $customer['name'] ?? 'Hotspot Customer',
                ],
                'customizations' => [
                    'title' => $payment->shop->tenant->company_name,
                    'description' => $payment->package->name,
                    'logo' => null,
                ],
            ])
            ->throw()
            ->json();

        return (string) data_get($response, 'data.link');
    }

    /**
     * @throws RequestException
     */
    public function verifyTransaction(string $transactionId): array
    {
        return Http::withToken((string) config('services.flutterwave.secret_key'))
            ->acceptJson()
            ->get(rtrim((string) config('services.flutterwave.base_url'), '/')."/transactions/{$transactionId}/verify")
            ->throw()
            ->json();
    }

    public function webhookIsValid(?string $signature): bool
    {
        $secretHash = config('services.flutterwave.webhook_secret_hash');

        return filled($secretHash) && hash_equals((string) $secretHash, (string) $signature);
    }
}
