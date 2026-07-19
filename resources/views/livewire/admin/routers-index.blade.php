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
            Add Router
        </flux:button>
    </div>

    <section class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 md:grid-cols-[1fr_180px_auto]">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search router, NAS ID, WireGuard IP, or shop" />
            <flux:select wire:model.live="status">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="online">Active/recent</flux:select.option>
                <flux:select.option value="offline">No recent accounting</flux:select.option>
            </flux:select>
            <flux:button type="button" variant="outline" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status">
                Reset
            </flux:button>
        </div>
    </section>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Router</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">NAS ID</th>
                    <th class="px-4 py-3 font-medium">WireGuard IP</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($routers as $router)
                    <tr wire:key="router-{{ $router->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $router->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Last seen {{ $router->last_seen_at?->diffForHumans() ?? 'never' }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $router->shop->name }} / {{ $router->shop->tenant->company_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $router->nas_identifier }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600">{{ $router->wireguard_internal_ip }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$router->is_online ? 'green' : 'zinc'">{{ $router->is_online ? 'Active/recent' : 'No recent accounting' }}</flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button href="{{ route('admin.routers.show', $router) }}" variant="outline" size="sm" icon="command-line">Script</flux:button>
                                <flux:button type="button" variant="outline" size="sm" icon="pencil-square" wire:click="edit({{ $router->id }})" wire:loading.attr="disabled" wire:target="edit({{ $router->id }})">Edit</flux:button>
                                <flux:button type="button" variant="danger" size="sm" icon="trash" wire:click="confirmDelete({{ $router->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $router->id }})">Delete</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center">
                            <p class="font-medium">No routers match this view.</p>
                            <p class="mt-1 text-sm text-zinc-500">Register the MikroTik router that will redirect customers to the captive portal.</p>
                            <flux:button type="button" variant="primary" icon="plus" class="mt-4" wire:click="create">Add Router</flux:button>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $routers->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingRouterId ? 'Edit Router' : 'Add Router' }}</flux:heading>
                <flux:text class="mt-2">Router saves are mirrored into the FreeRADIUS nas table.</flux:text>
            </div>

            @include('admin.partials.billing-usage', ['usage' => $billingUsage])

            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field class="md:col-span-2">
                        <flux:label>Shop</flux:label>
                        <flux:select wire:model="shop_id" required>
                            <option value="">Select shop</option>
                            @foreach ($shops as $shop)
                                <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:description>Create a tenant and shop first, then attach each MikroTik router to its shop.</flux:description>
                        <flux:error name="shop_id" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Router name</flux:label>
                        <flux:input wire:model.blur="name" icon="signal" placeholder="Main Shop Router" required />
                        <flux:description>Dashboard label only. Example: Main Shop Router.</flux:description>
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>NAS identifier</flux:label>
                        <flux:input wire:model.blur="nas_identifier" icon="finger-print" placeholder="lagos-shop-01" required />
                        <flux:description>Unique RouterOS identity. The generated script sets this with <code>/system identity</code>.</flux:description>
                        <flux:error name="nas_identifier" />
                    </flux:field>

                    <flux:field>
                        <flux:label>WireGuard internal IP</flux:label>
                        <flux:input wire:model.blur="wireguard_internal_ip" icon="globe-alt" placeholder="10.8.0.10" required />
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['10.8.0.10', '10.8.0.11', '10.8.0.12', '10.8.0.13'] as $ip)
                                <flux:button type="button" size="xs" wire:click="setPreset('wireguard_internal_ip', '{{ $ip }}')">{{ $ip }}</flux:button>
                            @endforeach
                        </div>
                        <flux:description>Private VPN IP for this router. Keep <code>10.8.0.1</code> for the server.</flux:description>
                        <flux:error name="wireguard_internal_ip" />
                    </flux:field>

                    <flux:field>
                        <flux:label>RADIUS shared secret</flux:label>
                        <flux:input wire:model.blur="shared_secret" icon="key" placeholder="{{ $editingRouterId ? 'Leave blank to keep current value' : 'QF9mX7vC2pL8nR4sT6wY1zA5' }}" viewable />
                        <flux:description>Random password shared by MikroTik and FreeRADIUS. Use a different secret per router.</flux:description>
                        <flux:error name="shared_secret" />
                    </flux:field>

                    <flux:checkbox wire:model.live="is_online" label="Online" />
                </div>

                <section class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                    <p><strong class="text-zinc-900">NAS identifier:</strong> the MikroTik system identity. Example: <code>lagos-shop-01</code>.</p>
                    <p class="mt-2"><strong class="text-zinc-900">Shared secret:</strong> generate a strong value with <code>openssl rand -base64 24</code>.</p>
                    <p class="mt-2">After saving, open the Script page and paste the generated commands into MikroTik RouterOS terminal.</p>
                </section>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save Router</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete Router</flux:heading>
                <flux:text class="mt-2">This removes the router record from the admin. FreeRADIUS accounting history is left untouched.</flux:text>
            </div>

            @if ($deletingRouter)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                    <p class="font-medium">{{ $deletingRouter->name }}</p>
                    <p class="mt-1 font-mono text-xs text-zinc-500">{{ $deletingRouter->nas_identifier }} / {{ $deletingRouter->wireguard_internal_ip }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Router</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
