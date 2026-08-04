<?php

namespace App\Livewire\Admin;

use App\Services\PlatformPaymentSettingsService;
use App\Services\SecurityActivityService;
use App\Support\PaymentGatewayCatalog;
use Livewire\Component;

class PlatformPaymentSettingsCard extends Component
{
    public string $client_id = '';

    public string $client_secret = '';

    public string $webhook_secret_hash = '';

    public string $active_gateway = 'flutterwave';

    public string $default_payment_method = 'opay';

    public bool $clear_client_credentials = false;

    public bool $clear_webhook_secret = false;

    public ?string $savedMessage = null;

    public function mount(PlatformPaymentSettingsService $settings): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $this->active_gateway = $settings->activeGateway();
        $this->default_payment_method = $settings->defaultPaymentMethod();
    }

    public function save(PlatformPaymentSettingsService $settings, SecurityActivityService $activity): void
    {
        $data = $this->validate($settings->rules());

        $settings->update($data, auth()->user());

        $this->reset([
            'client_id',
            'client_secret',
            'webhook_secret_hash',
            'clear_client_credentials',
            'clear_webhook_secret',
        ]);
        $this->active_gateway = $settings->activeGateway();
        $this->default_payment_method = $settings->defaultPaymentMethod();
        $this->savedMessage = 'Platform payment settings updated.';
        session()->flash('status', $this->savedMessage);

        $activity->log(auth()->user(), 'platform_payment_settings_updated', 'Platform payment settings updated.', [
            'client_credentials_updated' => filled($data['client_id'] ?? null) && filled($data['client_secret'] ?? null),
            'webhook_secret_updated' => filled($data['webhook_secret_hash'] ?? null),
            'active_gateway' => $this->active_gateway,
            'default_payment_method' => $this->default_payment_method,
        ]);
    }

    public function render(PlatformPaymentSettingsService $settings)
    {
        return view('livewire.admin.platform-payment-settings-card', [
            'snapshot' => $settings->snapshot(),
            'gatewayCards' => PaymentGatewayCatalog::gatewayCards(),
            'activeGateway' => PaymentGatewayCatalog::gateway($this->active_gateway),
        ]);
    }
}
