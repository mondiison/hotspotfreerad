<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold">Platform settlement account</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">The platform's own bank account -- needed once before the platform can be registered as a subaccount on a tenant's own gateway for automated commission collection.</p>
        </div>
        @if (! $editingSettlementAccount)
            <flux:button type="button" wire:click="startEditingSettlementAccount" variant="outline" size="sm">Update settlement account</flux:button>
        @endif
    </div>

    @if (! $editingSettlementAccount)
        <div class="mt-4 flex items-center gap-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <flux:icon name="check-badge" class="size-5 shrink-0" />
            <div>
                <p class="font-medium">{{ $account['account_name'] }}</p>
                <p>{{ $account['bank_name'] }} — {{ $account['account_number'] }}</p>
                <p class="text-xs text-emerald-700">Verified {{ $verifiedAt?->diffForHumans() }}</p>
            </div>
        </div>
    @else
        @if (! $bankVerificationAvailable)
            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Bank account verification isn't available yet -- configure a platform payment gateway (Paystack, Monnify, or Flutterwave) above first.
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
</div>
