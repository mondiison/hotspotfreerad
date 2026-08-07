<?php

namespace App\Services;

use App\Jobs\SyncRouterWalledGarden;
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
            'gateway_settings' => ['nullable', 'array'],
            'gateway_settings.*' => ['nullable', 'string', 'max:1000'],
            'gateway_settings.environment' => ['nullable', Rule::in(['live', 'test'])],
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
        $shop->loadMissing('tenant');

        $previousGateway = $shop->payment_gateway;
        $updates = $this->updates($shop, $data);

        $tenantUpdates = $updates['tenant'] ?? [];
        unset($updates['tenant']);

        $shopUpdated = $updates === [] ? false : $shop->update($updates);

        if ($tenantUpdates !== [] && $shop->tenant) {
            $shop->tenant->update($tenantUpdates);
            $shopUpdated = true;
        }

        // The new gateway's hosted-checkout host has to be walled-garden'd on every
        // router this shop owns, or customers hit a dead connection at checkout the
        // moment they pick a package -- see the RouterOsConnectionService::syncWalledGarden()
        // note for why HTTPS destinations fail closed rather than redirecting. Queued
        // (not synchronous) since this can touch multiple routers over the network and
        // shouldn't make the settings save wait on router reachability.
        if (array_key_exists('payment_gateway', $updates) && $updates['payment_gateway'] !== $previousGateway) {
            $shop->routers()->pluck('id')->each(
                fn (int $routerId) => SyncRouterWalledGarden::dispatch($routerId)
            );
        }

        return $shopUpdated;
    }

    private function updates(Shop $shop, array $data): array
    {
        $gateway = $data['payment_gateway'] ?? PaymentGatewayCatalog::FLUTTERWAVE;
        $gatewaySettings = $this->cleanGatewaySettings($gateway, (array) ($data['gateway_settings'] ?? []));

        $updates = [
            'payment_gateway' => $gateway,
        ];

        if ($gatewaySettings !== []) {
            $allSettings = (array) ($shop->tenant?->payment_gateway_settings ?? []);
            // Merge into (not replace) this gateway's existing settings -- a field left
            // blank means "keep the saved value" per the form's own placeholder text,
            // not "delete it." Replacing wholesale would wipe e.g. a saved secret key
            // every time an unrelated field (like Environment) is the only thing changed.
            $allSettings[$gateway] = array_merge((array) ($allSettings[$gateway] ?? []), $gatewaySettings);
            $updates['tenant'] = ['payment_gateway_settings' => $allSettings];
        }

        if ($gateway === PaymentGatewayCatalog::FLUTTERWAVE) {
            $data['flutterwave_client_id'] = $data['flutterwave_client_id'] ?? ($gatewaySettings['client_id'] ?? null);
            $data['flutterwave_client_secret'] = $data['flutterwave_client_secret'] ?? ($gatewaySettings['client_secret'] ?? null);
            $data['flutterwave_secret_key'] = $data['flutterwave_secret_key'] ?? ($gatewaySettings['secret_key'] ?? null);
            $data['flutterwave_webhook_secret'] = $data['flutterwave_webhook_secret'] ?? ($gatewaySettings['webhook_secret'] ?? null);
        }

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

    private function cleanGatewaySettings(string $gateway, array $settings): array
    {
        $allowedFields = array_keys(PaymentGatewayCatalog::credentialFields($gateway));

        return collect($settings)
            ->only($allowedFields)
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->all();
    }
}
