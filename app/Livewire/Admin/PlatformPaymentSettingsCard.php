<?php

namespace App\Livewire\Admin;

use App\Services\PlatformPaymentSettingsService;
use App\Services\SecurityActivityService;
use App\Support\PaymentGatewayCatalog;
use Livewire\Component;

class PlatformPaymentSettingsCard extends Component
{
    public string $active_gateway = 'flutterwave';

    public array $gateway_settings = [];

    public string $default_payment_method = 'opay';

    public bool $clear_gateway_credentials = false;

    public ?string $savedMessage = null;

    public function mount(PlatformPaymentSettingsService $settings): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $this->active_gateway = $settings->activeGateway();
        $this->default_payment_method = $settings->defaultPaymentMethod();
        $this->gateway_settings = $this->defaultGatewaySettings($settings);
    }

    public function updatedActiveGateway(PlatformPaymentSettingsService $settings): void
    {
        $this->gateway_settings = $this->defaultGatewaySettings($settings);
        $this->clear_gateway_credentials = false;
    }

    public function save(PlatformPaymentSettingsService $settings, SecurityActivityService $activity): void
    {
        $data = $this->validate($settings->rules());

        $settings->update($data, auth()->user());

        $this->reset(['gateway_settings', 'clear_gateway_credentials']);
        $this->active_gateway = $settings->activeGateway();
        $this->default_payment_method = $settings->defaultPaymentMethod();
        $this->gateway_settings = $this->defaultGatewaySettings($settings);
        $this->savedMessage = 'Platform payment settings updated.';
        session()->flash('status', $this->savedMessage);

        $activity->log(auth()->user(), 'platform_payment_settings_updated', 'Platform payment settings updated.', [
            'active_gateway' => $this->active_gateway,
            'default_payment_method' => $this->default_payment_method,
            'credentials_cleared' => (bool) ($data['clear_gateway_credentials'] ?? false),
        ]);
    }

    /**
     * Same reasoning as PaymentSettingsCard::defaultGatewaySettings() --
     * secret/credential fields always start blank (never re-displayed once
     * saved), but a select field like Environment needs pre-filling with
     * whatever is currently saved so the dropdown isn't confusingly blank.
     */
    private function defaultGatewaySettings(PlatformPaymentSettingsService $settings): array
    {
        $selectFields = PaymentGatewayCatalog::selectFields($this->active_gateway);

        if ($selectFields === []) {
            return [];
        }

        $currentSettings = $settings->gatewaySettings($this->active_gateway);

        return collect($selectFields)
            ->mapWithKeys(fn (array $options, string $key): array => [
                $key => $currentSettings[$key] ?? (string) array_key_first($options),
            ])
            ->all();
    }

    public function render(PlatformPaymentSettingsService $settings)
    {
        return view('livewire.admin.platform-payment-settings-card', [
            'snapshot' => $settings->snapshot(),
            'readiness' => PaymentGatewayCatalog::platformReadiness($this->active_gateway),
            'gatewayCards' => PaymentGatewayCatalog::gatewayCards(),
            'activeGateway' => PaymentGatewayCatalog::gateway($this->active_gateway),
            'credentialFields' => PaymentGatewayCatalog::platformCredentialFields($this->active_gateway),
            'secretFieldKeys' => PaymentGatewayCatalog::secretFieldKeys($this->active_gateway),
            'selectFields' => PaymentGatewayCatalog::selectFields($this->active_gateway),
            'hasStoredCredentials' => $settings->hasStoredCredentials($this->active_gateway),
        ]);
    }
}
