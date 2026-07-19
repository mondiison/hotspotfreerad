<x-layouts.admin title="Payments" heading="Payments" subheading="Customer hotspot payment attempts, confirmations, and provisioning status.">
    <x-slot:action>
        <flux:button href="{{ route('admin.payments.export', request()->query()) }}" variant="outline" icon="arrow-down-tray">Export CSV</flux:button>
    </x-slot:action>

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
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
                href="{{ route('admin.payments.index', array_filter(['preset' => $key, 'status' => $filters['status'], 'provider' => $filters['provider'], 'search' => $filters['search']])) }}"
                variant="{{ $filters['preset'] === $key ? 'primary' : 'outline' }}"
                size="sm"
            >
                {{ $label }}
            </flux:button>
        @endforeach

        <flux:button
            href="{{ route('admin.payments.index', array_filter(['from' => $filters['from'], 'to' => $filters['to'], 'status' => $filters['status'], 'provider' => $filters['provider'], 'search' => $filters['search']])) }}"
            variant="{{ $filters['preset'] ? 'outline' : 'primary' }}"
            size="sm"
        >
            Custom
        </flux:button>
    </section>

    <form method="GET" class="mt-4 grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 md:grid-cols-[1fr_1fr_1fr_170px_170px_auto]">
        <flux:input type="date" name="from" value="{{ $filters['from'] }}" />
        <flux:input type="date" name="to" value="{{ $filters['to'] }}" />
        <flux:input name="search" value="{{ $filters['search'] }}" placeholder="Search ref, customer, shop, package" />
        <flux:select name="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="attention" :selected="$filters['status'] === 'attention'">Needs attention</flux:select.option>
            @foreach (['pending', 'successful', 'failed', 'verification_failed'] as $status)
                <flux:select.option value="{{ $status }}" :selected="$filters['status'] === $status">{{ str_replace('_', ' ', ucfirst($status)) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select name="provider">
            <flux:select.option value="">All providers</flux:select.option>
            <flux:select.option value="flutterwave" :selected="$filters['provider'] === 'flutterwave'">Flutterwave</flux:select.option>
        </flux:select>
        <flux:button type="submit" variant="primary" icon="funnel">Filter</flux:button>
    </form>

    <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
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
                    <tr>
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
                            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $payment->status === 'successful' ? 'bg-emerald-50 text-emerald-700' : ($payment->status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700') }}">
                                {{ str_replace('_', ' ', $payment->status) }}
                            </span>
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
</x-layouts.admin>
