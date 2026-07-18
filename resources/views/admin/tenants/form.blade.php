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
            <label class="block">
                <span class="text-sm font-medium">Company name</span>
                <input name="company_name" value="{{ old('company_name', $tenant->company_name) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('company_name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Owner email</span>
                <input type="email" name="owner_email" value="{{ old('owner_email', $tenant->owner_email) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                @error('owner_email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Subscription plan</span>
                <input name="subscription_plan" value="{{ old('subscription_plan', $tenant->subscription_plan ?? 'basic') }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2" required>
                <span class="mt-1 block text-xs text-zinc-500">Example: basic, growth, pro, enterprise.</span>
                @error('subscription_plan') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Trial ends at</span>
                <input type="datetime-local" name="trial_ends_at" value="{{ old('trial_ends_at', optional($tenant->trial_ends_at)->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                @error('trial_ends_at') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>

                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $tenant->is_active ?? true)) class="rounded border-zinc-300">
                        Active tenant account
            </label>
                </div>
            </section>

            <section class="border-t border-zinc-200 pt-6">
                <div class="flex flex-col justify-between gap-2 md:flex-row md:items-start">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Public site</h2>
                        <p class="mt-1 text-sm text-zinc-500">This gives each tenant a simple branded page like <span class="font-medium text-zinc-700">{{ url('/demo-hotspot') }}</span>.</p>
                    </div>

                    @if ($tenant->exists && $tenant->slug)
                        <a href="{{ $tenant->publicUrl() }}" target="_blank" class="rounded-md border border-zinc-200 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50">Preview site</a>
                    @endif
                </div>

                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium">Unique slug</span>
                        <input name="slug" value="{{ old('slug', $tenant->slug) }}" placeholder="demo-hotspot" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        <span class="mt-1 block text-xs text-zinc-500">Use lowercase words with hyphens. Leave blank to generate it from the company name.</span>
                        @error('slug') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Brand color</span>
                        <div class="mt-1 flex overflow-hidden rounded-md border border-zinc-300">
                            <input type="color" value="{{ old('brand_color', $tenant->brand_color ?? '#0f766e') }}" class="h-10 w-14 border-0 bg-white p-1" onchange="this.nextElementSibling.value = this.value">
                            <input name="brand_color" value="{{ old('brand_color', $tenant->brand_color ?? '#0f766e') }}" class="min-w-0 flex-1 border-0 px-3 py-2 focus:ring-0" required>
                        </div>
                        <span class="mt-1 block text-xs text-zinc-500">Example: #0f766e. This accents buttons and public-site highlights.</span>
                        @error('brand_color') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium">Tagline</span>
                        <input name="public_site_tagline" value="{{ old('public_site_tagline', $tenant->public_site_tagline) }}" placeholder="Fast Wi-Fi for guests, students, and daily users." class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        @error('public_site_tagline') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium">About this hotspot business</span>
                        <textarea name="public_site_about" rows="4" placeholder="Tell customers what kind of locations you serve, support hours, or why your internet access is reliable." class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">{{ old('public_site_about', $tenant->public_site_about) }}</textarea>
                        @error('public_site_about') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                        <input type="checkbox" name="public_site_enabled" value="1" @checked(old('public_site_enabled', $tenant->public_site_enabled ?? true)) class="rounded border-zinc-300">
                        Public site enabled
                    </label>
                </div>
            </section>

            <section class="border-t border-zinc-200 pt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Customer contact</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium">Contact phone</span>
                        <input name="contact_phone" value="{{ old('contact_phone', $tenant->contact_phone) }}" placeholder="+234 800 000 0000" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        @error('contact_phone') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Contact email</span>
                        <input type="email" name="contact_email" value="{{ old('contact_email', $tenant->contact_email ?? $tenant->owner_email) }}" placeholder="support@example.com" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        @error('contact_email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium">Contact address</span>
                        <textarea name="contact_address" rows="3" placeholder="Shop address or coverage area customers should recognize." class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">{{ old('contact_address', $tenant->contact_address) }}</textarea>
                        @error('contact_address') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>
        </div>

        <div class="mt-6 flex gap-3">
            <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Tenant</button>
            <a href="{{ route('admin.tenants.index') }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
