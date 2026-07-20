<div class="grid gap-6 xl:grid-cols-[1fr_380px]">
    <form wire:submit="save" class="relative rounded-lg border border-zinc-200 bg-white p-6">
        <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
            <div class="rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow-sm">
                Saving and syncing RADIUS profile...
            </div>
        </div>

        <div class="grid gap-6">
            <section>
                <h2 class="text-base font-semibold">Plan Basics</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <flux:field class="md:col-span-2">
                        <flux:label>Shop</flux:label>
                        <flux:select wire:model="shop_id" required>
                            <option value="">Select shop</option>
                            @foreach ($shops as $shop)
                                <option value="{{ $shop->id }}">{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:description>This package will appear only on routers attached to this shop.</flux:description>
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
                        <flux:input type="number" step="0.01" min="0" wire:model.blur="price" icon="banknotes" placeholder="500" required />
                        <flux:description>Amount customers pay for this plan.</flux:description>
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
                <h2 class="text-base font-semibold">Access Rules</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Uptime</flux:label>
                        <flux:select wire:model.live="limit_uptime_seconds">
                            <option value="">Choose a common duration</option>
                            @foreach ([
                                3600 => '1 hour',
                                10800 => '3 hours',
                                21600 => '6 hours',
                                86400 => '1 day',
                                259200 => '3 days',
                                604800 => '7 days',
                                2592000 => '30 days',
                            ] as $seconds => $label)
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
                            @foreach ([
                                1073741824 => '1 GB',
                                2147483648 => '2 GB',
                                5368709120 => '5 GB',
                                10737418240 => '10 GB',
                                21474836480 => '20 GB',
                                53687091200 => '50 GB',
                                107374182400 => '100 GB',
                            ] as $bytes => $label)
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
                        <flux:description>Leave blank for unlimited. If set, the user is cut off when total upload + download reaches this byte value.</flux:description>
                        <flux:error name="data_limit_bytes" />
                    </flux:field>
                </div>
            </section>

            <section class="border-t border-zinc-200 pt-6">
                <h2 class="text-base font-semibold">Fair Usage Policy</h2>
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

            <div class="border-t border-zinc-200 pt-6">
                <flux:checkbox wire:model.live="is_active" label="Active and visible on the captive portal" />
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save Package</span>
                <span wire:loading wire:target="save">Saving...</span>
            </flux:button>
            <flux:button href="{{ route('admin.packages.index') }}" wire:navigate variant="outline">Cancel</flux:button>
        </div>
    </form>

    <aside class="space-y-4">
        @include('admin.partials.billing-usage', ['usage' => $billingUsage])

        <section class="rounded-lg border border-zinc-200 bg-zinc-950 p-5 text-white">
            <h2 class="text-base font-semibold">Plan Shape</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-400">Visibility</dt>
                    <dd>{{ $is_active ? 'Captive portal ready' : 'Hidden' }}</dd>
                </div>
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

        <section class="rounded-lg border border-zinc-200 bg-white p-5">
            <h2 class="text-base font-semibold">Example Plans</h2>
            <div class="mt-4 space-y-3 text-sm text-zinc-600">
                <div class="rounded-md bg-zinc-50 p-3">
                    <p class="font-medium text-zinc-950">Daily 5GB</p>
                    <p>1 day, 5GB hard cap, 5M/5M speed.</p>
                </div>
                <div class="rounded-md bg-zinc-50 p-3">
                    <p class="font-medium text-zinc-950">Weekly Unlimited</p>
                    <p>7 days, unlimited data, 3M/3M speed.</p>
                </div>
                <div class="rounded-md bg-zinc-50 p-3">
                    <p class="font-medium text-zinc-950">30-Day Fair Use</p>
                    <p>30 days, unlimited data, throttle after 20GB to 1M/1M.</p>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-zinc-200 bg-white p-5">
            <h2 class="text-base font-semibold">Byte Cheatsheet</h2>
            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                <dt class="text-zinc-500">1GB</dt><dd class="font-mono">1073741824</dd>
                <dt class="text-zinc-500">2GB</dt><dd class="font-mono">2147483648</dd>
                <dt class="text-zinc-500">5GB</dt><dd class="font-mono">5368709120</dd>
                <dt class="text-zinc-500">10GB</dt><dd class="font-mono">10737418240</dd>
                <dt class="text-zinc-500">20GB</dt><dd class="font-mono">21474836480</dd>
            </dl>
        </section>

        <section class="rounded-lg border border-zinc-200 bg-white p-5 text-sm text-zinc-600">
            <h2 class="text-base font-semibold text-zinc-950">Hard Cap vs FUP</h2>
            <p class="mt-3"><strong class="text-zinc-950">Hard cap</strong> stops access when the data limit is reached.</p>
            <p class="mt-2"><strong class="text-zinc-950">FUP</strong> keeps access alive but lowers speed after the threshold.</p>
        </section>
    </aside>
</div>
