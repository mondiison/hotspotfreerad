<div>
    <div class="mb-4 flex justify-end">
        <flux:button href="{{ route('admin.payments.export', $exportQuery) }}" variant="outline" icon="arrow-down-tray">Export CSV</flux:button>
    </div>

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-7">
        @foreach ([
            ['label' => 'Transactions', 'value' => number_format($summary['count']), 'hint' => 'Matching current filters'],
            ['label' => 'Successful', 'value' => number_format($summary['successful_count']), 'hint' => 'Confirmed customer payments'],
            ['label' => 'Pending', 'value' => number_format($summary['pending_count']), 'hint' => 'NGN '.number_format($summary['pending_value'], 2).' awaiting confirmation'],
            ['label' => 'Failed', 'value' => number_format($summary['failed_count']), 'hint' => 'NGN '.number_format($summary['failed_value'], 2).' not confirmed'],
            ['label' => 'Gross Sales', 'value' => 'NGN '.number_format($summary['successful_revenue'], 2), 'hint' => 'Successful customer payments'],
            ['label' => 'Platform Commission', 'value' => 'NGN '.number_format($summary['platform_fee'], 2), 'hint' => 'Commission from successful sales'],
            ['label' => 'Tenant Net', 'value' => 'NGN '.number_format($summary['tenant_net'], 2), 'hint' => 'Gross sales after commission'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
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

    <section class="mt-4 grid min-w-0 gap-3 rounded-lg border border-zinc-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-[140px_140px_minmax(0,1fr)_170px_170px_auto] [&>*]:min-w-0">
        <flux:input type="date" wire:model.live="from" />
        <flux:input type="date" wire:model.live="to" />
        <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search ref, customer, shop, package" />
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="attention">Needs attention</flux:select.option>
            @foreach (['pending', 'successful', 'failed', 'verification_failed'] as $statusOption)
                <flux:select.option value="{{ $statusOption }}">{{ str_replace('_', ' ', ucfirst($statusOption)) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="provider">
            <flux:select.option value="">All providers</flux:select.option>
            <flux:select.option value="flutterwave">Flutterwave</flux:select.option>
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" class="w-full sm:col-span-2 xl:col-span-1 xl:w-auto" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,from,to,search,status,provider">
            Reset
        </flux:button>
    </section>

    <div wire:loading.flex wire:target="from,to,search,status,provider,setPreset,useCustomRange,clearFilters" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating report...
    </div>

    <div class="mt-6 overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="min-w-[980px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Transaction</th>
                    <th class="px-4 py-3 font-medium">Customer</th>
                    <th class="px-4 py-3 font-medium">Plan</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Gross</th>
                    <th class="px-4 py-3 text-right font-medium">Commission</th>
                    <th class="px-4 py-3 text-right font-medium">Tenant Net</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($payments as $payment)
                    <tr wire:key="payment-{{ $payment->id }}">
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-medium">{{ $payment->tx_ref }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $payment->provider_reference ?: 'No provider ref' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $payment->created_at->diffForHumans() }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs">{{ $payment->customer?->mac_address ?? data_get($payment->payload, 'mac', 'No MAC') }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $payment->customer?->email ?: 'No email' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $payment->customer?->phone ?: 'No phone' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $payment->package?->name ?? 'Deleted package' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $payment->subscription ? 'Provisioned' : 'Not provisioned' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $payment->shop?->name ?? 'Deleted shop' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $payment->shop?->tenant?->company_name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$payment->status === 'successful' ? 'green' : ($payment->status === 'pending' ? 'amber' : 'red')">
                                {{ str_replace('_', ' ', $payment->status) }}
                            </flux:badge>
                            @if ($payment->paid_at)
                                <p class="mt-1 text-xs text-zinc-500">Paid {{ $payment->paid_at->diffForHumans() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-medium">{{ $payment->currency }} {{ number_format($payment->gross_amount ?: $payment->amount, 2) }}</td>
                        <td class="px-4 py-3 text-right">
                            <p>{{ $payment->currency }} {{ number_format($payment->platform_fee_amount, 2) }}</p>
                            @if (($payment->billing_model ?? 'subscription') === 'commission')
                                <p class="mt-1 text-xs text-zinc-500">{{ number_format((float) $payment->commission_rate, 2) }}%</p>
                            @else
                                <p class="mt-1 text-xs text-zinc-500">Subscription</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-medium">{{ $payment->currency }} {{ number_format($payment->tenant_net_amount ?: ($payment->gross_amount ?: $payment->amount), 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-zinc-500">No payments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>
</div>
