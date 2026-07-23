<?php

namespace App\Livewire\Admin;

use App\Models\Shop;
use App\Services\PaymentSettingsService;
use Livewire\Component;

class PaymentSettingsCard extends Component
{
    public Shop $shop;

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
            'clear_flutterwave_credentials',
            'clear_flutterwave_secret_key',
            'clear_flutterwave_webhook_secret',
        ]);

        $this->savedMessage = 'Payment settings updated for '.$this->shop->name.'.';

        session()->flash('status', 'Payment settings updated for '.$this->shop->name.'.');
    }

    public function render()
    {
        return view('livewire.admin.payment-settings-card');
    }
}
