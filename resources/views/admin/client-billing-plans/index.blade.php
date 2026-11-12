<x-layouts.admin
    title="Client plans"
    heading="Client plans"
    subheading="Plans you define yourself, sold to your own Clients -- separate from the platform billing plans a super admin manages."
>
    <div class="mb-4 flex justify-end">
        <flux:button :href="route('admin.client-billing-plans.create')" wire:navigate variant="primary">New plan</flux:button>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-left dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Clients</th>
                    <th class="px-4 py-2">Active</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($plans as $plan)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $plan->name }}</td>
                        <td class="px-4 py-2">{{ $plan->currency }} {{ number_format($plan->price, 2) }}</td>
                        <td class="px-4 py-2">{{ $plan->client_subscriptions_count }}</td>
                        <td class="px-4 py-2">
                            <flux:badge :color="$plan->is_active ? 'lime' : 'zinc'">{{ $plan->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <flux:button :href="route('admin.client-billing-plans.edit', $plan)" wire:navigate size="sm" variant="ghost">Edit</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-zinc-500">No client plans yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.admin>
