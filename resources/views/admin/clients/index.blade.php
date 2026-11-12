<x-layouts.admin
    title="Clients"
    heading="Clients"
    subheading="Each Client owns one or more of your shops and pays you directly for access -- give them a free trial, then they pay to keep going."
>
    @unless (auth()->user()->isSuperAdmin())
        @if (auth()->user()->tenant?->client_reselling_enabled)
            <div class="mb-4 flex items-center justify-between rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                <span>
                    Client reselling is enabled -- commission {{ number_format((float) auth()->user()->tenant->client_commission_rate, 2) }}%, trial {{ auth()->user()->tenant->default_client_trial_days }} days.
                </span>
                <form method="POST" action="{{ route('admin.clients.reselling.disable') }}" onsubmit="return confirm('Disable client reselling? Existing Clients and their shops are unaffected.');">
                    @csrf
                    <flux:button type="submit" size="sm" variant="ghost">Disable</flux:button>
                </form>
            </div>

            @php
                $resellingTenant = auth()->user()->tenant;
                $resellingGatewaySettings = $resellingTenant->paymentGatewaySettings();
                $resellingGatewayOptions = ['monnify' => 'Monnify', 'paystack' => 'Paystack', 'squad' => 'Squad', 'stripe' => 'Stripe'];
                $resellingConfiguredGateways = collect($resellingGatewayOptions)->keys()->filter(function (string $gateway) use ($resellingGatewaySettings) {
                    $secretKeys = \App\Support\PaymentGatewayCatalog::secretFieldKeys($gateway);

                    return $secretKeys !== [] && collect($secretKeys)->every(fn (string $key) => filled($resellingGatewaySettings[$gateway][$key] ?? null));
                });
            @endphp

            <div class="mb-4 rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <form method="POST" action="{{ route('admin.clients.checkout-gateway') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <flux:field class="min-w-[220px]">
                        <flux:label>Charge Clients through</flux:label>
                        <flux:select name="client_checkout_gateway">
                            <flux:select.option value="">Not set yet</flux:select.option>
                            @foreach ($resellingGatewayOptions as $value => $label)
                                <flux:select.option :value="$value" :selected="$resellingTenant->client_checkout_gateway === $value">
                                    {{ $label }}{{ $resellingConfiguredGateways->contains($value) ? '' : ' (no credentials saved)' }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>Only Monnify, Paystack, Squad and Stripe are supported for Client checkout. Save credentials for a gateway under Payment Setup on any of your shops first.</flux:description>
                    </flux:field>
                    <flux:button type="submit" size="sm" variant="primary">Save</flux:button>
                </form>
            </div>
        @elseif ($canEnableReselling === true)
            <div class="mb-4 flex items-center justify-between rounded-md border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                <span>Enable client reselling to onboard paying Clients with their own free trial.</span>
                <form method="POST" action="{{ route('admin.clients.reselling.enable') }}">
                    @csrf
                    <flux:button type="submit" size="sm" variant="primary">Enable client reselling</flux:button>
                </form>
            </div>
        @else
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                {{ $canEnableReselling }}
                <flux:button :href="route('admin.billing.index')" wire:navigate variant="outline" size="sm" class="ml-2">View platform plans</flux:button>
            </div>
        @endif
    @endunless

    <div class="mb-4 flex justify-end">
        <flux:button :href="route('admin.clients.create')" wire:navigate variant="primary">New client</flux:button>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-left dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Shops</th>
                    <th class="px-4 py-2">Plan</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($clients as $client)
                    @php $subscription = $client->currentBillingSubscription; @endphp
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $client->name }}</td>
                        <td class="px-4 py-2">{{ $client->shops->pluck('name')->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-2">{{ $subscription?->clientBillingPlan?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <flux:badge :color="match($subscription?->status) { 'active' => 'lime', 'trialing' => 'amber', 'past_due' => 'red', default => 'zinc' }">
                                {{ str($subscription?->status ?? 'none')->replace('_', ' ')->title() }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <flux:button :href="route('admin.clients.edit', $client)" wire:navigate size="sm" variant="ghost">Edit</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-zinc-500">No clients yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $clients->links() }}</div>
</x-layouts.admin>
