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
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Settlement account</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Withdrawals are paid to this account. Verify it once here instead of retyping it every time you request a withdrawal.</p>
                </div>
                @if (! $editingSettlementAccount)
                    <flux:button type="button" wire:click="startEditingSettlementAccount" variant="outline" size="sm">Update settlement account</flux:button>
                @endif
            </div>

            @if (! $editingSettlementAccount)
                <div class="mt-4 flex items-center gap-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <flux:icon name="check-badge" class="size-5 shrink-0" />
                    <div>
                        <p class="font-medium">{{ $tenant->settlement_account_name }}</p>
                        <p>{{ $tenant->settlement_bank_name }} — {{ $tenant->settlement_account_number }}</p>
                        <p class="text-xs text-emerald-700">Verified {{ $tenant->settlement_verified_at->diffForHumans() }}</p>
                    </div>
                </div>
            @else
                @if (! $bankVerificationAvailable)
                    <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        Bank account verification isn't available yet — ask a super admin to configure a platform payment gateway (Paystack, Monnify, or Flutterwave) under "Default Platform Gateway" on the Billing page first.
                    </div>
                @else
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>Bank</flux:label>
                            <flux:select wire:model.live="selectedBankCode">
                                <flux:select.option value="">Select bank</flux:select.option>
                                @foreach ($banks as $bank)
                                    <flux:select.option value="{{ $bank['code'] }}">{{ $bank['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="selectedBankCode" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Account number</flux:label>
                            <flux:input wire:model.live.debounce.500ms="settlementAccountNumber" placeholder="0123456789" maxlength="10" />
                            <flux:error name="settlementAccountNumber" />
                        </flux:field>

                        <div class="md:col-span-2">
                            @if ($resolvedAccountName)
                                <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                                    <span>Verified: <span class="font-semibold">{{ $resolvedAccountName }}</span></span>
                                    <flux:button type="button" wire:click="saveSettlementAccount" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="saveSettlementAccount">Save settlement account</flux:button>
                                </div>
                            @else
                                <flux:button type="button" wire:click="verifySettlementAccount" variant="outline" wire:loading.attr="disabled" wire:target="verifySettlementAccount">
                                    <span wire:loading.remove wire:target="verifySettlementAccount">Verify account</span>
                                    <span wire:loading wire:target="verifySettlementAccount">Verifying...</span>
                                </flux:button>
                                @if ($verifyError)
                                    <p class="mt-2 text-sm text-red-600">{{ $verifyError }}</p>
                                @endif
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </section>

        <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-sm">
            <h2 class="text-base font-semibold">Request a withdrawal</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Requests are reviewed and paid out manually to your saved settlement account above. The amount is set aside from your balance as soon as you submit the request.</p>

            @if (! $tenant->hasVerifiedSettlementAccount())
                <p class="mt-4 text-sm text-amber-700">Save and verify a settlement account above before requesting a withdrawal.</p>
            @else
                <form wire:submit="requestWithdrawal" class="mt-4 flex flex-wrap items-end gap-4">
                    <flux:field class="max-w-xs">
                        <flux:label>Amount</flux:label>
                        <flux:input type="number" step="0.01" min="1" wire:model="withdrawAmount" placeholder="0.00" />
                        <flux:error name="withdrawAmount" />
                    </flux:field>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="requestWithdrawal">
                        <span wire:loading.remove wire:target="requestWithdrawal">Submit request</span>
                        <span wire:loading wire:target="requestWithdrawal">Submitting...</span>
                    </flux:button>
                </form>
            @endif
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
