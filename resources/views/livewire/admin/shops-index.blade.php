<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            @if ($savedMessage)
                <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $savedMessage }}
                </div>
            @endif
        </div>

        <flux:button type="button" variant="primary" icon="plus" wire:click="create" wire:loading.attr="disabled" wire:target="create,save">
            Add Shop
        </flux:button>
    </div>

    @php
        $shopsActiveFilters = [];
        if (filled($search)) {
            $shopsActiveFilters[] = ['label' => 'Search: "'.$search.'"', 'clear' => "\$set('search', '')"];
        }
        if (filled($status)) {
            $shopsActiveFilters[] = ['label' => 'Status: '.($status === 'active' ? 'Active' : 'Inactive'), 'clear' => "\$set('status', '')"];
        }
        if (filled($payments)) {
            $shopsActiveFilters[] = ['label' => 'Payments: '.($payments === 'configured' ? 'Configured' : 'Not configured'), 'clear' => "\$set('payments', '')"];
        }
    @endphp

    <x-admin.filter-bar modal-name="filters-shops" :active="$shopsActiveFilters">
        <div class="sm:col-span-2">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search shop, city, or tenant" />
        </div>
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="inactive">Inactive</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="payments">
            <flux:select.option value="">All payment states</flux:select.option>
            <flux:select.option value="configured">Payments configured</flux:select.option>
            <flux:select.option value="unconfigured">Payments not configured</flux:select.option>
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" class="w-full sm:col-span-2" wire:click="clearFilters" x-on:click="$flux.modal('filters-shops').close()" wire:loading.attr="disabled" wire:target="clearFilters,search,status,payments">
            Reset
        </flux:button>
    </x-admin.filter-bar>

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
        <table class="min-w-[760px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                <tr>
                    <x-admin.sortable-th column="name" :sort-by="$sortBy" :sort-direction="$sortDirection">Shop</x-admin.sortable-th>
                    <th class="px-4 py-3 font-medium">Tenant</th>
                    <x-admin.sortable-th column="location_city" :sort-by="$sortBy" :sort-direction="$sortDirection">City</x-admin.sortable-th>
                    <th class="px-4 py-3 font-medium">Payments</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($shops as $shop)
                    <tr wire:key="shop-{{ $shop->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $shop->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $shop->routers_count ?? 0 }} routers / {{ $shop->packages_count ?? 0 }} packages</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $shop->location_city ?: 'Not set' }}</td>
                        <td class="px-4 py-3">
                            @if ($shop->hasConfiguredPaymentGateway())
                                <flux:badge color="emerald">Configured</flux:badge>
                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $shop->paymentGatewayName() }}</p>
                            @else
                                <flux:badge color="amber">Not configured</flux:badge>
                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Customer payments disabled</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$shop->is_active ? 'green' : 'zinc'">{{ $shop->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $shop->id }})" wire:loading.attr="disabled" wire:target="edit({{ $shop->id }})">Edit</flux:button>
                                <flux:dropdown>
                                    <flux:button type="button" variant="outline" size="sm" icon="ellipsis-vertical" aria-label="More actions" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="confirmDelete({{ $shop->id }})" icon="trash" variant="danger">Delete</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center">
                            <p class="font-medium">No shops match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Create a shop for each hotspot location or clear the filters.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add Shop</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $shops->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingShopId ? 'Edit Shop' : 'Add Shop' }}</flux:heading>
                <flux:text class="mt-2">Shops own routers, packages, portal branding, and payment credentials.</flux:text>
            </div>

            @include('admin.partials.billing-usage', ['usage' => $billingUsage])

            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field class="md:col-span-2">
                        <flux:label>Tenant</flux:label>
                        <flux:select wire:model="tenant_id" required :disabled="! auth()->user()->isSuperAdmin()">
                            <option value="">Select tenant</option>
                            @foreach ($tenants as $tenant)
                                <option value="{{ $tenant->id }}">{{ $tenant->company_name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="tenant_id" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Shop name</flux:label>
                        <flux:input wire:model.blur="name" icon="building-storefront" required />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>City</flux:label>
                        <flux:input wire:model.blur="location_city" icon="map-pin" />
                        <flux:error name="location_city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Contact phone</flux:label>
                        <flux:input wire:model.blur="contact_phone" icon="phone" placeholder="+234 800 000 0000" />
                        <flux:description>Shown on the hotspot portal's "Call to get a voucher" link. Leave blank to use the tenant's own contact phone.</flux:description>
                        <flux:error name="contact_phone" />
                    </flux:field>

                    <flux:checkbox wire:model.live="is_active" label="Active" />

                    <flux:field class="md:col-span-2">
                        <flux:checkbox wire:model.live="allow_test_access" label="Allow free test access (per package, debugging only)" />
                        <flux:description>Shows a "Start test access" button inside every package's details on this shop's captive portal, granting that package's full duration and bandwidth with no payment. Intended for staff to verify a router/package is working -- not a customer-facing promotion. Off by default.</flux:description>
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:checkbox wire:model.live="auto_recover_mac_changes" label="Automatically recover access when a device's MAC address changes" />
                        <flux:description>iOS/Android can present a different (privacy-randomized) MAC address on reconnect, leaving an already-paid customer stuck on the login screen for access they already own. When on, a device recognized by its recognition cookie as one that still has an active subscription under a different MAC is moved to its current MAC automatically, with no support contact needed -- skipped entirely if the new MAC already has its own active subscription. Off by default.</flux:description>
                    </flux:field>

                    <div class="md:col-span-2 rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                        <flux:field>
                            <flux:checkbox wire:model.live="trial_enabled" label="Enable free trial (customer-facing promotion)" />
                            <flux:description>Shows a standalone "Free trial" option on the captive portal, separate from every package -- a limited, throttled taste of the service meant to draw in real customers. Capped per device per day.</flux:description>
                        </flux:field>

                        @if ($trial_enabled)
                            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                                <flux:field>
                                    <flux:label>Duration (minutes)</flux:label>
                                    <flux:input type="number" min="1" max="1440" wire:model.blur="trial_duration_minutes" />
                                    <flux:error name="trial_duration_minutes" />
                                </flux:field>
                                <flux:field>
                                    <flux:label>Uses per device / day</flux:label>
                                    <flux:input type="number" min="1" max="255" wire:model.blur="trial_max_uses_per_day" />
                                    <flux:error name="trial_max_uses_per_day" />
                                </flux:field>
                                <flux:field>
                                    <flux:label>Bandwidth (rate limit)</flux:label>
                                    <flux:input wire:model.blur="trial_speed_limit_profile" placeholder="1M/1M" />
                                    <flux:description>MikroTik rate-limit format: upload/download, e.g. 1M/1M.</flux:description>
                                    <flux:error name="trial_speed_limit_profile" />
                                </flux:field>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Shop</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>

            @if ($editingShop)
                <livewire:admin.payment-settings-card :shop="$editingShop" :key="'shop-modal-payment-settings-'.$editingShop->id" />
            @else
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Save this shop first, then reopen it here to choose the payment gateway, enter its credentials, and set Live/Test environment where the gateway supports it.
                </div>
            @endif
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Shop</flux:heading>
                <flux:text class="mt-2">This removes the shop and related setup records. Use carefully for locations that are no longer needed.</flux:text>
            </div>

            @if ($deletingShop)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4">
                    <p class="font-medium">{{ $deletingShop->name }}</p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $deletingShop->location_city ?: 'No city set' }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Shop</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
