<?php

namespace App\Livewire\Admin;

use App\Models\Shop;
use App\Services\PaymentSettingsService;
use App\Services\WalletService;
use App\Support\PaymentGatewayCatalog;
use Flux\Flux;
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
        $this->payment_gateway = $this->rawPaymentGateway($shop);
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
        $this->payment_gateway = $this->rawPaymentGateway($this->shop);
        $this->gateway_settings = $this->defaultGatewaySettings();

        $this->savedMessage = 'Payment settings updated for '.$this->shop->name.'.';

        session()->flash('status', 'Payment settings updated for '.$this->shop->name.'.');
    }

    /**
     * 2026-10-10, confirmed live: this form edits a shop's OWN
     * payment_gateway column directly -- using $shop->paymentGateway()
     * here instead (as this used to) silently returns the PLATFORM's
     * wallet gateway for a wallet-enabled tenant, so picking "Paystack"
     * and saving would immediately snap the dropdown back to "Monnify"
     * (or whatever the platform's active gateway happens to be) the
     * instant the form re-hydrated after save, even though the save
     * itself correctly wrote 'paystack' to the raw column. The literal
     * saved choice is always shown here now, regardless of whether
     * wallet mode currently overrides it for actual checkout.
     */
    private function rawPaymentGateway(Shop $shop): string
    {
        return $shop->payment_gateway ?: PaymentGatewayCatalog::FLUTTERWAVE;
    }

    /**
     * 2026-10-10, direct request: the wallet-mode banner above used to just
     * say checkout is overridden "until wallet mode is turned off" with no
     * way to actually do that from here -- and WalletService::disable() had
     * no caller anywhere in the UI at all (a tenant could self-service
     * *enable* wallet mode from admin/wallet, but never disable it, and
     * super admins had no wallet toggle anywhere either). Surfacing the
     * action right here, where the confusion actually happens, needed no
     * new route/page -- gated the same way admin/wallet itself is reachable
     * (super admin, or the tenant's own tenant_admin; tenant_staff has no
     * entry in StaffPermissions for that route either, so it's excluded
     * here too for consistency).
     */
    public function canManageWalletMode(): bool
    {
        $user = auth()->user();

        return $user->isSuperAdmin() || ($user->isTenantAdmin() && $user->tenant_id === $this->shop->tenant_id);
    }

    public function disableWallet(WalletService $wallets): void
    {
        abort_unless($this->canManageWalletMode(), 403);

        $wallets->disable($this->shop->tenant);

        $this->shop->refresh();
        $this->shop->load('tenant');

        Flux::toast(
            heading: 'Wallet mode disabled',
            text: 'Customer checkout for this tenant now uses whichever gateway is configured below.',
            variant: 'success',
        );
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

    /**
     * Gateways where live/test is determined purely by which secret key is
     * pasted (one shared API host), so the UI shows a read-only fact derived
     * from the saved key instead of a separately-editable setting that could
     * disagree with it. Squad/Monnify are NOT here -- they have genuinely
     * different API hosts per environment, so their `environment` stays a
     * real, editable `select_fields` choice instead.
     */
    private const KEY_DETECTED_ENVIRONMENT_GATEWAYS = [PaymentGatewayCatalog::PAYSTACK, PaymentGatewayCatalog::STRIPE];

    public function render()
    {
        $showsDetectedEnvironment = in_array($this->payment_gateway, self::KEY_DETECTED_ENVIRONMENT_GATEWAYS, true);
        $detectedEnvironment = null;

        if ($showsDetectedEnvironment) {
            $savedSecretKey = (string) ($this->shop->paymentGatewaySettings()[$this->payment_gateway]['secret_key'] ?? '');
            $detectedEnvironment = PaymentGatewayCatalog::detectedEnvironment($savedSecretKey);
        }

        return view('livewire.admin.payment-settings-card', [
            'readiness' => PaymentGatewayCatalog::tenantReadiness($this->shop),
            'gatewayOptions' => PaymentGatewayCatalog::gatewayOptions(),
            'gatewayCards' => PaymentGatewayCatalog::gatewayCards(),
            'activeGateway' => PaymentGatewayCatalog::gateway($this->payment_gateway),
            'credentialFields' => PaymentGatewayCatalog::credentialFields($this->payment_gateway),
            'secretFieldKeys' => PaymentGatewayCatalog::secretFieldKeys($this->payment_gateway),
            'selectFields' => PaymentGatewayCatalog::selectFields($this->payment_gateway),
            'showsDetectedEnvironment' => $showsDetectedEnvironment,
            'detectedEnvironment' => $detectedEnvironment,
        ]);
    }
}
