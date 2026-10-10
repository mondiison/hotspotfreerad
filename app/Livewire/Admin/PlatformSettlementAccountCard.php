<?php

namespace App\Livewire\Admin;

use App\Services\BankAccountResolutionService;
use App\Services\PlatformPaymentSettingsService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * 2026-10-10: the platform's own settlement bank account -- a near copy of
 * WalletIndex's existing per-tenant "Settlement account" editing flow
 * (bank dropdown from BankAccountResolutionService::banks(), debounced
 * account-number input, verify-then-save), just persisting to
 * PlatformPaymentSettingsService::updateSettlementAccount() instead of a
 * Tenant row. Needed for automated commission collection on a tenant's own
 * gateway (TenantManagementService::createCommissionSubaccount()) -- the
 * platform gets registered as a subaccount on each tenant's own Paystack
 * account, which needs this bank account on file once, platform-wide.
 * Deliberately its own small component rather than folded into
 * PlatformPaymentSettingsCard -- that component's entire state/save()/
 * rules() is single-purpose around one gateway's credential form and
 * resets on every gateway switch, a bad fit for a field set that's
 * orthogonal to which gateway is active.
 */
class PlatformSettlementAccountCard extends Component
{
    public bool $editingSettlementAccount = false;

    public string $selectedBankCode = '';

    public string $settlementAccountNumber = '';

    public ?string $resolvedAccountName = null;

    public ?string $verifyError = null;

    public function mount(PlatformPaymentSettingsService $settings): void
    {
        $this->editingSettlementAccount = ! $settings->hasVerifiedSettlementAccount();
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

    public function saveSettlementAccount(BankAccountResolutionService $resolver, PlatformPaymentSettingsService $settings): void
    {
        if (blank($this->resolvedAccountName)) {
            $this->verifyError = 'Verify the account before saving it.';

            return;
        }

        $bankName = collect($resolver->banks())->firstWhere('code', $this->selectedBankCode)['name'] ?? $this->selectedBankCode;

        $settings->updateSettlementAccount([
            'bank_code' => $this->selectedBankCode,
            'bank_name' => $bankName,
            'account_number' => $this->settlementAccountNumber,
            'account_name' => $this->resolvedAccountName,
        ], auth()->user());

        $this->editingSettlementAccount = false;
        $this->reset(['selectedBankCode', 'settlementAccountNumber', 'resolvedAccountName', 'verifyError']);

        Flux::toast(heading: 'Platform settlement account saved', text: 'Ready to use for automated commission collection.', variant: 'success');
    }

    public function render(BankAccountResolutionService $resolver, PlatformPaymentSettingsService $settings)
    {
        $account = $settings->settlementAccount();

        return view('livewire.admin.platform-settlement-account-card', [
            'account' => $account,
            'verifiedAt' => filled($account['verified_at'] ?? null) ? Carbon::parse($account['verified_at']) : null,
            'hasVerifiedAccount' => $settings->hasVerifiedSettlementAccount(),
            'banks' => $resolver->banks(),
            'bankVerificationAvailable' => $resolver->activeProvider() !== null,
        ]);
    }
}
