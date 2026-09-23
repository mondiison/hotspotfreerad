<div class="space-y-6">
    @if ($statusMessage)
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $statusMessage }}
        </div>
    @endif

    @if ($errorMessage)
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="flex items-center gap-2">
        @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label)
            <button
                type="button"
                wire:click="$set('statusFilter', '{{ $value }}')"
                class="rounded-md px-3 py-1.5 text-xs font-medium {{ $statusFilter === $value ? 'bg-zinc-950 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'border border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <section class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
        @if ($withdrawals->isEmpty())
            <p class="px-6 py-6 text-sm text-zinc-500 dark:text-zinc-400">No withdrawal requests here.</p>
        @else
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($withdrawals as $withdrawal)
                    <div class="px-6 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">{{ $withdrawal->tenant->company_name }} — {{ $withdrawal->wallet?->currency ?? 'NGN' }} {{ number_format($withdrawal->amount, 2) }}</p>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $withdrawal->bank_name }} — {{ $withdrawal->account_number }} — {{ $withdrawal->account_name }}</p>
                                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Requested by {{ $withdrawal->requestedBy?->name }} {{ $withdrawal->created_at->diffForHumans() }}</p>
                                @if ($withdrawal->processedBy)
                                    <p class="text-xs text-zinc-400 dark:text-zinc-500">Last updated by {{ $withdrawal->processedBy->name }} {{ $withdrawal->processed_at?->diffForHumans() }}</p>
                                @endif
                                @if ($withdrawal->admin_notes)
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Note: {{ $withdrawal->admin_notes }}</p>
                                @endif
                            </div>
                            <flux:badge :color="match($withdrawal->status) { 'paid' => 'emerald', 'approved' => 'blue', 'rejected' => 'red', default => 'amber' }">
                                {{ ucfirst($withdrawal->status) }}
                            </flux:badge>
                        </div>

                        @if (in_array($withdrawal->status, ['pending', 'approved'], true))
                            <div class="mt-3">
                                @if ($notesForId === $withdrawal->id)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:input wire:model="notes" placeholder="Optional note" size="sm" class="max-w-xs" />
                                        @if ($withdrawal->status === 'pending')
                                            <flux:button type="button" wire:click="approve({{ $withdrawal->id }})" variant="outline" size="sm">Approve</flux:button>
                                            <flux:button type="button" wire:click="reject({{ $withdrawal->id }})" variant="danger" size="sm">Reject</flux:button>
                                        @endif
                                        <flux:button type="button" wire:click="markPaid({{ $withdrawal->id }})" variant="primary" size="sm">Mark paid</flux:button>
                                        <flux:button type="button" wire:click="$set('notesForId', null)" variant="ghost" size="sm">Cancel</flux:button>
                                    </div>
                                @else
                                    <flux:button type="button" wire:click="startNotes({{ $withdrawal->id }})" variant="outline" size="sm">Review</flux:button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="px-6 py-4">
                {{ $withdrawals->links() }}
            </div>
        @endif
    </section>
</div>
