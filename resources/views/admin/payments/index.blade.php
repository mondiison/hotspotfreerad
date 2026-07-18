<x-layouts.admin title="Payments" heading="Payments" subheading="Customer hotspot payment attempts, confirmations, and provisioning status.">
    <section class="grid gap-4 md:grid-cols-4">
        @foreach ([
            ['label' => 'Transactions', 'value' => number_format($summary['count']), 'hint' => 'Matching current filters'],
            ['label' => 'Successful', 'value' => number_format($summary['successful_count']), 'hint' => 'Confirmed customer payments'],
            ['label' => 'Pending', 'value' => number_format($summary['pending_count']), 'hint' => 'Awaiting payment confirmation'],
            ['label' => 'Revenue', 'value' => 'NGN '.number_format($summary['successful_revenue'], 2), 'hint' => 'Successful payments only'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <form method="GET" class="mt-6 grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 md:grid-cols-[1fr_180px_180px_auto]">
        <input name="search" value="{{ request('search') }}" placeholder="Search ref, customer, shop, package" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
        <select name="status" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <option value="">All statuses</option>
            @foreach (['pending', 'successful', 'failed', 'verification_failed'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
            @endforeach
        </select>
        <select name="provider" class="rounded-md border border-zinc-300 px-3 py-2 text-sm">
            <option value="">All providers</option>
            <option value="flutterwave" @selected(request('provider') === 'flutterwave')>Flutterwave</option>
        </select>
        <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Filter</button>
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
                    <th class="px-4 py-3 text-right font-medium">Amount</th>
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
                        <td class="px-4 py-3 text-right font-medium">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No payments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>
</x-layouts.admin>
