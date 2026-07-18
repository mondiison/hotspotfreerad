<x-layouts.admin
    :title="$shop->exists ? 'Edit Shop' : 'Add Shop'"
    :heading="$shop->exists ? 'Edit Shop' : 'Add Shop'"
    subheading="Shops own routers, packages, portal branding, and payment credentials."
>
    <form method="POST" action="{{ $shop->exists ? route('admin.shops.update', $shop) : route('admin.shops.store') }}" class="max-w-3xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($shop->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Tenant</span>
                <select name="tenant_id" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                    <option value="">Select tenant</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(old('tenant_id', $shop->tenant_id) == $tenant->id)>{{ $tenant->company_name }}</option>
                    @endforeach
                </select>
                @error('tenant_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Shop name</span>
                <input name="name" value="{{ old('name', $shop->name) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">City</span>
                <input name="location_city" value="{{ old('location_city', $shop->location_city) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('location_city') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Flutterwave client ID</span>
                <input name="flutterwave_client_id" value="{{ old('flutterwave_client_id') }}" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : '' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('flutterwave_client_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Flutterwave client secret</span>
                <input name="flutterwave_client_secret" value="{{ old('flutterwave_client_secret') }}" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : '' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('flutterwave_client_secret') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium">Flutterwave webhook secret</span>
                <input name="flutterwave_webhook_secret" value="{{ old('flutterwave_webhook_secret') }}" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : '' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('flutterwave_webhook_secret') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $shop->is_active ?? true)) class="rounded border-zinc-300">
                Active
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Shop</button>
            <a href="{{ route('admin.shops.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
