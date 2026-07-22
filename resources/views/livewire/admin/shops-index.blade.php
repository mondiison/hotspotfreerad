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

    <section class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_180px_220px_auto] [&>*]:min-w-0">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search shop, city, or tenant" />
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
            <flux:button type="button" variant="outline" icon="x-mark" class="w-full sm:col-span-2 lg:col-span-1 lg:w-auto" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status,payments">
                Reset
            </flux:button>
        </div>
    </section>

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-[760px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Tenant</th>
                    <th class="px-4 py-3 font-medium">City</th>
                    <th class="px-4 py-3 font-medium">Payments</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($shops as $shop)
                    <tr wire:key="shop-{{ $shop->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $shop->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $shop->routers_count ?? 0 }} routers / {{ $shop->packages_count ?? 0 }} packages</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 text-zinc-600">{{ $shop->location_city ?: 'Not set' }}</td>
                        <td class="px-4 py-3">
                            @if ($shop->hasCompleteFlutterwaveCredentials())
                                <flux:badge color="emerald">Configured</flux:badge>
                                <p class="mt-2 text-xs text-zinc-500">{{ $shop->hasFlutterwaveWebhookSecret() ? 'Webhook secret saved' : 'Webhook secret missing' }}</p>
                            @else
                                <flux:badge color="amber">Not configured</flux:badge>
                                <p class="mt-2 text-xs text-zinc-500">Customer payments disabled</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$shop->is_active ? 'green' : 'zinc'">{{ $shop->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $shop->id }})" wire:loading.attr="disabled" wire:target="edit({{ $shop->id }})">Edit</flux:button>
                                <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $shop->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $shop->id }})">Delete</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center">
                            <p class="font-medium">No shops match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Create a shop for each hotspot location or clear the filters.</p>
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

                    <section class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 md:col-span-2">
                        <div class="mb-4">
                            <h2 class="text-sm font-semibold text-zinc-950">Flutterwave payments</h2>
                            <p class="mt-1 text-sm leading-6 text-zinc-600">
                                Add this shop's Flutterwave v4 client ID and client secret so hotspot customer payments settle into the tenant's own Flutterwave account.
                            </p>
                        </div>

                        <div class="grid gap-5">
                            <flux:field>
                                <flux:label>Flutterwave client ID</flux:label>
                                <flux:input wire:model.blur="flutterwave_client_id" icon="identification" placeholder="{{ $editingShopId ? 'Leave blank to keep current value' : 'Example: FLW_CLIENT_...' }}" />
                                <flux:description>Required with client secret for tenant-owned collections.</flux:description>
                                <flux:error name="flutterwave_client_id" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Flutterwave client secret</flux:label>
                                <flux:input wire:model.blur="flutterwave_client_secret" icon="key" placeholder="{{ $editingShopId ? 'Leave blank to keep current value' : 'Paste the matching v4 client secret' }}" viewable />
                                <flux:description>The app uses this only on the server to request Flutterwave access tokens.</flux:description>
                                <flux:error name="flutterwave_client_secret" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Flutterwave webhook secret hash</flux:label>
                                <flux:input wire:model.blur="flutterwave_webhook_secret" icon="shield-check" placeholder="{{ $editingShopId ? 'Leave blank to keep current value' : 'Optional: tenant webhook verif-hash' }}" viewable />
                                <flux:description>Use the verif-hash from this tenant's Flutterwave webhook settings.</flux:description>
                                <flux:error name="flutterwave_webhook_secret" />
                            </flux:field>
                        </div>
                    </section>

                    <flux:checkbox wire:model.live="is_active" label="Active" />
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Shop</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Shop</flux:heading>
                <flux:text class="mt-2">This removes the shop and related setup records. Use carefully for locations that are no longer needed.</flux:text>
            </div>

            @if ($deletingShop)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingShop->name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $deletingShop->location_city ?: 'No city set' }}</p>
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
