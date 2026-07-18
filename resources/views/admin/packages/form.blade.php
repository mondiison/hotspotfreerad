<x-layouts.admin
    :title="$package->exists ? 'Edit Package' : 'Add Package'"
    :heading="$package->exists ? 'Edit Package' : 'Add Package'"
    subheading="Package saves are mirrored into radgroupreply as reusable RADIUS profiles."
>
    <form method="POST" action="{{ $package->exists ? route('admin.packages.update', $package) : route('admin.packages.store') }}" class="max-w-4xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($package->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Shop</span>
                <select name="shop_id" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    <option value="">Select shop</option>
                    @foreach ($shops as $shop)
                        <option value="{{ $shop->id }}" @selected(old('shop_id', $package->shop_id) == $shop->id)>{{ $shop->name }} / {{ $shop->tenant->company_name }}</option>
                    @endforeach
                </select>
                @error('shop_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Package name</span>
                <input name="name" value="{{ old('name', $package->name) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">RADIUS group name</span>
                <input name="radius_group_name" value="{{ old('radius_group_name', $package->radius_group_name) }}" placeholder="Auto-generated if blank" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('radius_group_name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Price</span>
                <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $package->price) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('price') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Currency</span>
                <input name="currency" value="{{ old('currency', $package->currency ?? 'NGN') }}" maxlength="3" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 uppercase" required>
                @error('currency') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Uptime limit seconds</span>
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
                @error('limit_uptime_seconds') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">MikroTik rate limit</span>
                <input name="speed_limit_profile" value="{{ old('speed_limit_profile', $package->speed_limit_profile) }}" placeholder="5M/5M" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                <span class="mt-1 block text-xs text-zinc-500">Upload/download format. Examples: <code>2M/5M</code>, <code>5M/5M</code>, <code>10M/20M</code>.</span>
                @error('speed_limit_profile') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Total transferred data</span>
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
                <span class="mt-1 block text-xs text-zinc-500">Leave blank for unlimited. Limited plans sync MikroTik total-limit RADIUS attributes.</span>
                @error('data_limit_bytes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">FUP threshold bytes</span>
                <input type="number" min="1" name="fup_data_threshold_bytes" value="{{ old('fup_data_threshold_bytes', $package->fup_data_threshold_bytes) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('fup_data_threshold_bytes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">FUP speed limit</span>
                <input name="fup_speed_limit_profile" value="{{ old('fup_speed_limit_profile', $package->fup_speed_limit_profile) }}" placeholder="1M/1M" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('fup_speed_limit_profile') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active ?? true)) class="rounded border-zinc-300">
                Active
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Package</button>
            <a href="{{ route('admin.packages.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
