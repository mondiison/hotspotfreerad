<div>
    @php
        $formatBytes = function (?int $bytes): string {
            $bytes = (int) $bytes;

            if ($bytes <= 0) {
                return '0 B';
            }

            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
            $value = $bytes / (1024 ** $power);

            return number_format($value, $power === 0 ? 0 : 1).' '.$units[$power];
        };
    @endphp

    <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
            <p class="text-sm text-zinc-500">Create fixed subscriber credentials for PPPoE CPE, ONT, or customer routers.</p>
        </div>
        <flux:button type="button" wire:click="create" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="create">
            Add PPPoE Customer
        </flux:button>
    </div>

    @if ($savedMessage)
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <section class="grid min-w-0 gap-3 rounded-lg border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_180px_auto] [&>*]:min-w-0">
        <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Search username, name, phone, email, shop" />
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="expired">Expired</flux:select.option>
            <flux:select.option value="disabled">Disabled</flux:select.option>
        </flux:select>
        <flux:button type="button" variant="outline" icon="x-mark" class="w-full sm:col-span-2 lg:col-span-1 lg:w-auto" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters,search,status">
            Reset
        </flux:button>
    </section>

    <div wire:loading.flex wire:target="search,status,clearFilters,save,delete,renew" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Updating PPPoE customers...
    </div>

    <div wire:loading.flex wire:target="inspect" class="mt-4 hidden rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Loading PPPoE activity...
    </div>

    <div class="mt-6 overflow-x-auto overflow-y-hidden rounded-lg border border-zinc-200 bg-white">
        <table class="min-w-[1120px] w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Customer</th>
                    <th class="px-4 py-3 font-medium">Credentials</th>
                    <th class="px-4 py-3 font-medium">Package</th>
                    <th class="px-4 py-3 font-medium">Shop</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Usage</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($subscribers as $subscriber)
                    @php
                        $isActive = $subscriber->isCurrentlyActive();
                        $usage = $subscriber->radius_usage;
                    @endphp
                    <tr wire:key="pppoe-subscriber-{{ $subscriber->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $subscriber->full_name ?: 'Unnamed customer' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $subscriber->phone ?: 'No phone' }}{{ $subscriber->email ? ' - '.$subscriber->email : '' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-medium">{{ $subscriber->username }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Password hidden. Edit to reset.</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $subscriber->package?->name ?? 'Deleted package' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $subscriber->package?->speed_limit_profile ?: 'No speed profile' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $subscriber->shop?->name ?? 'Deleted shop' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $subscriber->shop?->tenant?->company_name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$isActive ? 'green' : ($subscriber->is_active ? 'amber' : 'zinc')">
                                {{ $isActive ? 'Active' : ($subscriber->is_active ? 'Expired' : 'Disabled') }}
                            </flux:badge>
                            <p class="mt-2 text-xs text-zinc-500">
                                {{ $subscriber->expires_at ? 'Expires '.$subscriber->expires_at->format('M j, Y g:i A') : 'No expiry set' }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-400">
                                {{ $subscriber->last_provisioned_at ? 'Synced '.$subscriber->last_provisioned_at->diffForHumans() : 'Not synced yet' }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($usage['available'])
                                <p class="font-medium">{{ $formatBytes($usage['total_bytes']) }}</p>
                                <p class="mt-1 text-xs text-zinc-500">Down {{ $formatBytes($usage['download_bytes']) }}</p>
                                <p class="mt-1 text-xs text-zinc-500">Up {{ $formatBytes($usage['upload_bytes']) }}</p>
                                <p class="mt-1 text-xs text-zinc-400">
                                    {{ number_format($usage['open_session_count']) }} online /
                                    {{ number_format($usage['session_count']) }} total
                                </p>
                            @else
                                <p class="text-xs text-zinc-500">Accounting unavailable</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" wire:click="renew({{ $subscriber->id }})" wire:loading.attr="disabled" wire:target="renew({{ $subscriber->id }})" variant="outline" size="sm" icon="arrow-path">
                                    <span wire:loading.remove wire:target="renew({{ $subscriber->id }})">Renew</span>
                                    <span wire:loading wire:target="renew({{ $subscriber->id }})">Renewing...</span>
                                </flux:button>
                                <flux:button type="button" wire:click="inspect({{ $subscriber->id }})" wire:loading.attr="disabled" wire:target="inspect({{ $subscriber->id }})" variant="outline" size="sm" icon="magnifying-glass">
                                    Inspect
                                </flux:button>
                                <flux:button type="button" wire:click="edit({{ $subscriber->id }})" wire:loading.attr="disabled" wire:target="edit({{ $subscriber->id }})" variant="outline" size="sm" icon="pencil-square">Edit</flux:button>
                                <flux:button type="button" wire:click="confirmDelete({{ $subscriber->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $subscriber->id }})" variant="danger" size="sm" icon="trash">Delete</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-zinc-500">No PPPoE customers found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $subscribers->links() }}</div>

    <flux:modal wire:model.self="showFormModal" class="md:w-3xl" :dismissible="true" variant="flyout">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingSubscriberId ? 'Edit PPPoE Customer' : 'Add PPPoE Customer' }}</flux:heading>
                <flux:text class="mt-2">Credentials are synced to FreeRADIUS when the customer is active and not expired.</flux:text>
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
                    <flux:label>PPPoE package</flux:label>
                    <flux:select wire:model="package_id" required>
                        <option value="">Select PPPoE package</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}">{{ $package->name }} / {{ $package->speed_limit_profile }}</option>
                        @endforeach
                    </flux:select>
                    <flux:description>Only active packages marked PPPoE or Both appear here.</flux:description>
                    <flux:error name="package_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Username</flux:label>
                    <flux:input wire:model.blur="username" icon="user" placeholder="customer001" required />
                    <flux:error name="username" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ $editingSubscriberId ? 'New password' : 'Password' }}</flux:label>
                    <flux:input wire:model.blur="password" icon="key" placeholder="{{ $editingSubscriberId ? 'Leave blank to keep current password' : 'Generated password' }}" viewable />
                    <flux:description>Enter this on the customer CPE/router PPPoE WAN settings.</flux:description>
                    <flux:error name="password" />
                </flux:field>

                <flux:field>
                    <flux:label>Customer name</flux:label>
                    <flux:input wire:model.blur="full_name" icon="identification" placeholder="Customer full name" />
                    <flux:error name="full_name" />
                </flux:field>

                <flux:field>
                    <flux:label>Phone</flux:label>
                    <flux:input wire:model.blur="phone" icon="phone" placeholder="080..." />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>Email</flux:label>
                    <flux:input type="email" wire:model.blur="email" icon="envelope" placeholder="customer@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>Starts at</flux:label>
                    <flux:input type="datetime-local" wire:model.blur="starts_at" />
                    <flux:error name="starts_at" />
                </flux:field>

                <flux:field>
                    <flux:label>Expires at</flux:label>
                    <flux:input type="datetime-local" wire:model.blur="expires_at" />
                    <flux:description>Leave blank to use the selected package duration.</flux:description>
                    <flux:error name="expires_at" />
                </flux:field>
            </div>

            <flux:checkbox wire:model.live="is_active" label="Active and provisioned to FreeRADIUS" />

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showFormModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save Customer</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showInspectModal" class="md:w-5xl" :dismissible="true" variant="flyout">
        @if ($selectedSubscriber)
            @php
                $usage = $selectedSubscriber->radius_usage;
                $sessions = $selectedSubscriber->radius_sessions;
                $isActive = $selectedSubscriber->isCurrentlyActive();
            @endphp

            <div class="space-y-6">
                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                    <div>
                        <flux:heading level="2" size="lg">PPPoE Activity</flux:heading>
                        <flux:text class="mt-2 text-sm text-zinc-500">
                            {{ $selectedSubscriber->username }} on {{ $selectedSubscriber->shop?->name ?? 'Deleted shop' }}
                        </flux:text>
                    </div>

                    <flux:badge :color="$isActive ? 'green' : ($selectedSubscriber->is_active ? 'amber' : 'zinc')">
                        {{ $isActive ? 'Active' : ($selectedSubscriber->is_active ? 'Expired' : 'Disabled') }}
                    </flux:badge>
                </div>

                <section class="grid gap-3 md:grid-cols-5">
                    @foreach ([
                        ['label' => 'Total transfer', 'value' => $formatBytes($usage['total_bytes'])],
                        ['label' => 'Download', 'value' => $formatBytes($usage['download_bytes'])],
                        ['label' => 'Upload', 'value' => $formatBytes($usage['upload_bytes'])],
                        ['label' => 'Online sessions', 'value' => number_format($usage['open_session_count'])],
                        ['label' => 'Total sessions', 'value' => number_format($usage['session_count'])],
                    ] as $stat)
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase text-zinc-500">{{ $stat['label'] }}</p>
                            <p class="mt-2 text-xl font-semibold">{{ $stat['value'] }}</p>
                        </div>
                    @endforeach
                </section>

                <section class="grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 text-sm md:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Customer</p>
                        <p class="mt-1 font-medium">{{ $selectedSubscriber->full_name ?: 'Unnamed customer' }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ $selectedSubscriber->phone ?: 'No phone' }}{{ $selectedSubscriber->email ? ' - '.$selectedSubscriber->email : '' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Credentials</p>
                        <p class="mt-1 font-mono text-xs font-medium">{{ $selectedSubscriber->username }}</p>
                        <p class="mt-1 text-xs text-zinc-500">Password is hidden. Edit this customer to reset it.</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Package</p>
                        <p class="mt-1 font-medium">{{ $selectedSubscriber->package?->name ?? 'Deleted package' }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ $selectedSubscriber->package?->speed_limit_profile ?: 'No bandwidth profile' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Access window</p>
                        <p class="mt-1 font-medium">
                            {{ $selectedSubscriber->starts_at ? $selectedSubscriber->starts_at->format('M j, Y g:i A') : 'No start' }}
                            -
                            {{ $selectedSubscriber->expires_at ? $selectedSubscriber->expires_at->format('M j, Y g:i A') : 'No expiry' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Last seen</p>
                        <p class="mt-1 font-medium">{{ $usage['last_seen_at'] ? $usage['last_seen_at']->format('M j, Y g:i A') : 'No accounting yet' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-zinc-500">Tenant</p>
                        <p class="mt-1 font-medium">{{ $selectedSubscriber->shop?->tenant?->company_name ?? 'Unknown tenant' }}</p>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3">
                        <h3 class="font-semibold">RADIUS accounting sessions</h3>
                        <p class="mt-1 text-xs text-zinc-500">These are the latest FreeRADIUS records for this PPPoE username.</p>
                    </div>

                    <div class="overflow-x-auto overflow-y-hidden">
                        <table class="min-w-[760px] w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 text-zinc-500">
                                <tr>
                                    <th class="px-4 py-3 font-medium">Session</th>
                                    <th class="px-4 py-3 font-medium">Router / IP</th>
                                    <th class="px-4 py-3 font-medium">Started</th>
                                    <th class="px-4 py-3 font-medium">Updated / Stopped</th>
                                    <th class="px-4 py-3 text-right font-medium">Transfer</th>
                                    <th class="px-4 py-3 font-medium">End reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @forelse ($sessions as $session)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <p class="font-mono text-xs font-medium">{{ $session->acctsessionid }}</p>
                                            <p class="mt-1 font-mono text-xs text-zinc-500">{{ $session->acctuniqueid }}</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-mono text-xs">{{ $session->nasipaddress ?: 'Unknown NAS' }}</p>
                                            <p class="mt-1 font-mono text-xs text-zinc-500">{{ $session->framedipaddress ?: 'No framed IP' }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-zinc-600">
                                            {{ $session->acctstarttime ? \Illuminate\Support\Carbon::parse($session->acctstarttime)->format('M j, Y g:i A') : 'Unknown' }}
                                        </td>
                                        <td class="px-4 py-3 text-zinc-600">
                                            <p>{{ $session->acctupdatetime ? \Illuminate\Support\Carbon::parse($session->acctupdatetime)->format('M j, Y g:i A') : 'No update' }}</p>
                                            <p class="mt-1 text-xs text-zinc-500">{{ $session->acctstoptime ? 'Stopped '.\Illuminate\Support\Carbon::parse($session->acctstoptime)->format('M j, Y g:i A') : 'Still online / no stop' }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <p class="font-medium">{{ $formatBytes($session->total_bytes) }}</p>
                                            <p class="mt-1 text-xs text-zinc-500">Down {{ $formatBytes($session->download_bytes) }}</p>
                                            <p class="mt-1 text-xs text-zinc-500">Up {{ $formatBytes($session->upload_bytes) }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-zinc-600">
                                            {{ $session->acctterminatecause ?: 'Not stopped' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500">
                                            No RADIUS accounting sessions were found for this PPPoE username.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="outline" wire:click="renew({{ $selectedSubscriber->id }})" wire:loading.attr="disabled" wire:target="renew({{ $selectedSubscriber->id }})" icon="arrow-path">
                        <span wire:loading.remove wire:target="renew({{ $selectedSubscriber->id }})">Renew Customer</span>
                        <span wire:loading wire:target="renew({{ $selectedSubscriber->id }})">Renewing...</span>
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="closeInspect">Close</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model.self="showDeleteModal" class="md:w-lg" :dismissible="true">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Delete PPPoE Customer</flux:heading>
                <flux:text class="mt-2">This removes the subscriber from MMS Radius and FreeRADIUS.</flux:text>
            </div>

            @if ($deletingSubscriber)
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    <p class="font-medium">{{ $deletingSubscriber->username }}</p>
                    <p class="mt-1">{{ $deletingSubscriber->full_name ?: 'Unnamed customer' }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">Delete Customer</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
