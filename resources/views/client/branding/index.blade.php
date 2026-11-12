<x-layouts.client title="Branding">
    <h1 class="mb-1 text-xl font-semibold">Branding</h1>
    <p class="mb-5 text-sm text-zinc-500">Customize what customers see on your hotspot's payment page.</p>

    <div class="space-y-6">
        @forelse ($shops as $shop)
            <section class="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 class="font-medium">{{ $shop->name }}</h2>

                <form method="POST" action="{{ route('client.branding.update', $shop) }}" enctype="multipart/form-data" class="mt-4 space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid gap-5 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Banner image</flux:label>
                            @if ($shop->portal_banner_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($shop->portal_banner_path) }}" alt="Current banner" class="mb-2 h-24 rounded-md border border-zinc-200 object-cover">
                            @endif
                            <flux:input type="file" name="portal_banner" accept="image/*" />
                            @if ($shop->portal_banner_path)
                                <flux:checkbox name="remove_portal_banner" value="1" label="Remove current banner" />
                            @endif
                            <flux:error name="portal_banner" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Support phone number</flux:label>
                            <flux:input name="contact_phone" value="{{ old('contact_phone', $shop->contact_phone) }}" placeholder="Shown to customers who need help" />
                            <flux:error name="contact_phone" />
                        </flux:field>

                        <flux:field class="sm:col-span-2">
                            <flux:label>Welcome message</flux:label>
                            <flux:textarea name="portal_welcome_message" rows="3" placeholder="Shown above your packages on the payment page">{{ old('portal_welcome_message', $shop->portal_welcome_message) }}</flux:textarea>
                            <flux:error name="portal_welcome_message" />
                        </flux:field>
                    </div>

                    <flux:button type="submit" variant="primary">Save</flux:button>
                </form>
            </section>
        @empty
            <p class="text-sm text-zinc-500">No shops assigned yet.</p>
        @endforelse
    </div>
</x-layouts.client>
