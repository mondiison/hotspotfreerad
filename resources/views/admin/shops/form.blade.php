<x-layouts.admin
    :title="$shop->exists ? 'Edit Shop' : 'Add Shop'"
    :heading="$shop->exists ? 'Edit Shop' : 'Add Shop'"
    subheading="Shops own routers, packages, portal branding, and payment credentials."
>
    <div class="max-w-3xl space-y-6">
        @include('admin.partials.billing-usage', ['usage' => $billingUsage ?? null])

        <form method="POST" action="{{ $shop->exists ? route('admin.shops.update', $shop) : route('admin.shops.store') }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6">
        @csrf
        @if ($shop->exists)
            @method('PUT')
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            <flux:field class="md:col-span-2">
                <flux:label>Tenant</flux:label>
                <flux:select name="tenant_id" required>
                    <option value="">Select tenant</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(old('tenant_id', $shop->tenant_id) == $tenant->id)>{{ $tenant->company_name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="tenant_id" />
            </flux:field>

            <flux:field>
                <flux:label>Shop name</flux:label>
                <flux:input name="name" value="{{ old('name', $shop->name) }}" icon="building-storefront" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>City</flux:label>
                <flux:input name="location_city" value="{{ old('location_city', $shop->location_city) }}" icon="map-pin" />
                <flux:error name="location_city" />
            </flux:field>

            <flux:checkbox name="is_active" value="1" :checked="(bool) old('is_active', $shop->is_active ?? true)" label="Active" />
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save Shop</flux:button>
            <flux:button href="{{ route('admin.shops.index') }}" wire:navigate variant="outline">Cancel</flux:button>
        </div>
        </form>

        @if ($shop->exists)
            <livewire:admin.payment-settings-card :shop="$shop" />
        @else
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                Save this shop first, then reopen it here to choose the payment gateway, enter its credentials, and set Live/Test environment where the gateway supports it.
            </div>
        @endif
    </div>
</x-layouts.admin>
