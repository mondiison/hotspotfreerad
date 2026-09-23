<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\WalletTransaction;
use App\Models\WalletWithdrawal;
use App\Services\WalletService;
use App\Services\WalletWithdrawalService;
use App\Support\BillingPlanLimits;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class WalletIndex extends Component
{
    use WithPagination;

    #[Locked]
    public int $tenantId;

    public string $commissionBearer = 'tenant';

    public string $withdrawAmount = '';

    public string $bankName = '';

    public string $accountNumber = '';

    public string $accountName = '';

    public ?string $statusMessage = null;

    public ?string $canEnableError = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenantId = $tenant->id;
        $this->commissionBearer = $tenant->wallet_commission_bearer ?: 'tenant';
    }

    public function enableWallet(WalletService $wallets): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);

        BillingPlanLimits::assertCanEnableWallet(auth()->user());

        $wallets->enable($tenant);

        $this->statusMessage = 'Wallet enabled. Customer payments for this tenant now route through the platform gateway.';
    }

    public function saveCommissionBearer(): void
    {
        $this->validate(['commissionBearer' => 'required|in:tenant,customer']);

        Tenant::findOrFail($this->tenantId)->forceFill([
            'wallet_commission_bearer' => $this->commissionBearer,
        ])->save();

        $this->statusMessage = 'Saved who pays the platform commission.';
    }

    public function requestWithdrawal(WalletWithdrawalService $withdrawals): void
    {
        $validated = $this->validate([
            'withdrawAmount' => ['required', 'numeric', 'min:1'],
            'bankName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:50'],
            'accountName' => ['required', 'string', 'max:255'],
        ]);

        $tenant = Tenant::findOrFail($this->tenantId);

        $withdrawals->request(
            $tenant,
            (float) $validated['withdrawAmount'],
            [
                'bank_name' => $validated['bankName'],
                'account_number' => $validated['accountNumber'],
                'account_name' => $validated['accountName'],
            ],
            auth()->user(),
        );

        $this->reset(['withdrawAmount', 'bankName', 'accountNumber', 'accountName']);
        $this->statusMessage = 'Withdrawal request submitted. It will be reviewed and paid out manually.';
    }

    public function render()
    {
        $tenant = Tenant::with('currentBillingSubscription.billingPlan', 'wallet')->findOrFail($this->tenantId);

        $canEnable = null;

        if (! $tenant->wallet_enabled) {
            try {
                BillingPlanLimits::assertCanEnableWallet(auth()->user());
                $canEnable = true;
            } catch (ValidationException $exception) {
                $canEnable = false;
                $this->canEnableError = $exception->getMessage();
            }
        }

        return view('livewire.admin.wallet-index', [
            'tenant' => $tenant,
            'canEnable' => $canEnable,
            'canEnableError' => $this->canEnableError ?? null,
            'balance' => (float) ($tenant->wallet?->balance ?? 0),
            'transactions' => $tenant->wallet
                ? WalletTransaction::where('wallet_id', $tenant->wallet->id)->latest()->paginate(15)
                : null,
            'withdrawals' => $tenant->wallet
                ? WalletWithdrawal::where('tenant_id', $tenant->id)->latest()->get()
                : collect(),
        ]);
    }
}
