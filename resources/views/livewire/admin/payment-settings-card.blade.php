<form wire:submit="save" class="relative rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
    <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
        <div class="rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow-sm">
            Saving payment settings...
        </div>
    </div>

    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
        <div>
            <h2 class="text-base font-semibold">{{ $shop->name }}</h2>
            <p class="mt-1 text-sm text-zinc-500">{{ $shop->tenant->company_name }}{{ $shop->location_city ? ' - '.$shop->location_city : '' }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ number_format($shop->payments_count) }} customer payment {{ \Illuminate\Support\Str::plural('record', $shop->payments_count) }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:badge :color="$shop->hasCompleteFlutterwaveCredentials() ? 'emerald' : 'amber'" size="sm">
                {{ $shop->hasCompleteFlutterwaveCredentials() ? 'Payments configured' : 'Payments not configured' }}
            </flux:badge>
            <flux:badge :color="$shop->hasFlutterwaveWebhookSecret() ? 'emerald' : 'zinc'" size="sm">
                {{ $shop->hasFlutterwaveWebhookSecret() ? 'Webhook ready' : 'Webhook missing' }}
            </flux:badge>
        </div>
    </div>

    <flux:separator class="my-5" />

    @if ($savedMessage)
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <div class="mt-5 grid gap-4">
        <flux:field>
            <flux:label>Flutterwave client ID</flux:label>
            <flux:input
                wire:model.blur="flutterwave_client_id"
                icon="identification"
                placeholder="{{ $shop->hasCompleteFlutterwaveCredentials() ? 'Leave blank to keep saved client ID' : 'Paste tenant Flutterwave v4 client ID' }}"
            />
            <flux:error name="flutterwave_client_id" />
        </flux:field>

        <flux:field>
            <flux:label>Flutterwave client secret</flux:label>
            <flux:input
                wire:model.blur="flutterwave_client_secret"
                icon="key"
                placeholder="{{ $shop->hasCompleteFlutterwaveCredentials() ? 'Leave blank to keep saved client secret' : 'Paste tenant Flutterwave v4 client secret' }}"
                viewable
            />
            <flux:description>Client ID and secret must be saved together before online customer payment is enabled.</flux:description>
            <flux:error name="flutterwave_client_secret" />
        </flux:field>

        <flux:field>
            <flux:label>Webhook secret hash</flux:label>
            <flux:input
                wire:model.blur="flutterwave_webhook_secret"
                icon="shield-check"
                placeholder="{{ $shop->hasFlutterwaveWebhookSecret() ? 'Leave blank to keep saved webhook secret' : 'Paste Flutterwave webhook verif-hash' }}"
                viewable
            />
            <flux:description>Needed for automatic webhook confirmation. Payment callbacks can still verify successful payments after customer redirect.</flux:description>
            <flux:error name="flutterwave_webhook_secret" />
        </flux:field>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2">
        @if ($shop->hasCompleteFlutterwaveCredentials())
            <div class="rounded-md border border-zinc-200 p-3">
                <flux:checkbox wire:model.live="clear_flutterwave_credentials" label="Clear client credentials" />
            </div>
        @endif

        @if ($shop->hasFlutterwaveWebhookSecret())
            <div class="rounded-md border border-zinc-200 p-3">
                <flux:checkbox wire:model.live="clear_flutterwave_webhook_secret" label="Clear webhook secret" />
            </div>
        @endif
    </div>

    <div class="mt-5 flex flex-wrap gap-3">
        <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save payment settings</span>
            <span wire:loading wire:target="save">Saving...</span>
        </flux:button>
        <flux:button href="{{ route('admin.shops.edit', $shop) }}" variant="outline" icon="arrow-top-right-on-square">Open shop</flux:button>
    </div>
</form>
