<x-layouts.admin
    title="Brand"
    heading="Public Site Brand"
    subheading="Customize the tenant public page customers see before they connect or contact support."
>
    <form method="POST" action="{{ route('admin.brand.update') }}" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        @csrf
        @method('PUT')

        <div class="space-y-6">
            <section class="rounded-lg border border-zinc-200 bg-white p-6">
                <div>
                    <h2 class="text-base font-semibold">Public Page Copy</h2>
                    <p class="mt-1 text-sm text-zinc-500">These values control the tenant slug page: {{ $tenant->publicUrl() }}</p>
                </div>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <flux:color-picker
                            name="brand_color"
                            value="{{ old('brand_color', $tenant->brand_color ?? '#0f766e') }}"
                            label="Brand color"
                            format="hex"
                            copyable
                            :swatches="['#0f766e', '#2563eb', '#7c3aed', '#dc2626', '#f59e0b', '#16a34a', '#0891b2', '#111827']"
                        />
                        @error('brand_color') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium">Hero tagline</span>
                        <input name="public_site_tagline" value="{{ old('public_site_tagline', $tenant->public_site_tagline) }}" placeholder="Fast internet access for daily users." class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">
                        @error('public_site_tagline') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium">About this business</span>
                        <textarea name="public_site_about" rows="5" placeholder="Describe your hotspot locations, support promise, or coverage area." class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2">{{ old('public_site_about', $tenant->public_site_about) }}</textarea>
                        @error('public_site_about') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-6">
                <div>
                    <h2 class="text-base font-semibold">Media</h2>
                    <p class="mt-1 text-sm text-zinc-500">Upload public-facing images. Use compressed JPG, PNG, or WebP files under 4MB each.</p>
                </div>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium">Hero image</span>
                        <input type="file" name="hero_image" accept="image/*" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                        <span class="mt-1 block text-xs text-zinc-500">Best size: 1600x1000. This appears in the first screen.</span>
                        @error('hero_image') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Flyer image</span>
                        <input type="file" name="flyer_image" accept="image/*" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                        <span class="mt-1 block text-xs text-zinc-500">Use for promos, offers, or branch announcements.</span>
                        @error('flyer_image') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block md:col-span-2">
                        <span class="text-sm font-medium">Slider images</span>
                        <input type="file" name="slider_images[]" accept="image/*" multiple class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                        <span class="mt-1 block text-xs text-zinc-500">Upload up to 5 images. New uploads replace the existing slider set.</span>
                        @error('slider_images') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        @error('slider_images.*') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    @if ($tenant->hero_image_path)
                        <label class="flex items-center gap-2 rounded-md border border-zinc-200 p-3 text-sm">
                            <input type="checkbox" name="remove_hero_image" value="1" class="rounded border-zinc-300">
                            Remove hero image
                        </label>
                    @endif

                    @if ($tenant->flyer_image_path)
                        <label class="flex items-center gap-2 rounded-md border border-zinc-200 p-3 text-sm">
                            <input type="checkbox" name="remove_flyer_image" value="1" class="rounded border-zinc-300">
                            Remove flyer image
                        </label>
                    @endif

                    @if ($tenant->public_site_slides)
                        <label class="flex items-center gap-2 rounded-md border border-zinc-200 p-3 text-sm">
                            <input type="checkbox" name="clear_slider_images" value="1" class="rounded border-zinc-300">
                            Clear slider images
                        </label>
                    @endif
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-6">
                <h2 class="text-base font-semibold">Customer Contact</h2>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
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

            <div class="flex gap-3">
                <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save Brand</button>
                <a href="{{ $tenant->publicUrl() }}" target="_blank" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Preview public site</a>
            </div>
        </div>

        <aside class="space-y-4">
            <section class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="text-base font-semibold">Current Preview</h2>
                <div class="mt-4 overflow-hidden rounded-lg border border-zinc-200">
                    <div class="p-4 text-white" style="background-color: {{ old('brand_color', $tenant->brand_color ?? '#0f766e') }}">
                        <p class="text-sm font-medium">{{ $tenant->company_name }}</p>
                        <p class="mt-6 text-2xl font-semibold">{{ old('public_site_tagline', $tenant->public_site_tagline) ?: 'Simple, reliable internet access.' }}</p>
                    </div>
                    <div class="bg-zinc-50 p-4 text-sm text-zinc-600">
                        This color accents the public page buttons, logo mark, and feature highlights.
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="text-base font-semibold">Saved Media</h2>
                <div class="mt-4 space-y-4 text-sm">
                    <p class="text-zinc-500">Hero image: <span class="font-medium text-zinc-950">{{ $tenant->hero_image_path ? 'Uploaded' : 'Not set' }}</span></p>
                    <p class="text-zinc-500">Flyer image: <span class="font-medium text-zinc-950">{{ $tenant->flyer_image_path ? 'Uploaded' : 'Not set' }}</span></p>
                    <p class="text-zinc-500">Slider images: <span class="font-medium text-zinc-950">{{ count($tenant->public_site_slides ?? []) }}</span></p>
                </div>
            </section>
        </aside>
    </form>
</x-layouts.admin>
