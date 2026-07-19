<div>
    @if ($savedMessage)
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <form wire:submit.prevent="save" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-6">
            <section class="rounded-lg border border-zinc-200 bg-white p-6">
                <div>
                    <h2 class="text-base font-semibold">Public Page Copy</h2>
                    <p class="mt-1 text-sm text-zinc-500">These values control the tenant slug page: {{ $tenant->publicUrl() }}</p>
                </div>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <flux:color-picker
                            wire:model.live="brand_color"
                            label="Brand color"
                            format="hex"
                            copyable
                            :swatches="['#0f766e', '#2563eb', '#7c3aed', '#dc2626', '#f59e0b', '#16a34a', '#0891b2', '#111827']"
                        />
                        <flux:error name="brand_color" />
                    </div>

                    <flux:field class="md:col-span-2">
                        <flux:label>Hero tagline</flux:label>
                        <flux:input wire:model.blur="public_site_tagline" icon="sparkles" placeholder="Fast internet access for daily users." />
                        <flux:error name="public_site_tagline" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>About this business</flux:label>
                        <flux:textarea wire:model.blur="public_site_about" rows="5" placeholder="Describe your hotspot locations, support promise, or coverage area." />
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
                        <flux:input type="file" wire:model="logo_image" accept="image/*" />
                        <flux:description>Best size: square PNG or JPG. This appears on the public site, captive portal, checkout, and tenant login.</flux:description>
                        <flux:error name="logo_image" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Hero image</flux:label>
                        <flux:input type="file" wire:model="hero_image" accept="image/*" />
                        <flux:description>Best size: 1600x1000. This appears in the first screen.</flux:description>
                        <flux:error name="hero_image" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Flyer image</flux:label>
                        <flux:input type="file" wire:model="flyer_image" accept="image/*" />
                        <flux:description>Use for promos, offers, or branch announcements.</flux:description>
                        <flux:error name="flyer_image" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>Slider images</flux:label>
                        <flux:input type="file" wire:model="slider_images" accept="image/*" multiple />
                        <flux:description>Upload up to 5 images. New uploads replace the existing slider set.</flux:description>
                        <flux:error name="slider_images" />
                        <flux:error name="slider_images.*" />
                    </flux:field>
                </div>

                <div wire:loading wire:target="logo_image,hero_image,flyer_image,slider_images" class="mt-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                    Uploading media...
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    @if ($tenant->logo_image_path)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox wire:model.live="remove_logo_image" label="Remove logo image" />
                        </div>
                    @endif

                    @if ($tenant->hero_image_path)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox wire:model.live="remove_hero_image" label="Remove hero image" />
                        </div>
                    @endif

                    @if ($tenant->flyer_image_path)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox wire:model.live="remove_flyer_image" label="Remove flyer image" />
                        </div>
                    @endif

                    @if ($tenant->public_site_slides)
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox wire:model.live="clear_slider_images" label="Clear slider images" />
                        </div>
                    @endif
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-6">
                <h2 class="text-base font-semibold">Customer Contact</h2>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Contact phone</flux:label>
                        <flux:input wire:model.blur="contact_phone" icon="phone" placeholder="+234 800 000 0000" />
                        <flux:error name="contact_phone" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Contact email</flux:label>
                        <flux:input type="email" wire:model.blur="contact_email" icon="envelope" placeholder="support@example.com" />
                        <flux:error name="contact_email" />
                    </flux:field>

                    <flux:field class="md:col-span-2">
                        <flux:label>Contact address</flux:label>
                        <flux:textarea wire:model.blur="contact_address" rows="3" placeholder="Shop address or coverage area customers should recognize." />
                        <flux:error name="contact_address" />
                    </flux:field>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save,logo_image,hero_image,flyer_image,slider_images">
                    <span wire:loading.remove wire:target="save">Save Brand</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
                <flux:button href="{{ $tenant->publicUrl() }}" target="_blank" variant="outline" icon="arrow-top-right-on-square">Preview public site</flux:button>
            </div>
        </div>

        <aside class="space-y-4">
            <section class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="text-base font-semibold">Current Preview</h2>
                <div class="mt-4 overflow-hidden rounded-lg border border-zinc-200">
                    <div class="p-4 text-white" style="background-color: {{ $brand_color }}">
                        <div class="flex items-center gap-3">
                            @if ($tenant->logo_image_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-md bg-white object-cover">
                            @else
                                <span class="grid h-10 w-10 place-items-center rounded-md bg-white/15 text-sm font-semibold">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                            @endif
                            <p class="text-sm font-medium">{{ $tenant->company_name }}</p>
                        </div>
                        <p class="mt-6 text-2xl font-semibold">{{ $public_site_tagline ?: 'Simple, reliable internet access.' }}</p>
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

            <section class="rounded-lg border border-zinc-200 bg-white p-5 text-sm text-zinc-600">
                <h2 class="text-base font-semibold text-zinc-950">Branding Guide</h2>
                <p class="mt-3">Use a clear logo, one strong brand color, and customer-ready copy that matches the Wi-Fi location.</p>
                <p class="mt-2">Hero and flyer images should show the real venue, product, offer, or support promise customers will recognize.</p>
            </section>
        </aside>
    </form>
</div>
