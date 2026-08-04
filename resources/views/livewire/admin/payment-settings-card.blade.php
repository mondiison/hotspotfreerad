<form wire:submit="save" class="relative rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
    <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
        <div class="rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow-sm">
            Saving payment settings...
        </div>
    </div>

    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold">{{ $shop->name }}</h2>
                <flux:badge color="{{ $shop->is_active ? 'green' : 'zinc' }}" size="sm">{{ $shop->is_active ? 'Active shop' : 'Inactive shop' }}</flux:badge>
                <flux:badge color="{{ $shop->paymentGatewayIsImplemented() ? 'emerald' : 'amber' }}" size="sm">{{ $shop->paymentGatewayName() }}</flux:badge>
            </div>
            <p class="mt-1 text-sm text-zinc-500">{{ $shop->tenant->company_name }}{{ $shop->location_city ? ' - '.$shop->location_city : '' }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ number_format($shop->payments_count) }} customer payment {{ \Illuminate\Support\Str::plural('record', $shop->payments_count) }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($readiness as $item)
                <flux:badge :color="$item['ready'] ? 'emerald' : 'amber'" size="sm">
                    {{ $item['ready'] ? $item['ready_badge'] : $item['missing_badge'] }}
                </flux:badge>
            @endforeach
        </div>
    </div>

    <flux:separator class="my-5" />

    @if ($savedMessage)
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <div class="grid gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm md:grid-cols-3">
        @foreach ($readiness as $item)
            <div>
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full {{ $item['ready'] ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                    <p class="font-semibold text-zinc-950">{{ $item['label'] }}</p>
                </div>
                <p class="mt-1 text-xs leading-5 text-zinc-600">{{ $item['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($gatewayCards as $key => $gateway)
            <button
                type="button"
                wire:click="$set('payment_gateway', '{{ $key }}')"
                class="min-w-0 rounded-lg border p-4 text-left transition hover:border-zinc-400 hover:bg-zinc-50 {{ $payment_gateway === $key ? 'border-zinc-950 bg-zinc-50 ring-2 ring-zinc-950/10' : 'border-zinc-200 bg-white' }}"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="flex h-10 w-28 items-center justify-start">
                        @if ($gateway['logo_url'])
                            <img src="{{ $gateway['logo_url'] }}" alt="{{ $gateway['name'] }} logo" class="max-h-8 max-w-28 object-contain">
                        @else
                            <span class="rounded-md px-2 py-1 text-xs font-semibold text-white" style="background-color: {{ $gateway['brand_color'] }}">{{ $gateway['name'] }}</span>
                        @endif
                    </span>
                    <flux:badge color="{{ $gateway['status'] === 'live' ? 'green' : 'amber' }}" size="sm">{{ $gateway['status_label'] }}</flux:badge>
                </div>
                <p class="mt-3 text-sm font-semibold text-zinc-950">{{ $gateway['name'] }}</p>
                <p class="mt-1 text-xs leading-5 text-zinc-500">{{ $gateway['region'] }} · {{ $gateway['currency_label'] }}</p>
                <p class="mt-2 line-clamp-2 text-xs leading-5 text-zinc-600">{{ $gateway['summary'] }}</p>
            </button>
        @endforeach
    </div>

    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Default tenant gateway</flux:label>
            <flux:select wire:model.live="payment_gateway">
                @foreach ($gatewayOptions as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:description>Customer hotspot payments for this shop use this gateway. Only live adapters can process online checkout.</flux:description>
            <flux:error name="payment_gateway" />
        </flux:field>

        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 md:col-span-1">
            <div class="flex items-center gap-3">
                @if ($activeGateway['logo_path'] && \Illuminate\Support\Facades\File::exists(public_path($activeGateway['logo_path'])))
                    <img src="{{ asset($activeGateway['logo_path']) }}" alt="{{ $activeGateway['name'] }} logo" class="max-h-8 max-w-28 object-contain">
                @else
                    <span class="rounded-md px-2 py-1 text-xs font-semibold text-white" style="background-color: {{ $activeGateway['brand_color'] }}">{{ $activeGateway['name'] }}</span>
                @endif
                <flux:badge color="{{ $activeGateway['status'] === 'live' ? 'green' : 'amber' }}" size="sm">{{ $activeGateway['status'] === 'live' ? 'Live adapter' : 'Adapter pending' }}</flux:badge>
            </div>
            <p class="mt-3 text-sm leading-6 text-zinc-600">{{ $activeGateway['summary'] }}</p>
            <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $activeGateway['webhook_note'] }}</p>
        </div>

        @foreach ($credentialFields as $field => $label)
            <flux:field>
                <flux:label>{{ $label }}</flux:label>
                <flux:input
                    wire:model.blur="gateway_settings.{{ $field }}"
                    icon="{{ in_array($field, $secretFieldKeys, true) ? 'key' : 'identification' }}"
                    placeholder="Leave blank to keep saved {{ strtolower($label) }}"
                    :viewable="in_array($field, $secretFieldKeys, true)"
                />
                <flux:error name="gateway_settings.{{ $field }}" />
            </flux:field>
        @endforeach
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

        @if ($shop->hasFlutterwaveHostedCheckoutKey())
            <div class="rounded-md border border-zinc-200 p-3">
                <flux:checkbox wire:model.live="clear_flutterwave_secret_key" label="Clear card checkout secret key" />
            </div>
        @endif
    </div>

    <div class="mt-5 flex flex-wrap gap-3">
        <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save payment settings</span>
            <span wire:loading wire:target="save">Saving...</span>
        </flux:button>
                                <flux:button href="{{ route('admin.shops.edit', $shop) }}" wire:navigate variant="outline" icon="arrow-top-right-on-square">Open shop</flux:button>
    </div>
</form>
