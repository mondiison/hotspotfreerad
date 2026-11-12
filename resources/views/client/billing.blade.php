<x-layouts.client title="Billing">
    @php $subscription = $client->currentBillingSubscription; @endphp

    <div class="rounded-lg border border-zinc-200 bg-white p-5">
        <p class="text-sm text-zinc-500">Plan</p>
        <p class="text-lg font-semibold">{{ $subscription?->clientBillingPlan?->name ?? 'No plan' }}</p>
        <p class="text-sm text-zinc-500">{{ $subscription?->currency }} {{ number_format($subscription?->amount ?? $subscription?->clientBillingPlan?->price ?? 0, 2) }} / cycle</p>

        <p class="mt-4 text-sm text-zinc-500">Status</p>
        <p class="font-medium">
            {{ str($subscription?->status ?? 'none')->replace('_', ' ')->title() }}
            @if ($subscription?->status === 'trialing' && $subscription->trial_ends_at)
                &middot; trial ends {{ $subscription->trial_ends_at->format('M j, Y') }}
            @elseif ($subscription?->status === 'active' && $subscription->current_period_ends_at)
                &middot; renews {{ $subscription->current_period_ends_at->format('M j, Y') }}
            @endif
        </p>

        @if (in_array($subscription?->status, ['trialing', 'past_due'], true) || ($subscription?->status === 'active' && $subscription->current_period_ends_at?->isPast()))
            <form method="POST" action="{{ route('client.billing.checkout') }}" class="mt-5">
                @csrf
                @if (! $gateway)
                    <p class="text-sm text-amber-600">Your provider hasn't set up a payment method yet. Contact them directly.</p>
                @else
                    <flux:button type="submit" variant="primary">Pay now</flux:button>
                @endif
            </form>
        @endif
    </div>
</x-layouts.client>
