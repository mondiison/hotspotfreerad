<div>
    <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div class="space-y-2">
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <flux:button href="{{ route('admin.vouchers.export', $exportQuery) }}" variant="outline" icon="arrow-down-tray">
                Export CSV
            </flux:button>
            <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
                Generate Vouchers
            </flux:button>
        </div>
    </div>

    <section class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['label' => 'Vouchers', 'value' => $summary['total'], 'hint' => 'All generated codes', 'status' => ''],
            ['label' => 'Unused', 'value' => $summary['unused'], 'hint' => 'Ready to sell or print', 'status' => 'unused'],
            ['label' => 'Used', 'value' => $summary['used'], 'hint' => 'Redeemed by devices', 'status' => 'used'],
            ['label' => 'Used this month', 'value' => $summary['used_this_month'], 'hint' => 'Monthly voucher activity', 'status' => 'used', 'action' => 'filterUsedThisMonth'],
            ['label' => 'Used in range', 'value' => $summary['used_in_filter'], 'hint' => 'Matches date/shop filters', 'status' => 'used'],
        ] as $stat)
            @php($isActiveStat = $status === $stat['status'] && (($stat['action'] ?? '') !== 'filterUsedThisMonth' || ($used_from === now()->startOfMonth()->toDateString() && $used_to === now()->endOfMonth()->toDateString())))
            <button
                type="button"
                wire:click="{{ $stat['action'] ?? "filterBy('{$stat['status']}')" }}"
                wire:loading.attr="disabled"
                wire:target="filterBy,filterUsedThisMonth"
                class="rounded-lg border px-4 py-3 text-left shadow-sm transition hover:border-zinc-400 {{ $isActiveStat ? 'border-zinc-950 bg-zinc-950 text-white' : 'border-zinc-200 bg-white text-zinc-950' }}"
            >
                <span class="block text-xs font-medium uppercase {{ $isActiveStat ? 'text-zinc-300' : 'text-zinc-500' }}">{{ $stat['label'] }}</span>
                <span class="mt-2 block text-2xl font-semibold">{{ number_format($stat['value']) }}</span>
                <span class="mt-1 block text-xs {{ $isActiveStat ? 'text-zinc-300' : 'text-zinc-500' }}">{{ $stat['hint'] }}</span>
            </button>
        @endforeach
    </section>

    <section class="mb-4 grid min-w-0 gap-3 rounded-lg border border-zinc-200 bg-white p-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_190px_190px_150px_150px_auto] [&>*]:min-w-0">
        <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search batch, shop, package, prefix" />
        <flux:select wire:model.live="shop">
            <flux:select.option value="">All shops</flux:select.option>
            @foreach ($shops as $shopOption)
                <flux:select.option value="{{ $shopOption->id }}">{{ $shopOption->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status">
            <flux:select.option value="">All batches</flux:select.option>
            <flux:select.option value="active">Active batches</flux:select.option>
            <flux:select.option value="exhausted">Fully used</flux:select.option>
            <flux:select.option value="unused">Has unused codes</flux:select.option>
            <flux:select.option value="used">Has used codes</flux:select.option>
        </flux:select>
        <flux:input type="date" wire:model.live="used_from" aria-label="Used from date" />
        <flux:input type="date" wire:model.live="used_to" aria-label="Used to date" />
        <flux:button type="button" variant="outline" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status,shop,used_from,used_to">
            Reset
        </flux:button>
    </section>

    <div wire:loading.flex wire:target="search,status,shop,used_from,used_to,clearFilters,filterBy,save" class="mb-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating vouchers...
    </div>

    <div wire:loading.flex wire:target="inspect" class="mb-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Loading voucher batch...
    </div>

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-[980px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Batch</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 text-right font-medium">Codes</th>
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
                        <td class="px-4 py-3">
                            <p>{{ $batch->shop?->name ?? 'Deleted shop' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $batch->shop?->tenant?->company_name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $batch->package?->name ?? 'Deleted package' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $batch->package?->speed_limit_profile }} / {{ $batch->package?->limit_uptime_seconds ? round($batch->package->limit_uptime_seconds / 86400, 1).' days' : 'No time' }}</p>
                        </td>
                        <td class="px-4 py-3 text-right">{{ number_format($batch->vouchers_count) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($batch->used_vouchers_count) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($batch->unused_vouchers_count) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" wire:click="inspect({{ $batch->id }})" wire:loading.attr="disabled" wire:target="inspect({{ $batch->id }})" variant="outline" size="sm" icon="magnifying-glass">
                                    Inspect
                                </flux:button>
                                <flux:button href="{{ route('admin.voucher-batches.print', $batch) }}" target="_blank" variant="outline" size="sm" icon="printer">
                                    Print
                                </flux:button>
                                <flux:button href="{{ route('admin.voucher-batches.print', ['voucherBatch' => $batch, 'columns' => 5, 'status' => 'unused']) }}" target="_blank" variant="ghost" size="sm" icon="squares-2x2">
                                    Compact
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center">
                            <p class="font-medium">No voucher batches found.</p>
                            <p class="mt-1 text-sm text-zinc-500">Generate prepaid voucher codes for walk-in hotspot customers.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Generate Vouchers</flux:button>
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
                <flux:heading size="lg">Generate Vouchers</flux:heading>
                <flux:text class="mt-2">Create one-time prepaid hotspot codes from an active hotspot package.</flux:text>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <flux:field class="md:col-span-2">
                    <flux:label>Shop</flux:label>
                    <flux:select wire:model.live="shop_id" required>
                        <option value="">Select shop</option>
                        @foreach ($shops as $shop)
                            <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="shop_id" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>Hotspot package</flux:label>
                    <flux:select wire:model="package_id" required>
                        <option value="">Select package</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}">{{ $package->name }} / {{ $package->speed_limit_profile }} / {{ $package->currency }} {{ number_format($package->price, 0) }}</option>
                        @endforeach
                    </flux:select>
                    <flux:description>Voucher time, bandwidth, and total transfer come from this package.</flux:description>
                    <flux:error name="package_id" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>Batch name</flux:label>
                    <flux:input wire:model.blur="name" icon="ticket" placeholder="Weekend 1 day vouchers" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Quantity</flux:label>
                    <flux:input type="number" min="1" max="500" wire:model.blur="quantity" required />
                    <flux:description>Generate up to 500 codes per batch.</flux:description>
                    <flux:error name="quantity" />
                </flux:field>

                <flux:field>
                    <flux:label>Code length</flux:label>
                    <flux:input type="number" min="6" max="16" wire:model.blur="code_length" required />
                    <flux:description>Longer codes are harder to guess.</flux:description>
                    <flux:error name="code_length" />
                </flux:field>

                <flux:field>
                    <flux:label>Prefix</flux:label>
                    <flux:input wire:model.blur="prefix" icon="hashtag" placeholder="MMS" />
                    <flux:description>Optional. Example: MMS-7K2P9Q.</flux:description>
                    <flux:error name="prefix" />
                </flux:field>

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model.blur="notes" rows="3" placeholder="Sold at front desk, printed 10 per page..." />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showGenerateModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="ticket" wire:loading.attr="disabled" wire:target="save">
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
                        <flux:heading level="2" size="lg">Voucher Batch</flux:heading>
                        <flux:text class="mt-2 text-sm text-zinc-500">
                            {{ $selectedBatch->name }} / {{ $selectedBatch->shop?->name ?? 'Deleted shop' }}
                        </flux:text>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:button href="{{ route('admin.voucher-batches.export', $selectedBatch) }}" target="_blank" variant="outline" icon="arrow-down-tray">
                            Export batch
                        </flux:button>
                        <flux:button href="{{ route('admin.voucher-batches.print', ['voucherBatch' => $selectedBatch, 'status' => 'unused', 'columns' => 5]) }}" target="_blank" variant="outline" icon="printer">
                            Print unused
                        </flux:button>
                        <flux:button type="button" variant="ghost" wire:click="closeInspect">Close</flux:button>
                    </div>
                </div>

                <section class="grid gap-3 md:grid-cols-4">
                    @foreach ([
                        ['label' => 'Generated', 'value' => $selectedBatch->vouchers_count],
                        ['label' => 'Unused', 'value' => $selectedBatch->unused_vouchers_count],
                        ['label' => 'Used', 'value' => $selectedBatch->used_vouchers_count],
                        ['label' => 'Use rate', 'value' => $selectedBatch->vouchers_count > 0 ? round(($selectedBatch->used_vouchers_count / $selectedBatch->vouchers_count) * 100, 1).'%' : '0%'],
                    ] as $stat)
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase text-zinc-500">{{ $stat['label'] }}</p>
                            <p class="mt-2 text-xl font-semibold">{{ is_numeric($stat['value']) ? number_format($stat['value']) : $stat['value'] }}</p>
                        </div>
                    @endforeach
                </section>

                <section class="grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 text-sm md:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Package</p>
                        <p class="mt-1 font-medium">{{ $selectedBatch->package?->name ?? 'Deleted package' }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ $selectedBatch->package?->speed_limit_profile ?: 'No bandwidth profile' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Validity</p>
                        <p class="mt-1 font-medium">{{ $selectedBatch->package?->limit_uptime_seconds ? round($selectedBatch->package->limit_uptime_seconds / 3600, 1).' hours' : 'No time limit' }}</p>
                        <p class="mt-1 text-xs text-zinc-500">Transfer {{ $selectedBatch->package?->data_limit_bytes ? number_format($selectedBatch->package->data_limit_bytes).' bytes' : 'Unlimited' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Prefix</p>
                        <p class="mt-1 font-mono text-xs">{{ $selectedBatch->prefix ? $selectedBatch->prefix.'-' : 'None' }}{{ $selectedBatch->code_length }} chars</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Created</p>
                        <p class="mt-1 font-medium">{{ $selectedBatch->created_at?->format('M j, Y g:i A') }}</p>
                    </div>
                    @if ($selectedBatch->notes)
                        <div class="md:col-span-2">
                            <p class="text-xs font-medium uppercase text-zinc-500">Notes</p>
                            <p class="mt-1 text-zinc-700">{{ $selectedBatch->notes }}</p>
                        </div>
                    @endif
                </section>

                <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white">
                    <table class="min-w-[920px] w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                            <tr>
                                <th class="px-4 py-3 font-medium">Code</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Used by device</th>
                                <th class="px-4 py-3 font-medium">Used at</th>
                                <th class="px-4 py-3 text-right font-medium">Access expires</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @forelse ($selectedBatch->vouchers as $voucher)
                                <tr wire:key="voucher-detail-{{ $voucher->id }}">
                                    <td class="px-4 py-3">
                                        <p class="font-mono text-xs font-semibold">{{ $voucher->code }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <flux:badge :color="$voucher->status === 'unused' ? 'green' : 'zinc'">
                                            {{ ucfirst($voucher->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-mono text-xs">{{ $voucher->used_mac_address ?: '-' }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ $voucher->used_at?->format('M j, Y g:i A') ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        {{ $voucher->subscription?->expires_at?->format('M j, Y g:i A') ?? '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No voucher codes found in this batch.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
