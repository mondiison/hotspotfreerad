<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\SortsTable;
use App\Models\Payment;
use App\Models\Voucher;
use App\Services\AbandonedPaymentCleanupService;
use App\Services\HotspotPaymentConfirmationService;
use App\Services\PaymentReportService;
use App\Services\VoucherManagementService;
use App\Support\PaymentGatewayCatalog;
use App\Support\TenantAccess;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class PaymentsIndex extends Component
{
    use SortsTable, WithPagination;

    public string $preset = '';

    public string $from = '';

    public string $to = '';

    public string $search = '';

    public string $status = '';

    public string $provider = '';

    public string $channel = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public bool $showRecoveryVoucherModal = false;

    public ?string $recoveryVoucherCode = null;

    public ?string $recoveryVoucherTxRef = null;

    /**
     * 2026-11-09: the same modal/code/txref state above is reused for both
     * generateRecoveryVoucher() (a stuck MAC) and confirmManualTransfer()
     * confirming a manual-bank payment that turns out to be a "buy as
     * voucher" purchase -- this just swaps the modal's heading/copy to
     * match which one actually happened.
     */
    public string $voucherModalMode = 'recovery';

    protected $queryString = [
        'preset' => ['except' => ''],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'provider' => ['except' => ''],
        'channel' => ['except' => ''],
        'sortBy' => ['except' => 'created_at'],
        'sortDirection' => ['except' => 'desc'],
    ];

    public function mount(array $filters = []): void
    {
        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? now()->startOfMonth()->toDateString());
        $this->to = (string) ($filters['to'] ?? now()->toDateString());
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->provider = (string) ($filters['provider'] ?? '');
        $this->channel = (string) ($filters['channel'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = '';
        }

        if (in_array($property, ['preset', 'from', 'to', 'search', 'status', 'provider', 'channel'], true)) {
            $this->resetPage();
        }
    }

    public function setPreset(string $preset, PaymentReportService $reports): void
    {
        if (! array_key_exists($preset, $reports->presets())) {
            return;
        }

        $filters = $reports->filters([
            'preset' => $preset,
            'status' => $this->status,
            'provider' => $this->provider,
            'channel' => $this->channel,
            'search' => $this->search,
        ]);

        $this->preset = (string) $filters['preset'];
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];
        $this->resetPage();
    }

    public function useCustomRange(): void
    {
        $this->preset = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->preset = '';
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->search = '';
        $this->status = '';
        $this->provider = '';
        $this->channel = '';
        $this->resetPage();
    }

    protected function sortableColumns(): array
    {
        return ['created_at', 'gross_amount', 'platform_fee_amount', 'tenant_net_amount'];
    }

    public function confirmManualTransfer(int $paymentId, HotspotPaymentConfirmationService $payments): void
    {
        $payment = TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package', 'subscription']),
            auth()->user()
        )->findOrFail($paymentId);

        if ($payment->provider !== PaymentGatewayCatalog::MANUAL_BANK || $payment->status !== 'pending') {
            $this->dispatch('notify', type: 'warning', message: 'Only pending manual bank transfers can be confirmed here.');

            return;
        }

        $result = $payments->markPaidAndGrantAccess($payment, [
            'status' => 'success',
            'data' => [
                'reference' => $payment->tx_ref,
                'status' => 'successful',
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'confirmed_by' => auth()->id(),
                'confirmed_manually_at' => now()->toIso8601String(),
            ],
        ]);

        // 2026-11-09: a manual transfer confirmed for a payment flagged
        // as_voucher at checkout (see PortalController::pay()) gets a
        // voucher instead of access provisioned to any device -- show the
        // code in the same copyable modal generateRecoveryVoucher() below
        // already uses, so it can be read/sent to the customer, rather than
        // the generic "access provisioned" toast that would be wrong here.
        if ($result instanceof Voucher) {
            $this->voucherModalMode = 'purchase';
            $this->recoveryVoucherCode = $result->code;
            $this->recoveryVoucherTxRef = $payment->tx_ref;
            $this->showRecoveryVoucherModal = true;

            return;
        }

        $this->dispatch('notify', type: 'success', message: 'Manual transfer confirmed and hotspot access provisioned.');
    }

    /**
     * Re-queries the payment's own gateway using its already-stored
     * provider_reference and grants access if it now confirms successful --
     * added 2026-09-25 after a live report of a stuck customer payment
     * (Monnify's redirect URL was malformed, separately fixed, but the
     * underlying Payment row + provider_reference were saved correctly at
     * checkout time, so nothing here needed the redirect to have worked).
     * Mirrors BillingController::verify()'s already-proven shape for
     * platform billing, and reuses the exact same verifyAndGrant() every
     * other confirmation path (callback, webhook, customer-facing manual
     * verify) already goes through.
     */
    public function verifyPayment(int $paymentId, HotspotPaymentConfirmationService $payments): void
    {
        $payment = TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package', 'subscription']),
            auth()->user()
        )->findOrFail($paymentId);

        if ($payment->provider === PaymentGatewayCatalog::MANUAL_BANK || $payment->status === 'successful') {
            $this->dispatch('notify', type: 'warning', message: 'Nothing to verify for this payment.');

            return;
        }

        if (blank($payment->provider_reference)) {
            $this->dispatch('notify', type: 'warning', message: PaymentGatewayCatalog::gatewayName($payment->provider).' has not returned a provider reference for this payment yet.');

            return;
        }

        try {
            $subscription = $payments->verifyAndGrant(
                $payment,
                (string) $payment->provider_reference,
                $this->paymentResourceType((string) $payment->provider_reference)
            );
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'warning', message: 'Could not verify this payment: '.$exception->getMessage());

            return;
        }

        if (! $subscription) {
            $this->dispatch('notify', type: 'warning', message: 'Checked '.PaymentGatewayCatalog::gatewayName($payment->provider).' again, but this payment still isn\'t confirmed.');

            return;
        }

        $this->dispatch('notify', type: 'success', message: 'Payment verified and hotspot access provisioned.');
    }

    /**
     * 2026-10-09, direct request after a live report: a customer's payment
     * was confirmed successful and the subscription showed "Provisioned",
     * but their device stayed stuck on the login screen -- almost
     * certainly a MAC mismatch (the same randomization story
     * SubscriptionsIndex::changeMacAddress() exists for), except here
     * there's no known new MAC to retarget directly since this is a remote
     * support call. Generates a one-off voucher linked back to this exact
     * payment (see VoucherManagementService::generateRecoveryVoucher() for
     * why this never creates a second payment row) and shows the resulting
     * code in a copyable modal so it can be read out to the customer to
     * redeem themselves from the portal's own voucher box.
     */
    public function generateRecoveryVoucher(int $paymentId, VoucherManagementService $vouchers): void
    {
        $payment = TenantAccess::scopePayments(
            Payment::query()->with(['shop.tenant', 'package', 'subscription']),
            auth()->user()
        )->findOrFail($paymentId);

        try {
            $voucher = $vouchers->generateRecoveryVoucher($payment, auth()->user());
        } catch (ValidationException $exception) {
            $this->dispatch('notify', type: 'warning', message: collect($exception->errors())->flatten()->first());

            return;
        }

        $this->voucherModalMode = 'recovery';
        $this->recoveryVoucherCode = $voucher->code;
        $this->recoveryVoucherTxRef = $payment->tx_ref;
        $this->showRecoveryVoucherModal = true;
    }

    /**
     * 2026-11-07, direct request: clean a "No reference" checkout from the
     * list right away instead of waiting for the scheduled cleanup.
     */
    public function cleanAbandonedPayment(int $paymentId, AbandonedPaymentCleanupService $cleanup): void
    {
        $payment = TenantAccess::scopePayments(Payment::query(), auth()->user())->findOrFail($paymentId);

        $this->dispatch('notify', ...match ($cleanup->clean($payment)) {
            'abandoned' => ['type' => 'success', 'message' => 'Checkout marked as abandoned.'],
            'recovered' => ['type' => 'success', 'message' => 'This checkout was actually paid. Access has been granted.'],
            default => ['type' => 'warning', 'message' => 'Could not clean this payment right now. Try again in a moment.'],
        });
    }

    /**
     * Clean every visible "No reference" checkout older than 10 minutes, so a
     * customer who is still on the payment page right now is left alone.
     */
    public function cleanAllAbandonedPayments(AbandonedPaymentCleanupService $cleanup): void
    {
        $result = $cleanup->run(10, false, TenantAccess::scopePayments(Payment::query(), auth()->user()));

        $message = $result['candidates'] === 0
            ? 'No checkouts without a reference to clean.'
            : "Cleaned {$result['abandoned']} checkout(s)".($result['recovered'] ? ", {$result['recovered']} found paid and granted" : '').($result['skipped'] ? ", {$result['skipped']} left for later" : '').'.';

        $this->dispatch('notify', type: 'success', message: $message);
    }

    public function closeRecoveryVoucherModal(): void
    {
        $this->showRecoveryVoucherModal = false;
        $this->recoveryVoucherCode = null;
        $this->recoveryVoucherTxRef = null;
        $this->voucherModalMode = 'recovery';
    }

    /**
     * Mirrors PortalController::paymentResourceType() -- duplicated rather
     * than exposed from the controller, since it's a small, pure heuristic
     * with no other dependencies.
     */
    private function paymentResourceType(string $providerReference): string
    {
        return str_starts_with(strtolower($providerReference), 'chg') ? 'charge' : 'order';
    }

    public function render(PaymentReportService $reports)
    {
        $filters = $reports->filters([
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'status' => $this->status,
            'provider' => $this->provider,
            'channel' => $this->channel,
        ]);

        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];

        $query = $reports->query(auth()->user(), $filters);

        return view('livewire.admin.payments-index', [
            'payments' => $query->orderBy($this->sortBy, $this->sortDirection)->paginate(20),
            'summary' => $reports->summary(clone $query),
            'filters' => $filters,
            'presets' => $reports->presets(),
            'exportQuery' => $reports->queryParams($filters),
            'paymentMethods' => PaymentGatewayCatalog::paymentMethods(),
            'noReferenceCount' => app(AbandonedPaymentCleanupService::class)
                ->candidates(TenantAccess::scopePayments(Payment::query(), auth()->user()), 10)
                ->count(),
        ]);
    }
}
