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
                <input type="number" min="60" name="limit_uptime_seconds" value="{{ old('limit_uptime_seconds', $package->limit_uptime_seconds) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('limit_uptime_seconds') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">MikroTik rate limit</span>
                <input name="speed_limit_profile" value="{{ old('speed_limit_profile', $package->speed_limit_profile) }}" placeholder="5M/5M" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('speed_limit_profile') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
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
