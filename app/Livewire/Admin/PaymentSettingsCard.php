<?php

namespace App\Livewire\Admin;

use App\Models\Shop;
use App\Services\PaymentSettingsService;
use App\Support\PaymentGatewayCatalog;
use Livewire\Component;

class PaymentSettingsCard extends Component
{
    public Shop $shop;

    public string $payment_gateway = 'flutterwave';

    public array $gateway_settings = [];

    public string $flutterwave_client_id = '';

    public string $flutterwave_client_secret = '';

    public string $flutterwave_secret_key = '';

    public string $flutterwave_webhook_secret = '';

    public bool $clear_flutterwave_credentials = false;

    public bool $clear_flutterwave_secret_key = false;

    public bool $clear_flutterwave_webhook_secret = false;

    public ?string $savedMessage = null;

    public function mount(Shop $shop): void
    {
        $this->shop = $shop;
        $this->payment_gateway = $shop->paymentGateway();
        $this->gateway_settings = $this->defaultGatewaySettings();
    }

    public function updatedPaymentGateway(): void
    {
        $this->gateway_settings = $this->defaultGatewaySettings();
    }

    public function save(PaymentSettingsService $settings): void
    {
        $data = $this->validate($settings->rules());

        $settings->update($this->shop, $data, auth()->user());

        $this->shop->refresh();
        $this->reset([
            'flutterwave_client_id',
            'flutterwave_client_secret',
            'flutterwave_secret_key',
            'flutterwave_webhook_secret',
            'gateway_settings',
            'clear_flutterwave_credentials',
            'clear_flutterwave_secret_key',
            'clear_flutterwave_webhook_secret',
        ]);
        $this->payment_gateway = $this->shop->paymentGateway();
        $this->gateway_settings = $this->defaultGatewaySettings();

        $this->savedMessage = 'Payment settings updated for '.$this->shop->name.'.';

        session()->flash('status', 'Payment settings updated for '.$this->shop->name.'.');
    }

    /**
     * Secret/credential fields always start blank (never re-displayed once
     * saved), but select fields like Environment aren't secret and a blank
     * dropdown is confusing UX -- pre-fill those with whatever is currently
     * saved (defaulting to each field's first option, e.g. "test", for a
     * gateway that's never had one saved yet).
     */
    private function defaultGatewaySettings(): array
    {
        $selectFields = PaymentGatewayCatalog::selectFields($this->payment_gateway);

        if ($selectFields === []) {
            return [];
        }

        $currentSettings = (array) ($this->shop->paymentGatewaySettings()[$this->payment_gateway] ?? []);

        return collect($selectFields)
            ->mapWithKeys(fn (array $options, string $key): array => [
                $key => $currentSettings[$key] ?? (string) array_key_first($options),
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.admin.payment-settings-card', [
            'readiness' => PaymentGatewayCatalog::tenantReadiness($this->shop),
            'gatewayOptions' => PaymentGatewayCatalog::gatewayOptions(),
            'gatewayCards' => PaymentGatewayCatalog::gatewayCards(),
            'activeGateway' => PaymentGatewayCatalog::gateway($this->payment_gateway),
            'credentialFields' => PaymentGatewayCatalog::credentialFields($this->payment_gateway),
            'secretFieldKeys' => PaymentGatewayCatalog::secretFieldKeys($this->payment_gateway),
            'selectFields' => PaymentGatewayCatalog::selectFields($this->payment_gateway),
        ]);
    }
}
