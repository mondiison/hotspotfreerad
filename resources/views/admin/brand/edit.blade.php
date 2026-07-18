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
                        <flux:error name="brand_color" />
                    </div>

                    <flux:field class="md:col-span-2">
                        <flux:label>Hero tagline</flux:label>
                        <flux:input name="public_site_tagline" value="{{ old('public_site_tagline', $tenant->public_site_tagline) }}" icon="sparkles" placeholder="Fast internet access for daily users." />
                        <flux:error name="public_site_tagline" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>About this business</flux:label>
                        <flux:textarea name="public_site_about" rows="5" placeholder="Describe your hotspot locations, support promise, or coverage area.">{{ old('public_site_about', $tenant->public_site_about) }}</flux:textarea>
                        <flux:error name="public_site_about" />
                    </flux:field>
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-6">
                <div>
                    <h2 class="text-base font-semibold">Media</h2>
                    <p class="mt-1 text-sm text-zinc-500">Upload public-facing images. Use compressed JPG, PNG, or WebP files under 4MB each.</p>
                </div>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <flux:field class="md:col-span-2">
                        <flux:label>Logo image</flux:label>
                        <flux:input.file name="logo_image" accept="image/*" />
                        <flux:description>Best size: square PNG or JPG. This appears on the public site, captive portal, checkout, and tenant login.</flux:description>
                        <flux:error name="logo_image" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Hero image</flux:label>
                        <flux:input.file name="hero_image" accept="image/*" />
                        <flux:description>Best size: 1600x1000. This appears in the first screen.</flux:description>
                        <flux:error name="hero_image" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Flyer image</flux:label>
                        <flux:input.file name="flyer_image" accept="image/*" />
                        <flux:description>Use for promos, offers, or branch announcements.</flux:description>
                        <flux:error name="flyer_image" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>Slider images</flux:label>
                        <flux:input.file name="slider_images[]" accept="image/*" multiple />
                        <flux:description>Upload up to 5 images. New uploads replace the existing slider set.</flux:description>
                        <flux:error name="slider_images" />
                        <flux:error name="slider_images.*" />
                    </flux:field>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    @if ($tenant->logo_image_path)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox name="remove_logo_image" value="1" label="Remove logo image" />
                        </div>
                    @endif

                    @if ($tenant->hero_image_path)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox name="remove_hero_image" value="1" label="Remove hero image" />
                        </div>
                    @endif

                    @if ($tenant->flyer_image_path)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox name="remove_flyer_image" value="1" label="Remove flyer image" />
                        </div>
                    @endif

                    @if ($tenant->public_site_slides)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox name="clear_slider_images" value="1" label="Clear slider images" />
                        </div>
                    @endif
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-6">
                <h2 class="text-base font-semibold">Customer Contact</h2>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
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

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary" icon="check">Save Brand</flux:button>
                <flux:button href="{{ $tenant->publicUrl() }}" target="_blank" variant="outline" icon="arrow-top-right-on-square">Preview public site</flux:button>
            </div>
        </div>

        <aside class="space-y-4">
            <section class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="text-base font-semibold">Current Preview</h2>
                <div class="mt-4 overflow-hidden rounded-lg border border-zinc-200">
                    <div class="p-4 text-white" style="background-color: {{ old('brand_color', $tenant->brand_color ?? '#0f766e') }}">
                        <div class="flex items-center gap-3">
                            @if ($tenant->logo_image_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-md bg-white object-cover">
                            @else
                                <span class="grid h-10 w-10 place-items-center rounded-md bg-white/15 text-sm font-semibold">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                            @endif
                            <p class="text-sm font-medium">{{ $tenant->company_name }}</p>
                        </div>
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
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-zinc-500">Logo image</span>
                        <flux:badge :color="$tenant->logo_image_path ? 'emerald' : 'zinc'" size="sm">{{ $tenant->logo_image_path ? 'Uploaded' : 'Not set' }}</flux:badge>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-zinc-500">Hero image</span>
                        <flux:badge :color="$tenant->hero_image_path ? 'emerald' : 'zinc'" size="sm">{{ $tenant->hero_image_path ? 'Uploaded' : 'Not set' }}</flux:badge>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-zinc-500">Flyer image</span>
                        <flux:badge :color="$tenant->flyer_image_path ? 'emerald' : 'zinc'" size="sm">{{ $tenant->flyer_image_path ? 'Uploaded' : 'Not set' }}</flux:badge>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-zinc-500">Slider images</span>
                        <flux:badge color="zinc" size="sm">{{ count($tenant->public_site_slides ?? []) }}</flux:badge>
                    </div>
                </div>
            </section>
        </aside>
    </form>
</x-layouts.admin>
