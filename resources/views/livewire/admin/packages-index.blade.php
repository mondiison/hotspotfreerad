<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="space-y-2">
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif

            @error('billing')
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            Add Package
        </flux:button>
    </div>

    <section class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid min-w-0 gap-3 md:grid-cols-[minmax(0,1fr)_180px_auto] [&>*]:min-w-0">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search package, shop, group, or speed" />
            <flux:select wire:model.live="status">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
            <flux:button type="button" variant="outline" icon="x-mark" class="w-full md:w-auto" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status">
                Reset
            </flux:button>
        </div>
    </section>

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-[860px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Group</th>
                    <th class="px-4 py-3 font-medium">Price</th>
                    <th class="px-4 py-3 font-medium">Time</th>
                    <th class="px-4 py-3 font-medium">Data</th>
                    <th class="px-4 py-3 font-medium">Speed</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($packages as $package)
                    <tr wire:key="package-{{ $package->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $package->name }}</p>
                            <p class="mt-1 text-xs">
                                <flux:badge :color="$package->is_active ? 'green' : 'zinc'">{{ $package->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                            </p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $package->shop->name }} / {{ $package->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $package->radius_group_name ?: 'Pending sync' }}</td>
                        <td class="px-4 py-3">{{ $package->currency }} {{ number_format($package->price, 2) }}</td>
                        <td class="px-4 py-3">
                            @php
                                $days = intdiv($package->limit_uptime_seconds, 86400);
                                $hours = intdiv($package->limit_uptime_seconds % 86400, 3600);
                            @endphp
                            {{ $days ? "{$days}d" : "{$hours}h" }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $package->data_limit_bytes ? number_format($package->data_limit_bytes / 1073741824, 1).' GB' : 'Unlimited' }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $package->speed_limit_profile }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $package->id }})" wire:loading.attr="disabled" wire:target="edit({{ $package->id }})">Edit</flux:button>
                                <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $package->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $package->id }})">Delete</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center">
                            <p class="font-medium">No packages match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Create internet plans with uptime, speed, and optional data caps.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add Package</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $packages->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-5xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingPackageId ? 'Edit Package' : 'Add Package' }}</flux:heading>
                <flux:text class="mt-2">Build sellable hotspot plans. Each save syncs a reusable RADIUS profile.</flux:text>
            </div>

            @include('admin.partials.billing-usage', ['usage' => $billingUsage])

            <form wire:submit.prevent="save" class="relative space-y-6">
                <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
                    <div class="rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow-sm">
                        Saving and syncing RADIUS profile...
                    </div>
                </div>

                <section>
                    <h2 class="text-sm font-semibold uppercase text-zinc-500">Plan basics</h2>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <flux:field class="md:col-span-2">
                            <flux:label>Shop</flux:label>
                            <flux:select wire:model="shop_id" required>
                                <option value="">Select shop</option>
                                @foreach ($shops as $shop)
                                    <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:description>This package appears only on routers attached to this shop.</flux:description>
                            <flux:error name="shop_id" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Package name</flux:label>
                            <flux:input wire:model.blur="name" icon="tag" placeholder="Daily 5GB" required />
                            <flux:description>Customer-facing name. Examples: Daily 5GB, Weekly Unlimited, 30-Day Basic.</flux:description>
                            <flux:error name="name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>RADIUS group name</flux:label>
                            <flux:input wire:model.blur="radius_group_name" icon="server-stack" placeholder="Auto-generated if blank" />
                            <flux:description>Leave blank unless you need a specific FreeRADIUS group name.</flux:description>
                            <flux:error name="radius_group_name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Price</flux:label>
                            <flux:input type="number" step="0.01" min="1" wire:model.blur="price" icon="banknotes" placeholder="500" required />
                            <flux:description>Amount customers pay for this plan. Flutterwave requires at least 1.00.</flux:description>
                            <flux:error name="price" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Currency</flux:label>
                            <flux:input wire:model.blur="currency" maxlength="3" class="uppercase" icon="currency-dollar" required />
                            <flux:description>Three-letter code. Example: NGN.</flux:description>
                            <flux:error name="currency" />
                        </flux:field>
                    </div>
                </section>

                <section class="border-t border-zinc-200 pt-6">
                    <h2 class="text-sm font-semibold uppercase text-zinc-500">Access rules</h2>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <flux:field>
                            <flux:label>Uptime</flux:label>
                            <flux:select wire:model.live="limit_uptime_seconds">
                                <option value="">Choose a common duration</option>
                                @foreach ([3600 => '1 hour', 10800 => '3 hours', 21600 => '6 hours', 86400 => '1 day', 259200 => '3 days', 604800 => '7 days', 2592000 => '30 days'] as $seconds => $label)
                                    <option value="{{ $seconds }}">{{ $label }}</option>
                                @endforeach
                            </flux:select>
                            <flux:input type="number" min="60" wire:model.blur="limit_uptime_seconds" placeholder="3600" class="mt-2" required />
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ([86400 => '1 day', 259200 => '3 days', 604800 => '7 days', 2592000 => '30 days'] as $seconds => $label)
                                    <flux:button type="button" size="xs" wire:click="setPreset('limit_uptime_seconds', '{{ $seconds }}')">{{ $label }}</flux:button>
                                @endforeach
                            </div>
                            <flux:description>Session duration in seconds. 1 day = 86400, 7 days = 604800, 30 days = 2592000.</flux:description>
                            <flux:error name="limit_uptime_seconds" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Bandwidth</flux:label>
                            <flux:input wire:model.blur="speed_limit_profile" icon="signal" placeholder="5M/5M" required />
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach (['2M/2M', '5M/5M', '10M/10M', '20M/20M'] as $speed)
                                    <flux:button type="button" size="xs" wire:click="setPreset('speed_limit_profile', '{{ $speed }}')">{{ $speed }}</flux:button>
                                @endforeach
                            </div>
                            <flux:description>Upload/download format. Examples: <code>2M/5M</code>, <code>5M/5M</code>, <code>10M/20M</code>.</flux:description>
                            <flux:error name="speed_limit_profile" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Hard data cap</flux:label>
                            <flux:select wire:model.live="data_limit_bytes">
                                <option value="">Unlimited data</option>
                                @foreach ([1073741824 => '1 GB', 2147483648 => '2 GB', 5368709120 => '5 GB', 10737418240 => '10 GB', 21474836480 => '20 GB', 53687091200 => '50 GB', 107374182400 => '100 GB'] as $bytes => $label)
                                    <option value="{{ $bytes }}">{{ $label }}</option>
                                @endforeach
                            </flux:select>
                            <flux:input type="number" min="1" wire:model.blur="data_limit_bytes" placeholder="Leave blank for unlimited" class="mt-2" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <flux:button type="button" size="xs" wire:click="setPreset('data_limit_bytes', '')">Unlimited</flux:button>
                                @foreach ([5368709120 => '5GB', 10737418240 => '10GB', 21474836480 => '20GB', 53687091200 => '50GB'] as $bytes => $label)
                                    <flux:button type="button" size="xs" wire:click="setPreset('data_limit_bytes', '{{ $bytes }}')">{{ $label }}</flux:button>
                                @endforeach
                            </div>
                            <flux:description>Leave blank for unlimited. If set, access stops when upload + download reaches this byte value.</flux:description>
                            <flux:error name="data_limit_bytes" />
                        </flux:field>
                    </div>
                </section>

                <section class="border-t border-zinc-200 pt-6">
                    <h2 class="text-sm font-semibold uppercase text-zinc-500">Fair usage policy</h2>
                    <p class="mt-1 text-sm text-zinc-500">Optional soft cap. Instead of cutting users off, throttle them after heavy usage.</p>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <flux:field>
                            <flux:label>FUP threshold</flux:label>
                            <flux:input type="number" min="1" wire:model.blur="fup_data_threshold_bytes" placeholder="Leave blank for no FUP" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <flux:button type="button" size="xs" wire:click="setPreset('fup_data_threshold_bytes', '')">No FUP</flux:button>
                                @foreach ([5368709120 => '5GB', 10737418240 => '10GB', 21474836480 => '20GB'] as $bytes => $label)
                                    <flux:button type="button" size="xs" wire:click="setPreset('fup_data_threshold_bytes', '{{ $bytes }}')">{{ $label }}</flux:button>
                                @endforeach
                            </div>
                            <flux:description>Byte value where throttling starts. Example: 20GB = 21474836480.</flux:description>
                            <flux:error name="fup_data_threshold_bytes" />
                        </flux:field>

                        <flux:field>
                            <flux:label>FUP speed</flux:label>
                            <flux:input wire:model.blur="fup_speed_limit_profile" icon="arrow-trending-down" placeholder="1M/1M" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach (['512K/512K', '1M/1M', '2M/2M'] as $speed)
                                    <flux:button type="button" size="xs" wire:click="setPreset('fup_speed_limit_profile', '{{ $speed }}')">{{ $speed }}</flux:button>
                                @endforeach
                            </div>
                            <flux:description>Speed after FUP threshold. Example: reduce a 10M/10M plan to 1M/1M.</flux:description>
                            <flux:error name="fup_speed_limit_profile" />
                        </flux:field>
                    </div>
                </section>

                <div class="grid gap-4 border-t border-zinc-200 pt-6 md:grid-cols-[1fr_260px]">
                    <flux:checkbox wire:model.live="is_active" label="Active and visible on the captive portal" />

                    <section class="rounded-lg border border-zinc-200 bg-zinc-950 p-4 text-white">
                        <h2 class="text-sm font-semibold">Plan Shape</h2>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-zinc-400">Data mode</dt>
                                <dd>{{ filled($data_limit_bytes) ? 'Hard cap' : 'Unlimited' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-zinc-400">Fair use</dt>
                                <dd>{{ filled($fup_data_threshold_bytes) ? 'Throttle enabled' : 'Off' }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                    <p><strong class="text-zinc-950">Hard cap</strong> stops access when the data limit is reached.</p>
                    <p class="mt-2"><strong class="text-zinc-950">FUP</strong> keeps access alive but lowers speed after the threshold.</p>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Package</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Package</flux:heading>
                <flux:text class="mt-2">This removes the sellable plan from the admin list and captive portal.</flux:text>
            </div>

            @if ($deletingPackage)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingPackage->name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $deletingPackage->currency }} {{ number_format($deletingPackage->price, 2) }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Package</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
