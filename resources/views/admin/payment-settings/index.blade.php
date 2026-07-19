<x-layouts.admin title="Payment Setup" heading="Payment Setup" subheading="Connect each shop to its own Flutterwave account for customer hotspot payments.">
    <section class="grid gap-4 md:grid-cols-3">
        @foreach ([
            ['label' => 'Shops', 'value' => number_format($summary['shops']), 'hint' => 'Locations this admin can manage'],
            ['label' => 'Payments configured', 'value' => number_format($summary['configured']), 'hint' => 'Client ID and client secret saved'],
            ['label' => 'Webhooks ready', 'value' => number_format($summary['webhook_ready']), 'hint' => 'Webhook secret hash saved'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm leading-6 text-blue-900">
        Customer hotspot payments use the shop's Flutterwave v4 client ID and client secret. Platform Flutterwave settings are reserved for tenant subscription billing, so customer money only goes through a tenant shop after that shop is configured.
    </section>

    <div class="mt-6 grid gap-5 xl:grid-cols-2">
        @forelse ($shops as $shop)
            <livewire:admin.payment-settings-card :shop="$shop" :key="'payment-settings-'.$shop->id" />
        @empty
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center text-zinc-500 xl:col-span-2">
                No shops have been created yet.
            </div>
        @endforelse
    </div>
</x-layouts.admin>
