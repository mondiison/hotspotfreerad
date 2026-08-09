<div>
    <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Only devices registered here may join the Staff and Management SSIDs, even with the correct Wi-Fi password &mdash; the router's generated script rejects everyone else.</p>
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" wire:click="create" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="create">
                Add Device
            </flux:button>
        </div>
    </div>

    @if ($savedMessage)
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <section class="grid min-w-0 gap-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))] [&>*]:min-w-0">
        <div class="sm:col-span-2 xl:col-span-3">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search device, MAC, owner, shop" />
        </div>
        <flux:select wire:model.live="networkFilter">
            <flux:select.option value="">Staff + Management</flux:select.option>
            <flux:select.option value="staff">Staff only</flux:select.option>
            <flux:select.option value="mgmt">Management only</flux:select.option>
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" class="w-full" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,networkFilter">
            Reset
        </flux:button>
    </section>

    <div wire:loading.flex wire:target="search,networkFilter,clearFilters,save,delete,sync" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating trusted devices...
    </div>

    <div class="mt-6 overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <table class="min-w-[820px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Device</th>
                    <th class="px-4 py-3 font-medium">Network</th>
                    <th class="px-4 py-3 font-medium">Owner</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($devices as $device)
                    @php($isActive = $device->isCurrentlyActive())
                    <tr wire:key="trusted-wifi-device-{{ $device->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $device->device_name }}</p>
                            <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $device->mac_address }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge color="{{ $device->network === 'mgmt' ? 'purple' : 'blue' }}">{{ $device->network === 'mgmt' ? 'Management' : 'Staff' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $device->owner_name ?: 'No owner name' }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $device->notes ?: 'No notes' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $device->shop?->name ?? 'Deleted shop' }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $device->shop?->tenant?->company_name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$isActive ? 'green' : ($device->is_active ? 'amber' : 'zinc')">
                                {{ $isActive ? 'Active' : ($device->is_active ? 'Expired' : 'Disabled') }}
                            </flux:badge>
                            @if (! $device->last_provisioned_at)
                                <flux:badge color="zinc" class="ml-1">Unsynced</flux:badge>
                            @endif
                            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $device->expires_at ? 'Expires '.$device->expires_at->format('M j, Y g:i A') : 'No expiry set' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" wire:click="edit({{ $device->id }})" wire:loading.attr="disabled" wire:target="edit({{ $device->id }})" variant="outline" size="sm" icon="pencil-square">Edit</flux:button>
                                <flux:dropdown>
                                    <flux:button type="button" variant="outline" size="sm" icon="ellipsis-horizontal" aria-label="More actions" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="sync({{ $device->id }})" icon="cloud-arrow-up">Sync</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item wire:click="confirmDelete({{ $device->id }})" icon="trash" variant="danger">Delete</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No trusted devices registered yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $devices->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-2xl" :dismissible="true" variant="flyout">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingDeviceId ? 'Edit Trusted Device' : 'Add Trusted Device' }}</flux:heading>
                <flux:text class="mt-2">Active devices are synced to FreeRADIUS by MAC address and included in the router's generated Staff/Management Wi-Fi access list.</flux:text>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <flux:field>
                    <flux:label>Shop</flux:label>
                    <flux:select wire:model="shop_id" required>
                        <option value="">Select shop</option>
                        @foreach ($shops as $shop)
                            <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="shop_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Network</flux:label>
                    <flux:select wire:model="network" required>
                        <flux:select.option value="staff">Staff</flux:select.option>
                        <flux:select.option value="mgmt">Management</flux:select.option>
                    </flux:select>
                    <flux:error name="network" />
                </flux:field>

                <flux:field>
                    <flux:label>Device name</flux:label>
                    <flux:input wire:model.blur="device_name" icon="device-phone-mobile" placeholder="Tolu's Laptop" required />
                    <flux:error name="device_name" />
                </flux:field>

                <flux:field>
                    <flux:label>MAC address</flux:label>
                    <flux:input wire:model.blur="mac_address" icon="finger-print" placeholder="AA:BB:CC:DD:EE:FF" required />
                    <flux:description>Find this in the device's Wi-Fi settings before it connects, or in the router's DHCP lease list after.</flux:description>
                    <flux:error name="mac_address" />
                </flux:field>

                <flux:field>
                    <flux:label>Owner name</flux:label>
                    <flux:input wire:model.blur="owner_name" icon="user" placeholder="Tolu Adeyemi" />
                    <flux:error name="owner_name" />
                </flux:field>

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:input wire:model.blur="notes" icon="pencil" placeholder="Front desk staff" />
                    <flux:error name="notes" />
                </flux:field>

                <flux:field>
                    <flux:label>Expires at</flux:label>
                    <flux:input type="datetime-local" wire:model="expires_at" />
                    <flux:description>Optional. Use this for contractors or temporary access.</flux:description>
                    <flux:error name="expires_at" />
                </flux:field>

                <flux:checkbox wire:model.live="is_active" label="Active" />
            </div>

            <section class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 text-sm text-zinc-600 dark:text-zinc-400">
                This does not replace the Staff/Management Wi-Fi password &mdash; it adds a second check. On the MikroTik built-in Wi-Fi profile, re-open the router's Script page after adding or removing a device so the generated access-list includes the change, then re-run that section on the router. For external APs, configure RADIUS MAC authentication on the AP against the same FreeRADIUS server; see the Wi-Fi access docs for details and current limitations.
            </section>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save Device</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Trusted Device</flux:heading>
                <flux:text class="mt-2">This removes the device from MMS Radius and revokes its RADIUS MAC access. Re-copy the router's script afterward to drop it from the access-list too.</flux:text>
            </div>

            @if ($deletingDevice)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4">
                    <p class="font-medium">{{ $deletingDevice->device_name }}</p>
                    <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $deletingDevice->mac_address }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Device</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
