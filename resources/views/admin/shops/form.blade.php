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

            <section class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                <div class="mb-4">
                    <h2 class="text-sm font-semibold text-zinc-950">Flutterwave payments</h2>
                    <p class="mt-1 text-sm leading-6 text-zinc-600">
                        Add this shop's Flutterwave v4 client ID and client secret so hotspot customer payments settle into the tenant's own Flutterwave account. If these are empty, online customer payments stay disabled for this shop until the tenant connects an account.
                    </p>
                    @if ($shop->exists)
                        <p class="mt-2 text-xs font-medium text-zinc-500">
                            Existing saved secrets are hidden. Leave a credential field empty when editing to keep the current value.
                        </p>
                    @endif
                </div>

                <div class="grid gap-5">
                    <label class="block">
                        <span class="text-sm font-medium">Flutterwave client ID</span>
                        <input name="flutterwave_client_id" value="{{ old('flutterwave_client_id') }}" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : 'Example: FLW_CLIENT_...' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        <span class="mt-1 block text-xs text-zinc-500">Required with client secret for tenant-owned collections.</span>
                        @error('flutterwave_client_id') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Flutterwave client secret</span>
                        <input name="flutterwave_client_secret" value="{{ old('flutterwave_client_secret') }}" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : 'Paste the matching v4 client secret' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        <span class="mt-1 block text-xs text-zinc-500">The app uses this only on the server to request Flutterwave access tokens.</span>
                        @error('flutterwave_client_secret') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Flutterwave webhook secret hash</span>
                        <input name="flutterwave_webhook_secret" value="{{ old('flutterwave_webhook_secret') }}" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : 'Optional: tenant webhook verif-hash' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        <span class="mt-1 block text-xs text-zinc-500">Use the verif-hash from this tenant's Flutterwave webhook settings. Payment callbacks can still verify successful payments, but webhooks need this value.</span>
                        @error('flutterwave_webhook_secret') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>

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
