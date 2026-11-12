<div>
    <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div class="space-y-2">
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            New voucher batch
        </flux:button>
    </div>

    <section class="mb-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
        @foreach ([
            ['label' => 'Vouchers', 'value' => $summary['total'], 'status' => ''],
            ['label' => 'Unused', 'value' => $summary['unused'], 'status' => 'unused'],
            ['label' => 'Sold', 'value' => $summary['sold'], 'status' => 'sold'],
            ['label' => 'Used', 'value' => $summary['used'], 'status' => 'used'],
            ['label' => 'Voided', 'value' => $summary['void'], 'status' => 'void'],
        ] as $stat)
            <button
                type="button"
                wire:click="filterBy('{{ $stat['status'] }}')"
                class="rounded-lg border px-4 py-3 text-left shadow-sm transition hover:border-zinc-400 {{ $status === $stat['status'] ? 'border-zinc-950 bg-zinc-950 text-white' : 'border-zinc-200 bg-white text-zinc-950' }}"
            >
                <span class="block text-xs font-medium uppercase {{ $status === $stat['status'] ? 'text-zinc-300' : 'text-zinc-500' }}">{{ $stat['label'] }}</span>
                <span class="mt-2 block text-2xl font-semibold">{{ number_format($stat['value']) }}</span>
            </button>
        @endforeach
    </section>

    @if ($status)
        <div class="mb-4">
            <flux:button type="button" variant="outline" size="sm" icon="x-mark" wire:click="clearFilters">Clear filter</flux:button>
        </div>
    @endif

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-[900px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Batch</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 text-right font-medium">Codes</th>
                    <th class="px-4 py-3 text-right font-medium">Sold</th>
                    <th class="px-4 py-3 text-right font-medium">Used</th>
                    <th class="px-4 py-3 text-right font-medium">Unused</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($batches as $batch)
                    <tr wire:key="voucher-batch-{{ $batch->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $batch->name }}</p>
                            <p class="mt-1 font-mono text-xs text-zinc-500">{{ $batch->prefix ? $batch->prefix.'-' : 'No prefix' }}{{ $batch->code_length }} chars</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $batch->shop?->name ?? 'Deleted shop' }}</td>
                        <td class="px-4 py-3 text-zinc-600">{{ $batch->package?->name ?? 'Deleted package' }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($batch->vouchers_count) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($batch->sold_vouchers_count) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($batch->used_vouchers_count) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($batch->unused_vouchers_count) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" wire:click="inspect({{ $batch->id }})" variant="outline" size="sm" icon="magnifying-glass">Inspect</flux:button>
                                <flux:dropdown>
                                    <flux:button type="button" variant="outline" size="sm" icon="ellipsis-vertical" aria-label="More actions" />
                                    <flux:menu>
                                        <flux:menu.item href="{{ route('client.voucher-batches.print', $batch) }}" target="_blank" icon="printer">Print</flux:menu.item>
                                        <flux:menu.item href="{{ route('client.voucher-batches.print', ['voucherBatch' => $batch, 'columns' => 5, 'status' => 'unused']) }}" target="_blank" icon="squares-2x2">Compact</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center">
                            <p class="font-medium">No voucher batches yet.</p>
                            <p class="mt-1 text-sm text-zinc-500">Generate prepaid codes for walk-in customers.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">New voucher batch</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $batches->links() }}</div>

    <flux:modal wire:model.self="showGenerateModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">New voucher batch</flux:heading>
                <flux:text class="mt-2">Create one-time prepaid codes from an active package.</flux:text>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                @if ($shops->count() > 1)
                    <flux:field class="md:col-span-2">
                        <flux:label>Shop</flux:label>
                        <flux:select wire:model.live="shop_id" required>
                            <option value="">Select shop</option>
                            @foreach ($shops as $shop)
                                <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="shop_id" />
                    </flux:field>
                @endif

                <flux:field class="md:col-span-2">
                    <flux:label>Package</flux:label>
                    <flux:select wire:model="package_id" required>
                        <option value="">Select package</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}">{{ $package->name }} / {{ $package->currency }} {{ number_format($package->price, 0) }}</option>
                        @endforeach
                    </flux:select>
                    <flux:description>Voucher time, bandwidth, and data cap come from this package.</flux:description>
                    <flux:error name="package_id" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>Batch name</flux:label>
                    <flux:input wire:model.blur="name" placeholder="Weekend 1 day vouchers" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Quantity</flux:label>
                    <flux:input type="number" min="1" max="500" wire:model.blur="quantity" required />
                    <flux:error name="quantity" />
                </flux:field>

                <flux:field>
                    <flux:label>Code length</flux:label>
                    <flux:input type="number" min="6" max="16" wire:model.blur="code_length" required />
                    <flux:error name="code_length" />
                </flux:field>

                <flux:field>
                    <flux:label>Prefix (optional)</flux:label>
                    <flux:input wire:model.blur="prefix" placeholder="e.g. MMS" />
                    <flux:error name="prefix" />
                </flux:field>

                <flux:field>
                    <flux:label>Notes (optional)</flux:label>
                    <flux:input wire:model.blur="notes" placeholder="Printed at front desk" />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showGenerateModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Generate</span>
                    <span wire:loading wire:target="save">Generating...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showInspectModal" class="md:w-5xl" :dismissible="true" variant="flyout">
        @if ($selectedBatch)
            <div class="space-y-6">
                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                    <div>
                        <flux:heading level="2" size="lg">Voucher batch</flux:heading>
                        <flux:text class="mt-2 text-sm text-zinc-500">{{ $selectedBatch->name }} / {{ $selectedBatch->shop?->name ?? 'Deleted shop' }}</flux:text>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:button href="{{ route('client.voucher-batches.print', ['voucherBatch' => $selectedBatch, 'status' => 'unused', 'columns' => 5]) }}" target="_blank" variant="outline" icon="printer">
                            Print unused
                        </flux:button>
                        @if ($selectedBatch->unused_vouchers_count > 0)
                            <flux:button type="button" variant="danger" icon="shield-exclamation" wire:click="confirmVoid({{ $selectedBatch->id }})">Void unused</flux:button>
                        @endif
                        <flux:button type="button" variant="ghost" wire:click="closeInspect">Close</flux:button>
                    </div>
                </div>

                <section class="grid gap-3 md:grid-cols-4">
                    @foreach ([
                        ['label' => 'Generated', 'value' => $selectedBatch->vouchers_count],
                        ['label' => 'Unused', 'value' => $selectedBatch->unused_vouchers_count],
                        ['label' => 'Sold', 'value' => $selectedBatch->sold_vouchers_count],
                        ['label' => 'Used', 'value' => $selectedBatch->used_vouchers_count],
                    ] as $stat)
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase text-zinc-500">{{ $stat['label'] }}</p>
                            <p class="mt-2 text-xl font-semibold">{{ number_format($stat['value']) }}</p>
                        </div>
                    @endforeach
                </section>

                <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white">
                    <table class="min-w-[900px] w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                            <tr>
                                <th class="px-4 py-3 font-medium">Code</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Sale</th>
                                <th class="px-4 py-3 font-medium">Used at</th>
                                <th class="px-4 py-3 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @forelse ($selectedBatch->vouchers as $voucher)
                                <tr wire:key="voucher-detail-{{ $voucher->id }}">
                                    <td class="px-4 py-3"><p class="font-mono text-xs font-semibold">{{ $voucher->code }}</p></td>
                                    <td class="px-4 py-3">
                                        <flux:badge :color="$voucher->status === 'unused' ? 'green' : ($voucher->status === 'sold' ? 'amber' : ($voucher->status === 'void' ? 'red' : 'zinc'))">
                                            {{ ucfirst($voucher->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($voucher->sold_at)
                                            <p class="font-medium">{{ $voucher->package?->currency ?? 'NGN' }} {{ number_format((float) $voucher->sale_amount, 2) }}</p>
                                        @else
                                            <span class="text-zinc-400">Not sold</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $voucher->used_at?->format('M j, Y g:i A') ?? '-' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($voucher->status === 'unused')
                                            <flux:button type="button" size="sm" variant="outline" icon="banknotes" wire:click="confirmSale({{ $voucher->id }})">Mark sold</flux:button>
                                        @elseif ($voucher->status === 'sold')
                                            <flux:button type="button" size="sm" variant="outline" icon="arrow-uturn-left" wire:click="confirmReverseSale({{ $voucher->id }})">Reverse sale</flux:button>
                                        @else
                                            <span class="text-xs text-zinc-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No voucher codes in this batch.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model.self="showSaleModal" class="md:w-xl" :dismissible="true">
        <form wire:submit="markSold" class="space-y-5">
            <div>
                <flux:heading size="lg">Mark voucher sold</flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">Record a front-desk or cash sale before the customer redeems it.</flux:text>
            </div>

            <flux:error name="sale_voucher_id" />

            <div class="grid gap-4">
                <flux:field>
                    <flux:label>Sale amount</flux:label>
                    <flux:input type="number" min="0" step="0.01" wire:model.blur="sale_amount" required />
                    <flux:error name="sale_amount" />
                </flux:field>

                <flux:field>
                    <flux:label>Reference (optional)</flux:label>
                    <flux:input wire:model.blur="sale_reference" placeholder="Receipt number" />
                    <flux:error name="sale_reference" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showSaleModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="markSold">
                    <span wire:loading.remove wire:target="markSold">Mark sold</span>
                    <span wire:loading wire:target="markSold">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showReverseSaleModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Reverse voucher sale?</flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">This clears the recorded sale and returns the voucher to unused stock.</flux:text>
            </div>

            <flux:error name="reverse_sale_voucher_id" />

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showReverseSaleModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="arrow-uturn-left" wire:click="reverseSale">Reverse sale</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showVoidModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Void unused vouchers?</flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">This disables every unused code in the batch. Already-sold or already-used vouchers are not changed.</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showVoidModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="shield-exclamation" wire:click="voidUnused">Void unused</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
