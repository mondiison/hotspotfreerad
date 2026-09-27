<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\WalletTransaction;
use App\Models\WalletWithdrawal;
use App\Services\BankAccountResolutionService;
use App\Services\WalletService;
use App\Services\WalletWithdrawalService;
use App\Support\BillingPlanLimits;
use Flux\Flux;
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

    public bool $showWithdrawModal = false;

    public bool $editingSettlementAccount = false;

    public string $selectedBankCode = '';

    public string $settlementAccountNumber = '';

    public ?string $resolvedAccountName = null;

    public ?string $verifyError = null;

    public ?string $canEnableError = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenantId = $tenant->id;
        $this->commissionBearer = $tenant->wallet_commission_bearer ?: 'tenant';
        $this->editingSettlementAccount = ! $tenant->hasVerifiedSettlementAccount();
    }

    public function enableWallet(WalletService $wallets): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);

        BillingPlanLimits::assertCanEnableWallet(auth()->user());

        $wallets->enable($tenant);

        Flux::toast(
            heading: 'Wallet enabled',
            text: 'Customer payments for this tenant now route through the platform gateway.',
            variant: 'success',
        );
    }

    public function saveCommissionBearer(): void
    {
        $this->validate(['commissionBearer' => 'required|in:tenant,customer']);

        Tenant::findOrFail($this->tenantId)->forceFill([
            'wallet_commission_bearer' => $this->commissionBearer,
        ])->save();

        Flux::toast(heading: 'Saved', text: 'Updated who pays the platform commission.', variant: 'success');
    }

    public function startEditingSettlementAccount(): void
    {
        $this->editingSettlementAccount = true;
        $this->resolvedAccountName = null;
        $this->verifyError = null;
    }

    public function updatedSelectedBankCode(): void
    {
        $this->resolvedAccountName = null;
        $this->verifyError = null;
    }

    public function updatedSettlementAccountNumber(): void
    {
        $this->resolvedAccountName = null;
        $this->verifyError = null;
    }

    public function verifySettlementAccount(BankAccountResolutionService $resolver): void
    {
        $this->validate([
            'selectedBankCode' => ['required', 'string'],
            'settlementAccountNumber' => ['required', 'string', 'min:10', 'max:10'],
        ]);

        $result = $resolver->resolveAccount($this->selectedBankCode, $this->settlementAccountNumber);

        $this->resolvedAccountName = $result['account_name'];
        $this->verifyError = $result['error'];
    }

    public function saveSettlementAccount(BankAccountResolutionService $resolver): void
    {
        if (blank($this->resolvedAccountName)) {
            $this->verifyError = 'Verify the account before saving it.';

            return;
        }

        $bankName = collect($resolver->banks())->firstWhere('code', $this->selectedBankCode)['name'] ?? $this->selectedBankCode;

        Tenant::findOrFail($this->tenantId)->forceFill([
            'settlement_bank_code' => $this->selectedBankCode,
            'settlement_bank_name' => $bankName,
            'settlement_account_number' => $this->settlementAccountNumber,
            'settlement_account_name' => $this->resolvedAccountName,
            'settlement_verified_at' => now(),
        ])->save();

        $this->editingSettlementAccount = false;
        $this->reset(['selectedBankCode', 'settlementAccountNumber', 'resolvedAccountName', 'verifyError']);

        Flux::toast(heading: 'Settlement account saved', text: 'Verified and ready for withdrawals.', variant: 'success');
    }

    public function openWithdrawModal(): void
    {
        $this->showWithdrawModal = true;
    }

    public function requestWithdrawal(WalletWithdrawalService $withdrawals): void
    {
        $validated = $this->validate([
            'withdrawAmount' => ['required', 'numeric', 'min:1'],
        ]);

        $tenant = Tenant::findOrFail($this->tenantId);

        if (! $tenant->hasVerifiedSettlementAccount()) {
            $this->addError('withdrawAmount', 'Save and verify a settlement account before requesting a withdrawal.');

            return;
        }

        $withdrawals->request(
            $tenant,
            (float) $validated['withdrawAmount'],
            [
                'bank_name' => $tenant->settlement_bank_name,
                'account_number' => $tenant->settlement_account_number,
                'account_name' => $tenant->settlement_account_name,
            ],
            auth()->user(),
        );

        $this->reset(['withdrawAmount']);
        $this->showWithdrawModal = false;

        Flux::toast(
            heading: 'Withdrawal requested',
            text: 'It will be reviewed and paid out manually. Track its status under "Withdrawal requests" below.',
            variant: 'success',
        );
    }

    public function render(BankAccountResolutionService $resolver)
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
            'banks' => $tenant->wallet_enabled ? $resolver->banks() : [],
            'bankVerificationAvailable' => $resolver->activeProvider() !== null,
            'transactions' => $tenant->wallet
                ? WalletTransaction::where('wallet_id', $tenant->wallet->id)->latest()->paginate(15)
                : null,
            'withdrawals' => $tenant->wallet
                ? WalletWithdrawal::where('tenant_id', $tenant->id)->latest()->get()
                : collect(),
        ]);
    }
}
