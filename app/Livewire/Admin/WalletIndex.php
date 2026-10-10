<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\WalletTransaction;
use App\Models\WalletWithdrawal;
use App\Services\BankAccountResolutionService;
use App\Services\WalletService;
use App\Services\WalletWithdrawalFeeSettingsService;
use App\Services\WalletWithdrawalService;
use App\Support\BillingPlanLimits;
use App\Support\WalletWithdrawalFee;
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

    public string $txType = '';

    public string $txFrom = '';

    public string $txTo = '';

    public string $withdrawalStatus = '';

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

    /**
     * 2026-10-10, direct request: WalletService::disable() had no caller
     * anywhere in the UI -- a tenant could self-service enable wallet mode
     * from here but never turn it back off. Symmetric with enableWallet()
     * above; the wallet balance/history are untouched either way, only the
     * gateway Shop::paymentGateway() resolves to for this tenant changes.
     */
    public function disableWallet(WalletService $wallets): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);

        $wallets->disable($tenant);

        Flux::toast(
            heading: 'Wallet disabled',
            text: 'Customer checkout now uses each shop\'s own configured gateway instead of the platform account.',
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

    public function clearTransactionFilters(): void
    {
        $this->reset(['txType', 'txFrom', 'txTo']);
        $this->resetPage('page');
    }

    public function clearWithdrawalFilters(): void
    {
        $this->reset(['withdrawalStatus']);
        $this->resetPage('withdrawalsPage');
    }

    public function updatedTxType(): void
    {
        $this->resetPage('page');
    }

    public function updatedTxFrom(): void
    {
        $this->resetPage('page');
    }

    public function updatedTxTo(): void
    {
        $this->resetPage('page');
    }

    public function updatedWithdrawalStatus(): void
    {
        $this->resetPage('withdrawalsPage');
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

    public function render(BankAccountResolutionService $resolver, WalletWithdrawalFeeSettingsService $feeSettings)
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

        $feePreview = is_numeric($this->withdrawAmount) && (float) $this->withdrawAmount > 0
            ? WalletWithdrawalFee::calculate((float) $this->withdrawAmount, $feeSettings->settings())
            : null;

        return view('livewire.admin.wallet-index', [
            'tenant' => $tenant,
            'canEnable' => $canEnable,
            'canEnableError' => $this->canEnableError ?? null,
            'balance' => (float) ($tenant->wallet?->balance ?? 0),
            'feePreview' => $feePreview,
            'banks' => $tenant->wallet_enabled ? $resolver->banks() : [],
            'bankVerificationAvailable' => $resolver->activeProvider() !== null,
            'transactions' => $tenant->wallet
                ? WalletTransaction::where('wallet_id', $tenant->wallet->id)
                    ->when(filled($this->txType), fn ($query) => $query->where('type', $this->txType))
                    ->when(filled($this->txFrom), fn ($query) => $query->whereDate('created_at', '>=', $this->txFrom))
                    ->when(filled($this->txTo), fn ($query) => $query->whereDate('created_at', '<=', $this->txTo))
                    ->latest()
                    ->paginate(15, pageName: 'page')
                : null,
            'withdrawals' => $tenant->wallet
                ? WalletWithdrawal::where('tenant_id', $tenant->id)
                    ->when(filled($this->withdrawalStatus), fn ($query) => $query->where('status', $this->withdrawalStatus))
                    ->latest()
                    ->paginate(10, pageName: 'withdrawalsPage')
                : collect(),
        ]);
    }
}
