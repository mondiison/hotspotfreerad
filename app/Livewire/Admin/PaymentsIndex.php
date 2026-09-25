<?php

namespace App\Livewire\Admin;

use App\Models\Payment;
use App\Services\HotspotPaymentConfirmationService;
use App\Services\PaymentReportService;
use App\Support\PaymentGatewayCatalog;
use App\Support\TenantAccess;
use Livewire\Component;
use Livewire\WithPagination;

class PaymentsIndex extends Component
{
    use WithPagination;

    public string $preset = '';

    public string $from = '';

    public string $to = '';

    public string $search = '';

    public string $status = '';

    public string $provider = '';

    protected $queryString = [
        'preset' => ['except' => ''],
        'from' => ['except' => ''],
        'to' => ['except' => ''],
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'provider' => ['except' => ''],
    ];

    public function mount(array $filters = []): void
    {
        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) ($filters['from'] ?? now()->startOfMonth()->toDateString());
        $this->to = (string) ($filters['to'] ?? now()->toDateString());
        $this->search = (string) ($filters['search'] ?? '');
        $this->status = (string) ($filters['status'] ?? '');
        $this->provider = (string) ($filters['provider'] ?? '');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = '';
        }

        if (in_array($property, ['preset', 'from', 'to', 'search', 'status', 'provider'], true)) {
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
        $this->resetPage();
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

        $payments->markPaidAndGrantAccess($payment, [
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
        ]);

        $this->preset = (string) ($filters['preset'] ?? '');
        $this->from = (string) $filters['from'];
        $this->to = (string) $filters['to'];

        $query = $reports->query(auth()->user(), $filters);

        return view('livewire.admin.payments-index', [
            'payments' => $query->latest()->paginate(20),
            'summary' => $reports->summary(clone $query),
            'filters' => $filters,
            'presets' => $reports->presets(),
            'exportQuery' => $reports->queryParams($filters),
            'paymentMethods' => PaymentGatewayCatalog::paymentMethods(),
        ]);
    }
}
