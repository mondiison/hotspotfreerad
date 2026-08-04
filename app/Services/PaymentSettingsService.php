<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\User;
use App\Support\PaymentGatewayCatalog;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentSettingsService
{
    public function rules(): array
    {
        return [
            'payment_gateway' => ['nullable', 'string', Rule::in(array_keys(PaymentGatewayCatalog::onlineGateways()))],
            'flutterwave_client_id' => ['nullable', 'string', 'required_with:flutterwave_client_secret'],
            'flutterwave_client_secret' => ['nullable', 'string', 'required_with:flutterwave_client_id'],
            'flutterwave_secret_key' => ['nullable', 'string'],
            'flutterwave_webhook_secret' => ['nullable', 'string'],
            'clear_flutterwave_credentials' => ['nullable', 'boolean'],
            'clear_flutterwave_secret_key' => ['nullable', 'boolean'],
            'clear_flutterwave_webhook_secret' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request): array
    {
        return $request->validate($this->rules());
    }

    public function update(Shop $shop, array $data, User $user): bool
    {
        TenantAccess::assertShop($shop, $user);

        $updates = $this->updates($data);

        if ($updates === []) {
            return false;
        }

        return $shop->update($updates);
    }

    private function updates(array $data): array
    {
        $updates = [
            'payment_gateway' => $data['payment_gateway'] ?? PaymentGatewayCatalog::FLUTTERWAVE,
        ];

        if ((bool) ($data['clear_flutterwave_credentials'] ?? false)) {
            $updates['flutterwave_client_id'] = null;
            $updates['flutterwave_client_secret'] = null;
        } elseif (filled($data['flutterwave_client_id'] ?? null) && filled($data['flutterwave_client_secret'] ?? null)) {
            $updates['flutterwave_client_id'] = $data['flutterwave_client_id'];
            $updates['flutterwave_client_secret'] = $data['flutterwave_client_secret'];
        }

        if ((bool) ($data['clear_flutterwave_secret_key'] ?? false)) {
            $updates['flutterwave_secret_key'] = null;
        } elseif (filled($data['flutterwave_secret_key'] ?? null)) {
            $updates['flutterwave_secret_key'] = $data['flutterwave_secret_key'];
        }

        if ((bool) ($data['clear_flutterwave_webhook_secret'] ?? false)) {
            $updates['flutterwave_webhook_secret'] = null;
        } elseif (filled($data['flutterwave_webhook_secret'] ?? null)) {
            $updates['flutterwave_webhook_secret'] = $data['flutterwave_webhook_secret'];
        }

        return $updates;
    }
}
