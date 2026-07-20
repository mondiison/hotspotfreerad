<x-layouts.admin
    :title="$tenant->exists ? 'Edit Tenant' : 'Add Tenant'"
    :heading="$tenant->exists ? 'Edit Tenant' : 'Add Tenant'"
    subheading="Tenant records group shops, routers, packages, and billing settings."
>
    <form method="POST" action="{{ $tenant->exists ? route('admin.tenants.update', $tenant) : route('admin.tenants.store') }}" class="max-w-4xl rounded-lg border border-zinc-200 bg-white p-6">
        @csrf
        @if ($tenant->exists)
            @method('PUT')
        @endif

        <div class="grid gap-6">
            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Account</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Company name</flux:label>
                        <flux:input name="company_name" value="{{ old('company_name', $tenant->company_name) }}" icon="building-office" required />
                        <flux:error name="company_name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Owner email</flux:label>
                        <flux:input type="email" name="owner_email" value="{{ old('owner_email', $tenant->owner_email) }}" icon="envelope" required />
                        <flux:description>{{ $tenant->exists ? 'This is the tenant owner login email. Use the reset action below to send a password link.' : 'This email becomes the tenant admin login. A temporary password will be emailed after creation.' }}</flux:description>
                        <flux:error name="owner_email" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Subscription plan</flux:label>
                        <flux:input name="subscription_plan" value="{{ old('subscription_plan', $tenant->subscription_plan ?? 'basic') }}" icon="credit-card" required />
                        <flux:description>Example: basic, growth, pro, enterprise.</flux:description>
                        <flux:error name="subscription_plan" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Tenant billing model</flux:label>
                        <flux:select name="billing_model">
                            <flux:select.option value="subscription" :selected="old('billing_model', $tenant->billing_model ?? 'subscription') === 'subscription'">Subscription</flux:select.option>
                            <flux:select.option value="commission" :selected="old('billing_model', $tenant->billing_model ?? 'subscription') === 'commission'">Commission on sales</flux:select.option>
                        </flux:select>
                        <flux:description>Subscription charges the tenant separately. Commission deducts a platform percentage from each hotspot sale.</flux:description>
                        <flux:error name="billing_model" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Commission rate</flux:label>
                        <flux:input type="number" name="commission_rate" value="{{ old('commission_rate', $tenant->commission_rate ?? 0) }}" min="0" max="100" step="0.01" icon="receipt-percent" />
                        <flux:description>Example: 10 means the platform keeps 10% and the tenant net is 90%.</flux:description>
                        <flux:error name="commission_rate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Trial ends at</flux:label>
                        <flux:input type="datetime-local" name="trial_ends_at" value="{{ old('trial_ends_at', optional($tenant->trial_ends_at)->format('Y-m-d\TH:i')) }}" />
                        <flux:error name="trial_ends_at" />
                    </flux:field>

                    <div class="md:col-span-2">
                        <flux:checkbox name="is_active" value="1" :checked="(bool) old('is_active', $tenant->is_active ?? true)" label="Active tenant account" />
                    </div>
                </div>
            </section>

            <section class="border-t border-zinc-200 pt-6">
                <div class="flex flex-col justify-between gap-2 md:flex-row md:items-start">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Public site</h2>
                        <p class="mt-1 text-sm text-zinc-500">This gives each tenant a simple branded page like <span class="font-medium text-zinc-700">{{ url('/demo-hotspot') }}</span>.</p>
                    </div>

                    @if ($tenant->exists && $tenant->slug)
                        <flux:button href="{{ $tenant->publicUrl() }}" target="_blank" variant="outline" icon="arrow-top-right-on-square">Preview site</flux:button>
                    @endif
                </div>

                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Unique slug</flux:label>
                        <flux:input name="slug" value="{{ old('slug', $tenant->slug) }}" icon="link" placeholder="demo-hotspot" />
                        <flux:description>Use lowercase words with hyphens. Leave blank to generate it from the company name.</flux:description>
                        <flux:error name="slug" />
                    </flux:field>

                    <div class="block">
                        <span class="text-sm font-medium">Brand color</span>
                        <flux:color-picker
                            name="brand_color"
                            value="{{ old('brand_color', $tenant->brand_color ?? '#0f766e') }}"
                            format="hex"
                            copyable
                            :swatches="['#0f766e', '#2563eb', '#7c3aed', '#dc2626', '#f59e0b', '#16a34a', '#0891b2', '#111827']"
                        />
                        <flux:description>Example: #0f766e. This accents buttons and public-site highlights.</flux:description>
                        <flux:error name="brand_color" />
                    </div>

                    <flux:field class="md:col-span-2">
                        <flux:label>Tagline</flux:label>
                        <flux:input name="public_site_tagline" value="{{ old('public_site_tagline', $tenant->public_site_tagline) }}" icon="sparkles" placeholder="Fast Wi-Fi for guests, students, and daily users." />
                        <flux:error name="public_site_tagline" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>About this hotspot business</flux:label>
                        <flux:textarea name="public_site_about" rows="4" placeholder="Tell customers what kind of locations you serve, support hours, or why your internet access is reliable.">{{ old('public_site_about', $tenant->public_site_about) }}</flux:textarea>
                        <flux:error name="public_site_about" />
                    </flux:field>

                    <div class="md:col-span-2">
                        <flux:checkbox name="public_site_enabled" value="1" :checked="(bool) old('public_site_enabled', $tenant->public_site_enabled ?? true)" label="Public site enabled" />
                    </div>
                </div>
            </section>

            <section class="border-t border-zinc-200 pt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Customer contact</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Contact phone</flux:label>
                        <flux:input name="contact_phone" value="{{ old('contact_phone', $tenant->contact_phone) }}" icon="phone" placeholder="+234 800 000 0000" />
                        <flux:error name="contact_phone" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Contact email</flux:label>
                        <flux:input type="email" name="contact_email" value="{{ old('contact_email', $tenant->contact_email ?? $tenant->owner_email) }}" icon="envelope" placeholder="support@example.com" />
                        <flux:error name="contact_email" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>Contact address</flux:label>
                        <flux:textarea name="contact_address" rows="3" placeholder="Shop address or coverage area customers should recognize.">{{ old('contact_address', $tenant->contact_address) }}</flux:textarea>
                        <flux:error name="contact_address" />
                    </flux:field>
                </div>
            </section>
        </div>

        <div class="mt-6 flex gap-3">
            <flux:button type="submit" variant="primary" icon="check">Save Tenant</flux:button>
            <flux:button href="{{ route('admin.tenants.index') }}" wire:navigate variant="outline">Cancel</flux:button>
        </div>
    </form>

    @if ($tenant->exists)
        <section class="mt-6 max-w-4xl rounded-lg border border-zinc-200 bg-white p-6">
            <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Tenant Admin Access</h2>
                    <p class="mt-2 text-sm text-zinc-600">Send a password reset link to {{ $tenant->owner_email }}. This avoids exposing the tenant admin password inside the platform.</p>
                </div>

                <form method="POST" action="{{ route('admin.tenants.owner-reset-link', $tenant) }}">
                    @csrf
                    <flux:button type="submit" variant="outline" icon="envelope">Send reset link</flux:button>
                </form>
            </div>

            @error('owner_email')
                <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </section>
    @endif
</x-layouts.admin>
