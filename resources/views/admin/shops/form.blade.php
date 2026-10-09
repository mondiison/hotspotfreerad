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

            <flux:field>
                <flux:label>Contact phone</flux:label>
                <flux:input name="contact_phone" value="{{ old('contact_phone', $shop->contact_phone) }}" icon="phone" placeholder="+234 800 000 0000" />
                <flux:description>Shown on the hotspot portal's "Call to get a voucher" link. Leave blank to use the tenant's own contact phone.</flux:description>
                <flux:error name="contact_phone" />
            </flux:field>

            <flux:checkbox name="is_active" value="1" :checked="(bool) old('is_active', $shop->is_active ?? true)" label="Active" />

            <flux:field class="md:col-span-2">
                <flux:checkbox name="allow_test_access" value="1" :checked="(bool) old('allow_test_access', $shop->allow_test_access ?? false)" label="Allow free test access (per package, debugging only)" />
                <flux:description>Shows a "Start test access" button inside every package's details on this shop's captive portal, granting that package's full duration and bandwidth with no payment. Intended for staff to verify a router/package is working -- not a customer-facing promotion. Off by default.</flux:description>
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:checkbox name="auto_recover_mac_changes" value="1" :checked="(bool) old('auto_recover_mac_changes', $shop->auto_recover_mac_changes ?? false)" label="Automatically recover access when a device's MAC address changes" />
                <flux:description>iOS/Android can present a different (privacy-randomized) MAC address on reconnect, leaving an already-paid customer stuck on the login screen for access they already own. When on, a device recognized by its recognition cookie as one that still has an active subscription under a different MAC is moved to its current MAC automatically, with no support contact needed -- skipped entirely if the new MAC already has its own active subscription. Off by default.</flux:description>
            </flux:field>

            <div class="md:col-span-2 rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:field>
                    <flux:checkbox name="trial_enabled" value="1" :checked="(bool) old('trial_enabled', $shop->trial_enabled ?? false)" label="Enable free trial (customer-facing promotion)" />
                    <flux:description>Shows a standalone "Free trial" option on the captive portal, separate from every package -- a limited, throttled taste of the service meant to draw in real customers. Capped per device per day.</flux:description>
                </flux:field>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>Duration (minutes)</flux:label>
                        <flux:input type="number" min="1" max="1440" name="trial_duration_minutes" value="{{ old('trial_duration_minutes', $shop->trial_duration_minutes ?? 15) }}" />
                        <flux:error name="trial_duration_minutes" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Uses per device / day</flux:label>
                        <flux:input type="number" min="1" max="255" name="trial_max_uses_per_day" value="{{ old('trial_max_uses_per_day', $shop->trial_max_uses_per_day ?? 1) }}" />
                        <flux:error name="trial_max_uses_per_day" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Bandwidth (rate limit)</flux:label>
                        <flux:input name="trial_speed_limit_profile" value="{{ old('trial_speed_limit_profile', $shop->trial_speed_limit_profile ?? '1M/1M') }}" placeholder="1M/1M" />
                        <flux:description>MikroTik rate-limit format: upload/download, e.g. 1M/1M.</flux:description>
                        <flux:error name="trial_speed_limit_profile" />
                    </flux:field>
                </div>
            </div>
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
