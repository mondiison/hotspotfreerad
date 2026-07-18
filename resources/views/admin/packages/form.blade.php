<x-layouts.admin
    :title="$package->exists ? 'Edit Package' : 'Add Package'"
    :heading="$package->exists ? 'Edit Package' : 'Add Package'"
    subheading="Build sellable hotspot data plans. Each save syncs a reusable RADIUS profile."
>
    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <form method="POST" action="{{ $package->exists ? route('admin.packages.update', $package) : route('admin.packages.store') }}" class="rounded-lg border border-zinc-200 bg-white p-6">
            @csrf
            @if ($package->exists)
                @method('PUT')
            @endif

            <div class="grid gap-6">
                <section>
                    <h2 class="text-base font-semibold">Plan Basics</h2>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <label class="block md:col-span-2">
                            <span class="text-sm font-medium">Shop</span>
                            <select name="shop_id" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                                <option value="">Select shop</option>
                                @foreach ($shops as $shop)
                                    <option value="{{ $shop->id }}" @selected(old('shop_id', $package->shop_id) == $shop->id)>{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                                @endforeach
                            </select>
                            <span class="mt-1 block text-xs text-zinc-500">This package will appear only on routers attached to this shop.</span>
                            @error('shop_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">Package name</span>
                            <input name="name" value="{{ old('name', $package->name) }}" placeholder="Daily 5GB" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                            <span class="mt-1 block text-xs text-zinc-500">Customer-facing name. Examples: Daily 5GB, Weekly Unlimited, 30-Day Basic.</span>
                            @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">RADIUS group name</span>
                            <input name="radius_group_name" value="{{ old('radius_group_name', $package->radius_group_name) }}" placeholder="Auto-generated if blank" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                            <span class="mt-1 block text-xs text-zinc-500">Leave blank unless you need a specific FreeRADIUS group name.</span>
                            @error('radius_group_name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">Price</span>
                            <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $package->price) }}" placeholder="500" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                            <span class="mt-1 block text-xs text-zinc-500">Amount customers pay for this plan.</span>
                            @error('price') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">Currency</span>
                            <input name="currency" value="{{ old('currency', $package->currency ?? 'NGN') }}" maxlength="3" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 uppercase" required>
                            <span class="mt-1 block text-xs text-zinc-500">Three-letter code. Example: NGN.</span>
                            @error('currency') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </section>

                <section class="border-t border-zinc-200 pt-6">
                    <h2 class="text-base font-semibold">Access Rules</h2>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium">Uptime</span>
                            <select class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" onchange="document.querySelector('[name=limit_uptime_seconds]').value = this.value">
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
                                    <option value="{{ $seconds }}" @selected((string) old('limit_uptime_seconds', $package->limit_uptime_seconds) === (string) $seconds)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="number" min="60" name="limit_uptime_seconds" value="{{ old('limit_uptime_seconds', $package->limit_uptime_seconds) }}" placeholder="3600" class="mt-2 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                            <span class="mt-1 block text-xs text-zinc-500">Session duration in seconds. 1 day = 86400, 7 days = 604800, 30 days = 2592000.</span>
                            @error('limit_uptime_seconds') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">Bandwidth</span>
                            <input name="speed_limit_profile" value="{{ old('speed_limit_profile', $package->speed_limit_profile) }}" placeholder="5M/5M" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                            <span class="mt-1 block text-xs text-zinc-500">Upload/download format. Examples: <code>2M/5M</code>, <code>5M/5M</code>, <code>10M/20M</code>.</span>
                            @error('speed_limit_profile') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">Hard data cap</span>
                            <select class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" onchange="document.querySelector('[name=data_limit_bytes]').value = this.value">
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
                                    <option value="{{ $bytes }}" @selected((string) old('data_limit_bytes', $package->data_limit_bytes) === (string) $bytes)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="number" min="1" name="data_limit_bytes" value="{{ old('data_limit_bytes', $package->data_limit_bytes) }}" placeholder="Leave blank for unlimited" class="mt-2 w-full rounded-md border border-zinc-300 px-3 py-2">
                            <span class="mt-1 block text-xs text-zinc-500">Leave blank for unlimited. If set, the user is cut off when total upload + download reaches this byte value.</span>
                            @error('data_limit_bytes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </section>

                <section class="border-t border-zinc-200 pt-6">
                    <h2 class="text-base font-semibold">Fair Usage Policy</h2>
                    <p class="mt-1 text-sm text-zinc-500">Optional soft cap. Instead of cutting users off, throttle them after heavy usage.</p>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium">FUP threshold</span>
                            <input type="number" min="1" name="fup_data_threshold_bytes" value="{{ old('fup_data_threshold_bytes', $package->fup_data_threshold_bytes) }}" placeholder="Leave blank for no FUP" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                            <span class="mt-1 block text-xs text-zinc-500">Byte value where throttling starts. Example: 20GB = 21474836480.</span>
                            @error('fup_data_threshold_bytes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">FUP speed</span>
                            <input name="fup_speed_limit_profile" value="{{ old('fup_speed_limit_profile', $package->fup_speed_limit_profile) }}" placeholder="1M/1M" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                            <span class="mt-1 block text-xs text-zinc-500">Speed after FUP threshold. Example: reduce a 10M/10M plan to 1M/1M.</span>
                            @error('fup_speed_limit_profile') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </section>

                <label class="flex items-center gap-2 border-t border-zinc-200 pt-6 text-sm">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active ?? true)) class="rounded border-zinc-300">
                    Active and visible on the captive portal
                </label>
            </div>

            <div class="mt-6 flex gap-3">
                <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Package</button>
                <a href="{{ route('admin.packages.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
            </div>
        </form>

        <aside class="space-y-4">
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
</x-layouts.admin>
