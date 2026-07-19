<x-layouts.admin
    title="Billing"
    heading="Billing"
    subheading="Platform subscriptions are separate from hotspot customer payments."
>
    @if (auth()->user()->isSuperAdmin())
        <livewire:admin.billing-plans-manager />

        <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-5">
            <h2 class="font-semibold">Assign Tenant Subscription</h2>
            <p class="mt-1 text-sm text-zinc-500">Use this for manual billing status while platform Flutterwave subscription checkout is being added.</p>

            <form method="POST" action="{{ route('admin.billing.subscriptions.store') }}" class="mt-5 grid gap-4 md:grid-cols-3">
                @csrf
                <flux:field>
                    <flux:label>Tenant</flux:label>
                    <flux:select name="tenant_id" required>
                        <option value="">Select tenant</option>
                        @foreach ($tenants as $tenantOption)
                            <option value="{{ $tenantOption->id }}" @selected(old('tenant_id') == $tenantOption->id)>{{ $tenantOption->company_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="tenant_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Billing plan</flux:label>
                    <flux:select name="billing_plan_id" required>
                        <option value="">Select plan</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected(old('billing_plan_id') == $plan->id)>{{ $plan->name }} - {{ $plan->currency }} {{ number_format($plan->monthly_price, 2) }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="billing_plan_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Status</flux:label>
                    <flux:select name="status" required>
                        @foreach (['trialing', 'active', 'past_due', 'canceled'] as $status)
                            <option value="{{ $status }}" @selected(old('status', 'trialing') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="status" />
                </flux:field>

                <flux:field>
                    <flux:label>Trial ends</flux:label>
                    <flux:input type="datetime-local" name="trial_ends_at" value="{{ old('trial_ends_at') }}" />
                    <flux:error name="trial_ends_at" />
                </flux:field>

                <flux:field>
                    <flux:label>Period starts</flux:label>
                    <flux:input type="datetime-local" name="current_period_starts_at" value="{{ old('current_period_starts_at') }}" />
                    <flux:error name="current_period_starts_at" />
                </flux:field>

                <flux:field>
                    <flux:label>Period ends</flux:label>
                    <flux:input type="datetime-local" name="current_period_ends_at" value="{{ old('current_period_ends_at') }}" />
                    <flux:error name="current_period_ends_at" />
                </flux:field>

                <div class="md:col-span-3">
                    <flux:button type="submit" variant="primary" icon="check">Record Subscription</flux:button>
                </div>
            </form>
        </section>

        <section class="mt-6 overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <div class="border-b border-zinc-200 px-4 py-3">
                <h2 class="font-semibold">Tenant Billing History</h2>
            </div>
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Tenant</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Amount</th>
                        <th class="px-4 py-3 font-medium">Current period</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($subscriptions as $subscription)
                        <tr>
                            <td class="px-4 py-3">{{ $subscription->tenant->company_name }}</td>
                            <td class="px-4 py-3">{{ $subscription->billingPlan->name }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', ucfirst($subscription->status)) }}</td>
                            <td class="px-4 py-3">{{ $subscription->currency }} {{ number_format($subscription->amount, 2) }}</td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ optional($subscription->current_period_starts_at)->format('M j, Y') ?? 'Not started' }}
                                -
                                {{ optional($subscription->current_period_ends_at)->format('M j, Y') ?? 'Open' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No platform billing subscriptions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <div class="mt-4">{{ $subscriptions->links() }}</div>
    @else
        <section class="rounded-lg border border-zinc-200 bg-white p-6">
            <p class="text-sm font-medium text-zinc-500">{{ $tenant->company_name }}</p>
            <h2 class="mt-2 text-2xl font-semibold">
                {{ $currentSubscription?->billingPlan?->name ?? 'No platform plan assigned' }}
            </h2>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600">
                This is your SaaS subscription to the hotspot platform. It is separate from your hotspot customer payments, which settle into the Flutterwave account configured on each shop.
            </p>

            <dl class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-4">
                    <dt class="text-sm text-zinc-500">Status</dt>
                    <dd class="mt-1 font-semibold">{{ $currentSubscription ? str_replace('_', ' ', ucfirst($currentSubscription->status)) : 'Not assigned' }}</dd>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4">
                    <dt class="text-sm text-zinc-500">Monthly amount</dt>
                    <dd class="mt-1 font-semibold">{{ $currentSubscription ? $currentSubscription->currency.' '.number_format($currentSubscription->amount, 2) : 'Pending' }}</dd>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4">
                    <dt class="text-sm text-zinc-500">Renews</dt>
                    <dd class="mt-1 font-semibold">{{ optional($currentSubscription?->current_period_ends_at)->format('M j, Y') ?? 'Not set' }}</dd>
                </div>
            </dl>
        </section>

        <section class="mt-6 rounded-lg border border-zinc-200 bg-white p-6">
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                <div>
                    <h2 class="font-semibold">Choose Platform Plan</h2>
                    <p class="mt-1 text-sm text-zinc-500">Pay the platform subscription from here. Hotspot customer collections remain on your shop Flutterwave account.</p>
                </div>
                @unless ($platformFlutterwaveConfigured)
                    <flux:badge color="amber">Platform checkout not configured</flux:badge>
                @endunless
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                @foreach ($plans as $plan)
                    <section class="rounded-lg border border-zinc-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">{{ $plan->name }}</h3>
                                <p class="mt-1 text-sm text-zinc-500">{{ $plan->currency }} {{ number_format($plan->monthly_price, 2) }} / month</p>
                            </div>
                            @if ($currentSubscription?->billing_plan_id === $plan->id && $currentSubscription?->status === 'active')
                                <flux:badge color="emerald" size="sm">Current</flux:badge>
                            @endif
                        </div>
                        @if ($plan->features)
                            <ul class="mt-3 space-y-1 text-sm text-zinc-600">
                                @foreach ($plan->features as $feature)
                                    <li>{{ $feature }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <form method="POST" action="{{ route('admin.billing.payments.checkout') }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="billing_plan_id" value="{{ $plan->id }}">
                            <flux:button type="submit" class="w-full" variant="primary" :disabled="! $platformFlutterwaveConfigured">
                                {{ $currentSubscription?->billing_plan_id === $plan->id ? 'Renew Plan' : 'Pay for Plan' }}
                            </flux:button>
                        </form>
                    </section>
                @endforeach
            </div>
        </section>

        <section class="mt-6 overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <div class="border-b border-zinc-200 px-4 py-3">
                <h2 class="font-semibold">Billing History</h2>
            </div>
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Amount</th>
                        <th class="px-4 py-3 font-medium">Period</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($subscriptions as $subscription)
                        <tr>
                            <td class="px-4 py-3">{{ $subscription->billingPlan->name }}</td>
                            <td class="px-4 py-3">{{ str_replace('_', ' ', ucfirst($subscription->status)) }}</td>
                            <td class="px-4 py-3">{{ $subscription->currency }} {{ number_format($subscription->amount, 2) }}</td>
                            <td class="px-4 py-3 text-zinc-500">{{ optional($subscription->current_period_starts_at)->format('M j, Y') ?? 'Not started' }} - {{ optional($subscription->current_period_ends_at)->format('M j, Y') ?? 'Open' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500">No billing history yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <div class="mt-4">{{ $subscriptions->links() }}</div>
    @endif
</x-layouts.admin>
