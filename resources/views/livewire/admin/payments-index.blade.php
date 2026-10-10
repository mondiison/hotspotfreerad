<div>
    <div class="mb-4 flex justify-end">
        <flux:button href="{{ route('admin.payments.export', $exportQuery) }}" variant="outline" icon="arrow-down-tray">Export CSV</flux:button>
    </div>

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-7">
        @foreach ([
            ['label' => 'Transactions', 'value' => number_format($summary['count']), 'hint' => 'Matching current filters', 'status' => ''],
            ['label' => 'Successful', 'value' => number_format($summary['successful_count']), 'hint' => 'Confirmed customer payments', 'status' => 'successful'],
            ['label' => 'Pending', 'value' => number_format($summary['pending_count']), 'hint' => 'NGN '.number_format($summary['pending_value'], 2).' awaiting confirmation', 'status' => 'pending'],
            ['label' => 'Failed', 'value' => number_format($summary['failed_count']), 'hint' => 'NGN '.number_format($summary['failed_value'], 2).' not confirmed', 'status' => 'failed'],
            ['label' => 'Gross Sales', 'value' => 'NGN '.number_format($summary['successful_revenue'], 2), 'hint' => 'Successful customer payments', 'status' => 'successful'],
            ['label' => 'Platform Commission', 'value' => 'NGN '.number_format($summary['platform_fee'], 2), 'hint' => 'Commission from successful sales', 'status' => 'successful'],
            ['label' => 'Tenant Net', 'value' => 'NGN '.number_format($summary['tenant_net'], 2), 'hint' => 'Gross sales after commission', 'status' => 'successful'],
        ] as $stat)
            <button
                type="button"
                wire:click="$set('status', '{{ $stat['status'] }}')"
                wire:loading.attr="disabled"
                class="rounded-lg border p-5 text-left shadow-sm transition hover:border-zinc-400 dark:hover:border-zinc-500 {{ $status === $stat['status'] ? 'border-zinc-950 bg-zinc-950 text-white dark:border-zinc-700 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-950 dark:text-zinc-100' }}"
            >
                <p class="text-sm font-medium {{ $status === $stat['status'] ? 'text-zinc-300 dark:text-zinc-600' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 {{ $status === $stat['status'] ? 'text-zinc-300 dark:text-zinc-600' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $stat['hint'] }}</p>
            </button>
        @endforeach
    </section>

    <section class="mt-6 flex flex-wrap gap-2">
        @foreach ($presets as $key => $label)
            <flux:button
                type="button"
                wire:click="setPreset('{{ $key }}')"
                wire:loading.attr="disabled"
                wire:target="setPreset('{{ $key }}')"
                variant="{{ $filters['preset'] === $key ? 'primary' : 'outline' }}"
                size="sm"
            >
                {{ $label }}
            </flux:button>
        @endforeach

        <flux:button
            type="button"
            wire:click="useCustomRange"
            variant="{{ $filters['preset'] ? 'outline' : 'primary' }}"
            size="sm"
        >
            Custom
        </flux:button>
    </section>

    @php
        $paymentsActiveFilters = [];
        if (filled($search)) {
            $paymentsActiveFilters[] = ['label' => 'Search: "'.$search.'"', 'clear' => "\$set('search', '')"];
        }
        if (filled($status)) {
            $paymentsActiveFilters[] = ['label' => 'Status: '.($status === 'attention' ? 'Needs attention' : str_replace('_', ' ', ucfirst($status))), 'clear' => "\$set('status', '')"];
        }
        if (filled($provider)) {
            $paymentsActiveFilters[] = ['label' => 'Method: '.($paymentMethods[$provider] ?? $provider), 'clear' => "\$set('provider', '')"];
        }
    @endphp

    <x-admin.filter-bar modal-name="filters-payments" :active="$paymentsActiveFilters" class="mt-4">
        <flux:field>
            <flux:label>From</flux:label>
            <flux:input type="date" wire:model.live="from" />
        </flux:field>
        <flux:field>
            <flux:label>To</flux:label>
            <flux:input type="date" wire:model.live="to" />
        </flux:field>
        <div class="sm:col-span-2">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search ref, customer, shop, package" />
        </div>
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="attention">Needs attention</flux:select.option>
            @foreach (['pending', 'successful', 'failed', 'verification_failed'] as $statusOption)
                <flux:select.option value="{{ $statusOption }}">{{ str_replace('_', ' ', ucfirst($statusOption)) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="provider">
            <flux:select.option value="">All collection methods</flux:select.option>
            @foreach ($paymentMethods as $methodKey => $methodLabel)
                <flux:select.option value="{{ $methodKey }}">{{ $methodLabel }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" class="w-full sm:col-span-2" wire:click="clearFilters" x-on:click="$flux.modal('filters-payments').close()" wire:loading.attr="disabled" wire:target="clearFilters,from,to,search,status,provider">
            Reset
        </flux:button>
    </x-admin.filter-bar>

    <div wire:loading.flex wire:target="from,to,search,status,provider,setPreset,useCustomRange,clearFilters" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating report...
    </div>

    <div class="mt-6 overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <table class="min-w-[1100px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                <tr>
                    <x-admin.sortable-th column="created_at" :sort-by="$sortBy" :sort-direction="$sortDirection">Transaction</x-admin.sortable-th>
                    <th class="px-4 py-3 font-medium">Customer</th>
                    <th class="px-4 py-3 font-medium">Plan</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Method</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <x-admin.sortable-th column="gross_amount" :sort-by="$sortBy" :sort-direction="$sortDirection" align="right">Gross</x-admin.sortable-th>
                    <x-admin.sortable-th column="platform_fee_amount" :sort-by="$sortBy" :sort-direction="$sortDirection" align="right">Commission</x-admin.sortable-th>
                    <x-admin.sortable-th column="tenant_net_amount" :sort-by="$sortBy" :sort-direction="$sortDirection" align="right">Tenant Net</x-admin.sortable-th>
                    <th class="px-4 py-3 text-right font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($payments as $payment)
                    <tr wire:key="payment-{{ $payment->id }}">
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-medium">{{ $payment->tx_ref }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $payment->provider_reference ?: 'No provider ref' }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $payment->created_at->diffForHumans() }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs">{{ $payment->customer?->mac_address ?? data_get($payment->payload, 'mac', 'No MAC') }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $payment->customer?->email ?: 'No email' }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $payment->customer?->phone ?: 'No phone' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $payment->package?->name ?? 'Deleted package' }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $payment->subscription ? 'Provisioned' : 'Not provisioned' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $payment->shop?->name ?? 'Deleted shop' }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $payment->shop?->tenant?->company_name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $paymentMethods[$payment->provider] ?? str($payment->provider)->replace('_', ' ')->headline() }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ data_get($payment->payload, 'payment_channel') ?: ($payment->provider === 'flutterwave' ? 'Online checkout' : 'Offline sale') }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$payment->status === 'successful' ? 'green' : ($payment->status === 'pending' ? 'amber' : 'red')">
                                {{ str_replace('_', ' ', $payment->status) }}
                            </flux:badge>
                            @if ($payment->paid_at)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Paid {{ $payment->paid_at->diffForHumans() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-medium">{{ $payment->currency }} {{ number_format($payment->gross_amount ?: $payment->amount, 2) }}</td>
                        <td class="px-4 py-3 text-right">
                            <p>{{ $payment->currency }} {{ number_format($payment->platform_fee_amount, 2) }}</p>
                            @if (($payment->billing_model ?? 'subscription') === 'commission')
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ number_format((float) $payment->commission_rate, 2) }}%</p>
                            @else
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Subscription</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-medium">{{ $payment->currency }} {{ number_format($payment->tenant_net_amount ?: ($payment->gross_amount ?: $payment->amount), 2) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($payment->provider === \App\Support\PaymentGatewayCatalog::MANUAL_BANK && $payment->status === 'pending')
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="primary"
                                    icon="check"
                                    wire:click="confirmManualTransfer({{ $payment->id }})"
                                    wire:confirm="Confirm this bank transfer and provision hotspot access?"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmManualTransfer({{ $payment->id }})"
                                >
                                    <span wire:loading.remove wire:target="confirmManualTransfer({{ $payment->id }})">Confirm</span>
                                    <span wire:loading wire:target="confirmManualTransfer({{ $payment->id }})">Confirming...</span>
                                </flux:button>
                            @elseif ($payment->provider !== \App\Support\PaymentGatewayCatalog::MANUAL_BANK && $payment->status !== 'successful')
                                @if ($payment->provider_reference)
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="outline"
                                        icon="arrow-path"
                                        wire:click="verifyPayment({{ $payment->id }})"
                                        wire:confirm="Re-check this payment with the gateway now?"
                                        wire:loading.attr="disabled"
                                        wire:target="verifyPayment({{ $payment->id }})"
                                    >
                                        <span wire:loading.remove wire:target="verifyPayment({{ $payment->id }})">Verify</span>
                                        <span wire:loading wire:target="verifyPayment({{ $payment->id }})">Verifying...</span>
                                    </flux:button>
                                @else
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500" title="No provider reference yet">No reference</span>
                                @endif
                            @elseif ($payment->status === 'successful' && $payment->subscription && $payment->subscription->expires_at->isFuture() && blank($payment->voucher_id))
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="outline"
                                    icon="lifebuoy"
                                    wire:click="generateRecoveryVoucher({{ $payment->id }})"
                                    wire:confirm="Generate a one-time voucher linked to this payment, and end access for the current MAC ({{ $payment->subscription->mac_address }})? Give the resulting code to the customer to redeem on the portal's voucher box."
                                    wire:loading.attr="disabled"
                                    wire:target="generateRecoveryVoucher({{ $payment->id }})"
                                >
                                    <span wire:loading.remove wire:target="generateRecoveryVoucher({{ $payment->id }})">Recover access</span>
                                    <span wire:loading wire:target="generateRecoveryVoucher({{ $payment->id }})">Generating...</span>
                                </flux:button>
                            @else
                                <span class="text-xs text-zinc-400 dark:text-zinc-500">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No payments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>

    <flux:modal wire:model.self="showRecoveryVoucherModal" class="max-w-md" :dismissible="true">
        <div class="space-y-4" x-data="{ copied: false }">
            <div>
                <flux:heading size="lg">Recovery voucher generated</flux:heading>
                <flux:subheading>
                    For payment {{ $recoveryVoucherTxRef }}. Read this code out to the customer -- they enter it in the "Have a voucher?" box on the hotspot portal to get access under their current device, with nothing charged again.
                </flux:subheading>
            </div>

            <div class="flex items-center justify-between gap-3 rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-4 py-3">
                <span class="font-mono text-lg font-semibold tracking-wide">{{ $recoveryVoucherCode }}</span>
                <flux:button
                    type="button"
                    variant="outline"
                    size="sm"
                    icon="clipboard"
                    @click="copied = await window.copyText(@js($recoveryVoucherCode)); setTimeout(() => copied = false, 1800)"
                >
                    <span x-show="! copied">Copy</span>
                    <span x-cloak x-show="copied">Copied</span>
                </flux:button>
            </div>

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeRecoveryVoucherModal">Close</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
