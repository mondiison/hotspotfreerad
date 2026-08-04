<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Shop;
use App\Support\PaymentGatewayCatalog;

class ManualBankTransferService
{
    public function isConfiguredFor(Shop|Payment $model): bool
    {
        $settings = $this->settings($model);

        return filled($settings['bank_name'] ?? null)
            && filled($settings['account_name'] ?? null)
            && filled($settings['account_number'] ?? null);
    }

    public function transferDetails(Shop|Payment $model): array
    {
        $settings = $this->settings($model);

        if ($model instanceof Payment) {
            $settings = array_merge($settings, (array) data_get($model->payload, 'manual_bank_transfer', []));
        }

        return [
            'bank_name' => $settings['bank_name'] ?? null,
            'account_name' => $settings['account_name'] ?? null,
            'account_number' => $settings['account_number'] ?? null,
            'instructions' => $settings['instructions'] ?? 'Use the transaction reference as your transfer narration, then wait for the hotspot operator to confirm your payment.',
        ];
    }

    private function settings(Shop|Payment $model): array
    {
        $shop = $model instanceof Payment ? $model->shop : $model;

        return (array) ($shop?->paymentGatewaySettings()[PaymentGatewayCatalog::MANUAL_BANK] ?? []);
    }
}
