<form wire:submit="save" class="relative rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
    <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
        <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-3 text-sm font-medium text-zinc-700 dark:text-zinc-300 shadow-sm">
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
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $shop->tenant->company_name }}{{ $shop->location_city ? ' - '.$shop->location_city : '' }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ number_format($shop->payments_count) }} customer payment {{ \Illuminate\Support\Str::plural('record', $shop->payments_count) }}</p>
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

    <div class="grid gap-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 text-sm md:grid-cols-3">
        @foreach ($readiness as $item)
            <div>
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full {{ $item['ready'] ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                    <p class="font-semibold text-zinc-950 dark:text-zinc-100">{{ $item['label'] }}</p>
                </div>
                <p class="mt-1 text-xs leading-5 text-zinc-600 dark:text-zinc-400">{{ $item['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($gatewayCards as $key => $gateway)
            <div class="min-w-0 rounded-lg border p-3 transition {{ $payment_gateway === $key ? 'border-zinc-950 dark:border-zinc-100 bg-zinc-50 dark:bg-zinc-800 ring-2 ring-zinc-950/10 dark:ring-zinc-100/10' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-zinc-400 dark:hover:border-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
                <div class="flex items-center justify-between gap-2">
                    <button type="button" wire:click="$set('payment_gateway', '{{ $key }}')" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                        <span class="flex h-8 w-20 shrink-0 items-center justify-start">
                            @if ($gateway['logo_url'])
                                <img src="{{ $gateway['logo_url'] }}" alt="{{ $gateway['name'] }} logo" class="max-h-7 max-w-20 object-contain">
                            @else
                                <span class="rounded-md px-2 py-1 text-xs font-semibold text-white" style="background-color: {{ $gateway['brand_color'] }}">{{ $gateway['name'] }}</span>
                            @endif
                        </span>
                        <span class="truncate text-sm font-semibold text-zinc-950 dark:text-zinc-100">{{ $gateway['name'] }}</span>
                    </button>

                    <flux:dropdown class="shrink-0">
                        <flux:button type="button" icon="information-circle" variant="ghost" size="sm" aria-label="About {{ $gateway['name'] }}" />
                        <flux:popover class="max-w-xs space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                            <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $gateway['name'] }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $gateway['region'] }} · {{ $gateway['currency_label'] }}</p>
                            <p>{{ $gateway['summary'] }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $gateway['webhook_note'] }}</p>
                        </flux:popover>
                    </flux:dropdown>
                </div>

                @if ($payment_gateway === $key)
                    <flux:badge color="{{ $gateway['status'] === 'live' ? 'green' : 'amber' }}" size="sm" class="mt-2">{{ $gateway['status_label'] }}</flux:badge>
                @endif
            </div>
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

        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 md:col-span-1">
            <div class="flex items-center gap-3">
                @if ($activeGateway['logo_path'] && \Illuminate\Support\Facades\File::exists(public_path($activeGateway['logo_path'])))
                    <img src="{{ asset($activeGateway['logo_path']) }}" alt="{{ $activeGateway['name'] }} logo" class="max-h-8 max-w-28 object-contain">
                @else
                    <span class="rounded-md px-2 py-1 text-xs font-semibold text-white" style="background-color: {{ $activeGateway['brand_color'] }}">{{ $activeGateway['name'] }}</span>
                @endif
                <flux:badge color="{{ $activeGateway['status'] === 'live' ? 'green' : 'amber' }}" size="sm">{{ $activeGateway['status'] === 'live' ? 'Live adapter' : 'Adapter pending' }}</flux:badge>
            </div>
            <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $activeGateway['summary'] }}</p>
            <p class="mt-2 text-xs leading-5 text-zinc-500 dark:text-zinc-400">{{ $activeGateway['webhook_note'] }}</p>
        </div>

        @foreach ($credentialFields as $field => $label)
            <flux:field>
                <flux:label>{{ $label }}</flux:label>
                @if (array_key_exists($field, $selectFields))
                    <flux:select wire:model.live="gateway_settings.{{ $field }}">
                        @foreach ($selectFields[$field] as $value => $optionLabel)
                            <flux:select.option value="{{ $value }}">{{ $optionLabel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($field === 'environment')
                        @if ($showsDetectedEnvironment)
                            <flux:description>{{ $activeGateway['name'] }} uses one API host for both modes, so this is saved for your own reference only -- checkout always follows whichever mode the secret key below is actually in.</flux:description>
                            @if ($detectedEnvironment !== null && ($gateway_settings['environment'] ?? null) !== $detectedEnvironment)
                                <p class="mt-1 text-xs font-medium text-amber-600">Heads up: this doesn't match the saved key ({{ $detectedEnvironment === 'live' ? 'Live' : 'Test' }} key detected below).</p>
                            @endif
                        @else
                            <flux:description>Sandbox/Test uses this gateway's test API and never moves real money. Switch to Live only once you have live credentials from {{ $activeGateway['name'] }}.</flux:description>
                        @endif
                    @endif
                @else
                    <flux:input
                        wire:model.blur="gateway_settings.{{ $field }}"
                        icon="{{ in_array($field, $secretFieldKeys, true) ? 'key' : 'identification' }}"
                        placeholder="Leave blank to keep saved {{ strtolower($label) }}"
                        :viewable="in_array($field, $secretFieldKeys, true)"
                    />
                    @if ($field === 'secret_key' && $showsDetectedEnvironment)
                        <div class="mt-1">
                            @if ($detectedEnvironment === 'live')
                                <flux:badge color="green" size="sm">Detected: Live key</flux:badge>
                            @elseif ($detectedEnvironment === 'test')
                                <flux:badge color="amber" size="sm">Detected: Test key</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Environment not detected</flux:badge>
                            @endif
                        </div>
                        <flux:description>{{ $activeGateway['name'] }} uses one API host for both modes -- the saved secret key's prefix is what actually determines live vs test, so there's no separate environment setting to get out of sync.</flux:description>
                    @endif
                @endif
                <flux:error name="gateway_settings.{{ $field }}" />
            </flux:field>
        @endforeach
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2">
        @if ($shop->hasCompleteFlutterwaveCredentials())
            <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:checkbox wire:model.live="clear_flutterwave_credentials" label="Clear client credentials" />
            </div>
        @endif

        @if ($shop->hasFlutterwaveWebhookSecret())
            <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:checkbox wire:model.live="clear_flutterwave_webhook_secret" label="Clear webhook secret" />
            </div>
        @endif

        @if ($shop->hasFlutterwaveHostedCheckoutKey())
            <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-3">
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
