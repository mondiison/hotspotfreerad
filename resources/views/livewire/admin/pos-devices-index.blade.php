<div>
    <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <p class="text-sm text-zinc-500">POS devices use the POS SSID/VLAN and are renewed from here without sharing customer hotspot passwords.</p>
        <div class="flex flex-wrap gap-2">
            <flux:button href="{{ route('admin.packages.index', ['service' => 'hotspot_capable']) }}" wire:navigate variant="outline" icon="radio">
                POS Plans
            </flux:button>
            <flux:button type="button" wire:click="create" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="create">
                Add POS Device
            </flux:button>
        </div>
    </div>

    @if ($savedMessage)
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <section class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @foreach ([
            ['key' => '', 'label' => 'Devices', 'value' => $summary['total'], 'hint' => 'All matching POS terminals'],
            ['key' => 'active', 'label' => 'Active', 'value' => $summary['active'], 'hint' => 'Can authenticate now'],
            ['key' => 'expiring_soon', 'label' => 'Due soon', 'value' => $summary['expiring_soon'], 'hint' => 'Expires within 7 days'],
            ['key' => 'expired', 'label' => 'Expired', 'value' => $summary['expired'], 'hint' => 'Needs renewal'],
            ['key' => 'disabled', 'label' => 'Disabled', 'value' => $summary['disabled'], 'hint' => 'Blocked from RADIUS'],
            ['key' => 'unsynced', 'label' => 'Unsynced', 'value' => $summary['unsynced'], 'hint' => 'Not pushed to RADIUS'],
        ] as $stat)
            <button
                type="button"
                wire:click="$set('status', '{{ $stat['key'] }}')"
                wire:loading.attr="disabled"
                wire:target="status"
                class="rounded-lg border px-4 py-3 text-left shadow-sm transition hover:border-zinc-400 {{ $status === $stat['key'] ? 'border-zinc-950 bg-zinc-950 text-white' : 'border-zinc-200 bg-white text-zinc-950' }}"
            >
                <span class="block text-xs font-medium uppercase {{ $status === $stat['key'] ? 'text-zinc-300' : 'text-zinc-500' }}">{{ $stat['label'] }}</span>
                <span class="mt-2 block text-2xl font-semibold">{{ number_format($stat['value']) }}</span>
                <span class="mt-1 block text-xs {{ $status === $stat['key'] ? 'text-zinc-300' : 'text-zinc-500' }}">{{ $stat['hint'] }}</span>
            </button>
        @endforeach
    </section>

    <section class="grid min-w-0 gap-3 rounded-lg border border-zinc-200 bg-white p-4 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))] [&>*]:min-w-0">
        <div class="sm:col-span-2 xl:col-span-3">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search device, MAC, owner, phone, shop" />
        </div>
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="expiring_soon">Expiring within 7 days</flux:select.option>
            <flux:select.option value="expired">Expired</flux:select.option>
            <flux:select.option value="disabled">Disabled</flux:select.option>
            <flux:select.option value="unsynced">Unsynced to RADIUS</flux:select.option>
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" class="w-full" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status">
            Reset
        </flux:button>
    </section>

    <div wire:loading.flex wire:target="search,status,clearFilters,save,delete,renew,sync" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating POS devices...
    </div>

    <div class="mt-6 overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="min-w-[980px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Device</th>
                    <th class="px-4 py-3 font-medium">Owner</th>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($devices as $device)
                    @php($isActive = $device->isCurrentlyActive())
                    <tr wire:key="pos-device-{{ $device->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $device->device_name }}</p>
                            <p class="mt-1 font-mono text-xs text-zinc-500">{{ $device->mac_address }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $device->owner_name ?: 'No owner name' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $device->phone ?: 'No phone' }}{{ $device->email ? ' - '.$device->email : '' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $device->package?->name ?? 'Deleted package' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $device->package?->speed_limit_profile ?: 'No speed profile' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $device->shop?->name ?? 'Deleted shop' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $device->shop?->tenant?->company_name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$isActive ? 'green' : ($device->is_active ? 'amber' : 'zinc')">
                                {{ $isActive ? 'Active' : ($device->is_active ? 'Expired' : 'Disabled') }}
                            </flux:badge>
                            @if (! $device->last_provisioned_at)
                                <flux:badge color="zinc" class="ml-1">Unsynced</flux:badge>
                            @endif
                            <p class="mt-2 text-xs text-zinc-500">{{ $device->expires_at ? 'Expires '.$device->expires_at->format('M j, Y g:i A') : 'No expiry set' }}</p>
                            <p class="mt-1 text-xs text-zinc-400">{{ $device->last_provisioned_at ? 'Synced '.$device->last_provisioned_at->diffForHumans() : 'Not synced yet' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" wire:click="edit({{ $device->id }})" wire:loading.attr="disabled" wire:target="edit({{ $device->id }})" variant="outline" size="sm" icon="pencil-square">Edit</flux:button>
                                <flux:dropdown>
                                    <flux:button type="button" variant="outline" size="sm" icon="ellipsis-horizontal" aria-label="More actions" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="renew({{ $device->id }})" icon="arrow-path">Renew</flux:menu.item>
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
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500">No POS devices found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $devices->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingDeviceId ? 'Edit POS Device' : 'Add POS Device' }}</flux:heading>
                <flux:text class="mt-2">Active POS devices are synced to FreeRADIUS by MAC address for the POS SSID/VLAN.</flux:text>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <flux:field>
                    <flux:label>Shop</flux:label>
                    <flux:select wire:model.live="shop_id" required>
                        <option value="">Select shop</option>
                        @foreach ($shops as $shop)
                            <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="shop_id" />
                </flux:field>

                <flux:field>
                    <flux:label>POS package</flux:label>
                    <flux:select wire:model="package_id" required>
                        <option value="">Select hotspot-capable package</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}">{{ $package->name }} / {{ $package->speed_limit_profile }}</option>
                        @endforeach
                    </flux:select>
                    <flux:description>Use a low-bandwidth monthly package for POS terminals.</flux:description>
                    <flux:error name="package_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Device name</flux:label>
                    <flux:input wire:model.blur="device_name" icon="device-phone-mobile" placeholder="Shop 12 POS Terminal" required />
                    <flux:error name="device_name" />
                </flux:field>

                <flux:field>
                    <flux:label>MAC address</flux:label>
                    <flux:input wire:model.blur="mac_address" icon="finger-print" placeholder="AA:BB:CC:DD:EE:FF" required />
                    <flux:description>Find this in the POS network settings or MikroTik DHCP lease list.</flux:description>
                    <flux:error name="mac_address" />
                </flux:field>

                <flux:field>
                    <flux:label>Owner name</flux:label>
                    <flux:input wire:model.blur="owner_name" icon="user" placeholder="MMS Shop" />
                    <flux:error name="owner_name" />
                </flux:field>

                <flux:field>
                    <flux:label>Phone</flux:label>
                    <flux:input wire:model.blur="phone" icon="phone" placeholder="07063218823" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>Email</flux:label>
                    <flux:input type="email" wire:model.blur="email" icon="envelope" placeholder="owner@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>Starts at</flux:label>
                    <flux:input type="datetime-local" wire:model="starts_at" />
                    <flux:error name="starts_at" />
                </flux:field>

                <flux:field>
                    <flux:label>Expires at</flux:label>
                    <flux:input type="datetime-local" wire:model="expires_at" />
                    <flux:description>Leave blank to calculate from the package uptime.</flux:description>
                    <flux:error name="expires_at" />
                </flux:field>

                <flux:checkbox wire:model.live="is_active" label="Active in RADIUS" />
            </div>

            <section class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                POS terminals should connect to the password-protected POS SSID on VLAN 50. The Wi-Fi password protects the radio, while this record controls renewal, expiry, and bandwidth through FreeRADIUS.
            </section>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save POS Device</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete POS Device</flux:heading>
                <flux:text class="mt-2">This removes the device from MMS Radius and revokes its RADIUS MAC access.</flux:text>
            </div>

            @if ($deletingDevice)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingDevice->device_name }}</p>
                    <p class="mt-1 font-mono text-xs text-zinc-500">{{ $deletingDevice->mac_address }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete POS Device</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
