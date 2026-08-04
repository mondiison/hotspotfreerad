<x-layouts.admin title="Payment Setup" heading="Payment Setup" subheading="Connect tenant-owned collection accounts for customer hotspot payments.">
    <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-semibold">{{ $gateway['label'] }}</h2>
                        <flux:badge color="blue" size="sm">Tenant collections</flux:badge>
                    </div>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600">{{ $gateway['summary'] }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-3">
                @foreach ($gateway['channels'] as $channel)
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                        <p class="text-sm font-semibold text-zinc-950">{{ $channel['label'] }}</p>
                        <p class="mt-1 text-xs font-medium text-zinc-500">{{ $channel['requires'] }}</p>
                        <p class="mt-2 text-xs leading-5 text-zinc-600">{{ $channel['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold">Global URLs</h2>
            <p class="mt-1 text-sm leading-6 text-zinc-500">Use these once in the active payment gateway dashboard. They do not change per tenant.</p>

            @foreach ([['label' => 'Payment webhook', 'url' => $webhookUrl], ['label' => 'Payment callback', 'url' => $callbackUrl]] as $endpoint)
                <div class="mt-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold uppercase text-zinc-500">{{ $endpoint['label'] }}</p>
                        <button
                            type="button"
                            class="text-xs font-semibold text-zinc-950 underline decoration-zinc-300 underline-offset-4"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard?.writeText('{{ $endpoint['url'] }}'); copied = true; setTimeout(() => copied = false, 1400)"
                            x-text="copied ? 'Copied' : 'Copy'"
                        >Copy</button>
                    </div>
                    <div class="mt-2 max-w-full overflow-x-auto rounded-md bg-zinc-950 px-3 py-2 font-mono text-xs text-white">
                        <span class="whitespace-nowrap">{{ $endpoint['url'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mt-5 grid gap-4 md:grid-cols-4">
        @foreach ([
            ['label' => 'Shops', 'value' => number_format($summary['shops']), 'hint' => 'Locations this admin can manage'],
            ['label' => 'OPay/transfer', 'value' => number_format($summary['configured']), 'hint' => 'v4 Client ID and secret saved'],
            ['label' => 'Card checkout', 'value' => number_format($summary['card_ready']), 'hint' => 'v3 Secret Key saved'],
            ['label' => 'Webhooks ready', 'value' => number_format($summary['webhook_ready']), 'hint' => 'Webhook secret hash saved'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
        Customer money only goes through the configured tenant shop gateway. Platform billing is reserved for tenant subscription payments, so tenants must connect their own shop gateway before online customer checkout is offered.
    </section>

    <div class="mt-6 grid min-w-0 gap-5 xl:grid-cols-2">
        @forelse ($shops as $shop)
            <livewire:admin.payment-settings-card :shop="$shop" :key="'payment-settings-'.$shop->id" />
        @empty
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center text-zinc-500 xl:col-span-2">
                No shops have been created yet.
            </div>
        @endforelse
    </div>
</x-layouts.admin>
