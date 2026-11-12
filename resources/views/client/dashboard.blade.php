<x-layouts.client title="Dashboard">
    @php $subscription = $client->currentBillingSubscription; @endphp

    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-5">
        <p class="text-sm text-zinc-500">Subscription status</p>
        <p class="mt-1 text-lg font-semibold">
            {{ str($subscription?->status ?? 'none')->replace('_', ' ')->title() }}
            @if ($subscription?->status === 'trialing' && $subscription->trial_ends_at)
                &middot; trial ends {{ $subscription->trial_ends_at->diffForHumans() }}
            @endif
        </p>
        <a href="{{ route('client.billing.index') }}" class="mt-2 inline-block text-sm text-emerald-700 hover:underline">Manage billing &rarr;</a>
    </div>

    <h2 class="mb-3 text-sm font-semibold tracking-wide text-zinc-500 uppercase">Your shops</h2>
    <div class="grid gap-4 sm:grid-cols-2">
        @forelse ($client->shops as $shop)
            <div class="rounded-lg border border-zinc-200 bg-white p-5">
                <p class="font-medium">{{ $shop->name }}</p>
                <p class="text-sm text-zinc-500">{{ $shop->location_city }}</p>

                <div class="mt-3 space-y-1">
                    @forelse ($shop->routers as $router)
                        <a href="{{ route('client.routers.show', $router) }}" class="block rounded-md border border-zinc-200 px-3 py-2 text-sm hover:border-zinc-400">
                            {{ $router->name }}
                            <span class="float-right text-xs text-zinc-400">{{ $router->detected_status }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-zinc-400">No router set up yet.</p>
                    @endforelse
                </div>
            </div>
        @empty
            <p class="text-sm text-zinc-500">No shops assigned yet.</p>
        @endforelse
    </div>
</x-layouts.client>
