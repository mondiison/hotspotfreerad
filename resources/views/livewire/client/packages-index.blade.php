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
            New package
        </flux:button>
    </div>

    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-[760px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
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
                        <td class="px-4 py-3 text-zinc-600">{{ $package->shop->name }}</td>
                        <td class="px-4 py-3">{{ $package->currency }} {{ number_format($package->price, 2) }}</td>
                        <td class="px-4 py-3">
                            @php
                                $days = intdiv($package->limit_uptime_seconds, 86400);
                                $hours = intdiv($package->limit_uptime_seconds % 86400, 3600);
                            @endphp
                            {{ $days ? "{$days}d" : "{$hours}h" }}
                        </td>
                        <td class="px-4 py-3">{{ $package->data_limit_bytes ? number_format($package->data_limit_bytes / 1073741824, 1).' GB' : 'Unlimited' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $package->speed_limit_profile }}</td>
                        <td class="px-4 py-3 text-right">
                            <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $package->id }})">Edit</flux:button>
                            <flux:button type="button" variant="ghost" size="sm" icon="trash" wire:click="confirmDelete({{ $package->id }})">Delete</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center">
                            <p class="font-medium">No packages yet.</p>
                            <p class="mt-1 text-sm text-zinc-500">Create one for customers to buy on your hotspot's portal.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">New package</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $packages->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingPackageId ? 'Edit package' : 'New package' }}</flux:heading>
                <flux:text class="mt-2">Shown to customers on your hotspot's payment page.</flux:text>
            </div>

            @if ($billingUsage)
                <div class="rounded-md border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600">{{ $billingUsage['message'] }}</div>
            @endif

            <form wire:submit.prevent="save" class="space-y-6">
                @if ($shops->count() > 1)
                    <flux:field>
                        <flux:label>Shop</flux:label>
                        <flux:select wire:model="shop_id" required>
                            <option value="">Select shop</option>
                            @foreach ($shops as $shop)
                                <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="shop_id" />
                    </flux:field>
                @endif

                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Package name</flux:label>
                        <flux:input wire:model.blur="name" placeholder="Daily 5GB" required />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Service type</flux:label>
                        <flux:select wire:model.live="service_type" required>
                            <option value="hotspot">Hotspot captive portal</option>
                            <option value="pppoe">PPPoE subscriber</option>
                            <option value="both">Hotspot and PPPoE</option>
                        </flux:select>
                        <flux:error name="service_type" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Price</flux:label>
                        <flux:input type="number" step="0.01" min="1" wire:model.blur="price" placeholder="500" required />
                        <flux:error name="price" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Currency</flux:label>
                        <flux:input wire:model.blur="currency" maxlength="3" class="uppercase" required />
                        <flux:error name="currency" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Duration</flux:label>
                        <flux:input type="number" min="60" wire:model.blur="limit_uptime_seconds" required />
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ([3600 => '1h', 86400 => '1d', 259200 => '3d', 604800 => '7d', 2592000 => '30d'] as $seconds => $label)
                                <flux:button type="button" size="xs" wire:click="setPreset('limit_uptime_seconds', '{{ $seconds }}')">{{ $label }}</flux:button>
                            @endforeach
                        </div>
                        <flux:error name="limit_uptime_seconds" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Speed (upload/download)</flux:label>
                        <flux:input wire:model.blur="speed_limit_profile" placeholder="5M/5M" required />
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['2M/2M', '5M/5M', '5M/10M', '10M/20M'] as $speed)
                                <flux:button type="button" size="xs" wire:click="setPreset('speed_limit_profile', '{{ $speed }}')">{{ $speed }}</flux:button>
                            @endforeach
                        </div>
                        <flux:error name="speed_limit_profile" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>Data cap</flux:label>
                        <flux:input type="number" min="1" wire:model.blur="data_limit_bytes" placeholder="Leave blank for unlimited" />
                        <div class="mt-2 flex flex-wrap gap-2">
                            <flux:button type="button" size="xs" wire:click="setPreset('data_limit_bytes', '')">Unlimited</flux:button>
                            @foreach ([1073741824 => '1GB', 5368709120 => '5GB', 10737418240 => '10GB', 21474836480 => '20GB'] as $bytes => $label)
                                <flux:button type="button" size="xs" wire:click="setPreset('data_limit_bytes', '{{ $bytes }}')">{{ $label }}</flux:button>
                            @endforeach
                        </div>
                        <flux:error name="data_limit_bytes" />
                    </flux:field>
                </div>

                <flux:checkbox wire:model.live="is_active" label="Active and visible on the captive portal" />

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save package</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete package</flux:heading>
                <flux:text class="mt-2">This removes it from your hotspot's payment page.</flux:text>
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
                    <span wire:loading.remove wire:target="delete">Delete package</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
