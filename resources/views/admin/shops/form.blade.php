<x-layouts.admin
    :title="$shop->exists ? 'Edit Shop' : 'Add Shop'"
    :heading="$shop->exists ? 'Edit Shop' : 'Add Shop'"
    subheading="Shops own routers, packages, portal branding, and payment credentials."
>
    <div class="max-w-3xl space-y-6">
        @include('admin.partials.billing-usage', ['usage' => $billingUsage ?? null])

        <form method="POST" action="{{ $shop->exists ? route('admin.shops.update', $shop) : route('admin.shops.store') }}" class="rounded-lg border border-zinc-200 bg-white p-6">
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
                        <div class="mt-3 flex flex-wrap gap-2">
                            <flux:badge :color="$shop->hasCompleteFlutterwaveCredentials() ? 'emerald' : 'amber'" size="sm">
                                {{ $shop->hasCompleteFlutterwaveCredentials() ? 'Payments configured' : 'Payments not configured' }}
                            </flux:badge>
                            <flux:badge :color="$shop->hasFlutterwaveWebhookSecret() ? 'emerald' : 'zinc'" size="sm">
                                {{ $shop->hasFlutterwaveWebhookSecret() ? 'Webhook ready' : 'Webhook secret missing' }}
                            </flux:badge>
                        </div>
                    @endif
                </div>

                <div class="grid gap-5">
                    <flux:field>
                        <flux:label>Flutterwave client ID</flux:label>
                        <flux:input name="flutterwave_client_id" value="{{ old('flutterwave_client_id') }}" icon="identification" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : 'Example: FLW_CLIENT_...' }}" />
                        <flux:description>Required with client secret for tenant-owned collections.</flux:description>
                        <flux:error name="flutterwave_client_id" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Flutterwave client secret</flux:label>
                        <flux:input name="flutterwave_client_secret" value="{{ old('flutterwave_client_secret') }}" icon="key" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : 'Paste the matching v4 client secret' }}" viewable />
                        <flux:description>The app uses this only on the server to request Flutterwave access tokens.</flux:description>
                        <flux:error name="flutterwave_client_secret" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Flutterwave secret key</flux:label>
                        <flux:input name="flutterwave_secret_key" value="{{ old('flutterwave_secret_key') }}" icon="lock-closed" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : 'Example: FLWSECK_TEST-...' }}" viewable />
                        <flux:description>Needed for Card hosted checkout. Client ID/secret still powers OPay and transfer.</flux:description>
                        <flux:error name="flutterwave_secret_key" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Flutterwave webhook secret hash</flux:label>
                        <flux:input name="flutterwave_webhook_secret" value="{{ old('flutterwave_webhook_secret') }}" icon="shield-check" placeholder="{{ $shop->exists ? 'Leave blank to keep current value' : 'Optional: tenant webhook verif-hash' }}" viewable />
                        <flux:description>Use the verif-hash from this tenant's Flutterwave webhook settings. Payment callbacks can still verify successful payments, but webhooks need this value.</flux:description>
                        <flux:error name="flutterwave_webhook_secret" />
                    </flux:field>
                </div>
            </section>

            <flux:checkbox name="is_active" value="1" :checked="(bool) old('is_active', $shop->is_active ?? true)" label="Active" />
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save Shop</flux:button>
            <flux:button href="{{ route('admin.shops.index') }}" wire:navigate variant="outline">Cancel</flux:button>
        </div>
        </form>
    </div>
</x-layouts.admin>
