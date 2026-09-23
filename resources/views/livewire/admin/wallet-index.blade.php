<div class="space-y-6">
    @if ($statusMessage)
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $statusMessage }}
        </div>
    @endif

    @if (! $tenant->wallet_enabled)
        <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-sm">
            <h2 class="text-base font-semibold">Enable the platform wallet</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Skip setting up your own payment gateway account. Customer payments route through the MMS Radius platform gateway instead, and your share accumulates here as a wallet balance you can withdraw whenever you like.</p>

            @if ($canEnable)
                <flux:button type="button" wire:click="enableWallet" variant="primary" class="mt-4" wire:loading.attr="disabled" wire:target="enableWallet">
                    <span wire:loading.remove wire:target="enableWallet">Enable Wallet</span>
                    <span wire:loading wire:target="enableWallet">Enabling...</span>
                </flux:button>
            @else
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $canEnableError ?? 'This feature is not available on your current platform plan.' }}
                </div>
                <flux:button href="{{ route('admin.billing.index') }}" wire:navigate variant="outline" class="mt-3">View platform plans</flux:button>
            @endif
        </section>
    @else
        <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Wallet balance</p>
                    <p class="mt-1 text-3xl font-semibold">{{ $tenant->wallet?->currency ?? 'NGN' }} {{ number_format($balance, 2) }}</p>
                </div>
                <flux:badge color="emerald">Wallet active</flux:badge>
            </div>
        </section>

        <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-sm">
            <h2 class="text-base font-semibold">Who pays the platform commission?</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Choose whether you absorb the platform's cut, or the customer pays it on top of the package price.</p>

            <div class="mt-4 flex flex-col gap-2">
                <label class="flex items-start gap-3 text-sm">
                    <input type="radio" wire:model="commissionBearer" value="tenant" class="mt-1">
                    <span>
                        <span class="font-medium">I'll absorb it</span>
                        <span class="block text-zinc-500 dark:text-zinc-400">Customer pays the package price as listed; your wallet nets price minus commission.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 text-sm">
                    <input type="radio" wire:model="commissionBearer" value="customer" class="mt-1">
                    <span>
                        <span class="font-medium">Pass it to the customer</span>
                        <span class="block text-zinc-500 dark:text-zinc-400">Customer is charged the package price plus commission; your wallet nets the full package price.</span>
                    </span>
                </label>
            </div>

            <flux:button type="button" wire:click="saveCommissionBearer" variant="outline" size="sm" class="mt-4">Save</flux:button>
        </section>

        <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-sm">
            <h2 class="text-base font-semibold">Request a withdrawal</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Requests are reviewed and paid out manually by bank transfer. The amount is set aside from your balance as soon as you submit the request.</p>

            <form wire:submit="requestWithdrawal" class="mt-4 grid gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>Amount</flux:label>
                    <flux:input type="number" step="0.01" min="1" wire:model="withdrawAmount" placeholder="0.00" />
                    <flux:error name="withdrawAmount" />
                </flux:field>
                <flux:field>
                    <flux:label>Bank name</flux:label>
                    <flux:input wire:model="bankName" placeholder="e.g. GTBank" />
                    <flux:error name="bankName" />
                </flux:field>
                <flux:field>
                    <flux:label>Account number</flux:label>
                    <flux:input wire:model="accountNumber" placeholder="0123456789" />
                    <flux:error name="accountNumber" />
                </flux:field>
                <flux:field>
                    <flux:label>Account name</flux:label>
                    <flux:input wire:model="accountName" placeholder="As it appears on the account" />
                    <flux:error name="accountName" />
                </flux:field>

                <div class="md:col-span-2">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="requestWithdrawal">
                        <span wire:loading.remove wire:target="requestWithdrawal">Submit request</span>
                        <span wire:loading wire:target="requestWithdrawal">Submitting...</span>
                    </flux:button>
                </div>
            </form>
        </section>

        @if ($withdrawals->isNotEmpty())
            <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                <div class="border-b border-zinc-200 dark:border-zinc-700 px-6 py-4">
                    <h2 class="text-base font-semibold">Withdrawal requests</h2>
                </div>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($withdrawals as $withdrawal)
                        <div class="flex items-center justify-between px-6 py-3 text-sm">
                            <div>
                                <p class="font-medium">{{ $tenant->wallet?->currency ?? 'NGN' }} {{ number_format($withdrawal->amount, 2) }}</p>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ $withdrawal->bank_name }} — {{ $withdrawal->account_number }} — {{ $withdrawal->created_at->diffForHumans() }}</p>
                            </div>
                            <flux:badge :color="match($withdrawal->status) { 'paid' => 'emerald', 'approved' => 'blue', 'rejected' => 'red', default => 'amber' }">
                                {{ ucfirst($withdrawal->status) }}
                            </flux:badge>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
            <div class="border-b border-zinc-200 dark:border-zinc-700 px-6 py-4">
                <h2 class="text-base font-semibold">Transaction history</h2>
            </div>

            @if ($transactions && $transactions->isNotEmpty())
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($transactions as $transaction)
                        <div class="flex items-center justify-between px-6 py-3 text-sm">
                            <div>
                                <p class="font-medium">{{ $transaction->description }}</p>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ $transaction->created_at->diffForHumans() }}</p>
                            </div>
                            <p class="font-mono font-medium {{ $transaction->type === 'credit' ? 'text-emerald-600' : 'text-red-600' }}">
                                {{ $transaction->type === 'credit' ? '+' : '-' }}{{ number_format($transaction->amount, 2) }}
                            </p>
                        </div>
                    @endforeach
                </div>
                <div class="px-6 py-4">
                    {{ $transactions->links() }}
                </div>
            @else
                <p class="px-6 py-6 text-sm text-zinc-500 dark:text-zinc-400">No transactions yet.</p>
            @endif
        </section>
    @endif
</div>
