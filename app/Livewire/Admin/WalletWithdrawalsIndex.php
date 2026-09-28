<?php

namespace App\Livewire\Admin;

use App\Models\WalletWithdrawal;
use App\Services\WalletWithdrawalFeeSettingsService;
use App\Services\WalletWithdrawalService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class WalletWithdrawalsIndex extends Component
{
    use WithPagination;

    public string $statusFilter = 'pending';

    public ?int $notesForId = null;

    public string $notes = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public string $feeType = 'fixed';

    public string $feeValue = '0';

    public function mount(WalletWithdrawalFeeSettingsService $feeSettings): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $settings = $feeSettings->settings();
        $this->feeType = $settings['type'];
        $this->feeValue = rtrim(rtrim(number_format($settings['value'], 2, '.', ''), '0'), '.') ?: '0';
    }

    public function saveFeeSettings(WalletWithdrawalFeeSettingsService $feeSettings): void
    {
        $validated = $this->validate([
            'feeType' => ['required', 'in:fixed,percentage'],
            'feeValue' => ['required', 'numeric', 'min:0'],
        ], [], [
            'feeType' => 'fee type',
            'feeValue' => 'fee value',
        ]);

        $feeSettings->update([
            'type' => $validated['feeType'],
            'value' => $validated['feeValue'],
        ], auth()->user());

        $this->statusMessage = 'Withdrawal fee settings saved.';
        $this->errorMessage = null;
    }

    public function startNotes(int $withdrawalId): void
    {
        $this->notesForId = $withdrawalId;
        $this->notes = '';
    }

    public function approve(int $withdrawalId, WalletWithdrawalService $withdrawals): void
    {
        $this->act($withdrawalId, fn (WalletWithdrawal $withdrawal) => $withdrawals->approve($withdrawal, auth()->user(), $this->notes ?: null));
    }

    public function reject(int $withdrawalId, WalletWithdrawalService $withdrawals): void
    {
        $this->act($withdrawalId, fn (WalletWithdrawal $withdrawal) => $withdrawals->reject($withdrawal, auth()->user(), $this->notes ?: null));
    }

    public function markPaid(int $withdrawalId, WalletWithdrawalService $withdrawals): void
    {
        $this->act($withdrawalId, fn (WalletWithdrawal $withdrawal) => $withdrawals->markPaid($withdrawal, auth()->user(), $this->notes ?: null));
    }

    private function act(int $withdrawalId, \Closure $action): void
    {
        $withdrawal = WalletWithdrawal::findOrFail($withdrawalId);

        try {
            $action($withdrawal);
            $this->statusMessage = 'Withdrawal request updated.';
            $this->errorMessage = null;
        } catch (ValidationException $exception) {
            $this->errorMessage = $exception->getMessage();
            $this->statusMessage = null;
        }

        $this->notesForId = null;
        $this->notes = '';
    }

    public function render()
    {
        return view('livewire.admin.wallet-withdrawals-index', [
            'withdrawals' => WalletWithdrawal::with(['tenant', 'wallet', 'requestedBy', 'processedBy'])
                ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
                ->latest()
                ->paginate(15),
        ]);
    }
}
