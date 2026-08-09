<form wire:submit="save" class="relative rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
    <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
        <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-3 text-sm font-medium text-zinc-700 dark:text-zinc-300 shadow-sm">
            Saving platform payment settings...
        </div>
    </div>

    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold">Default Platform Gateway</h2>
                <flux:badge color="{{ $snapshot['source'] === 'database' ? 'blue' : 'zinc' }}" size="sm">
                    {{ $snapshot['source'] === 'database' ? 'Managed in app' : '.env fallback' }}
                </flux:badge>
                <flux:badge color="{{ $snapshot['active_gateway_implemented'] ? 'emerald' : 'amber' }}" size="sm">
                    {{ $snapshot['active_gateway_name'] }}
                </flux:badge>
            </div>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                Used only for tenant subscription payments. Tenant customer collections still use each shop's selected tenant gateway.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:badge color="{{ $snapshot['client_id_configured'] && $snapshot['client_secret_configured'] ? 'emerald' : 'amber' }}" size="sm">
                {{ $snapshot['client_id_configured'] && $snapshot['client_secret_configured'] ? 'Checkout ready' : 'Checkout missing' }}
            </flux:badge>
            <flux:badge color="{{ $snapshot['webhook_secret_configured'] ? 'emerald' : 'amber' }}" size="sm">
                {{ $snapshot['webhook_secret_configured'] ? 'Webhook ready' : 'Webhook missing' }}
            </flux:badge>
        </div>
    </div>

    @if ($savedMessage)
        <div class="mt-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $savedMessage }}
        </div>
    @endif

    <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($gatewayCards as $key => $gateway)
            <button
                type="button"
                wire:click="$set('active_gateway', '{{ $key }}')"
                class="min-w-0 rounded-lg border p-4 text-left transition hover:border-zinc-400 dark:hover:border-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800 {{ $active_gateway === $key ? 'border-zinc-950 dark:border-zinc-100 bg-zinc-50 dark:bg-zinc-800 ring-2 ring-zinc-950/10 dark:ring-zinc-100/10' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900' }}"
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
                <p class="mt-3 text-sm font-semibold text-zinc-950 dark:text-zinc-100">{{ $gateway['name'] }}</p>
                <p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">{{ $gateway['region'] }} · {{ $gateway['currency_label'] }}</p>
                <p class="mt-2 line-clamp-2 text-xs leading-5 text-zinc-600 dark:text-zinc-400">{{ $gateway['summary'] }}</p>
            </button>
        @endforeach
    </div>

    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Active platform gateway</flux:label>
            <flux:select wire:model.live="active_gateway">
                @foreach ($snapshot['gateway_options'] as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:description>Only live adapters can process checkout. Coming-soon gateways can be selected for planning, but checkout stays disabled until their adapter is added.</flux:description>
            <flux:error name="active_gateway" />
        </flux:field>

        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4">
            <div class="flex items-center gap-3">
                @if ($snapshot['active_gateway_logo_url'])
                    <img src="{{ $snapshot['active_gateway_logo_url'] }}" alt="{{ $snapshot['active_gateway_name'] }} logo" class="max-h-8 max-w-28 object-contain">
                @else
                    <span class="rounded-md px-2 py-1 text-xs font-semibold text-white" style="background-color: {{ $activeGateway['brand_color'] }}">{{ $activeGateway['name'] }}</span>
                @endif
                <flux:badge color="{{ $snapshot['active_gateway_implemented'] ? 'green' : 'amber' }}" size="sm">
                    {{ $snapshot['active_gateway_implemented'] ? 'Live adapter' : 'Adapter pending' }}
                </flux:badge>
            </div>
            <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $activeGateway['summary'] }}</p>
            <p class="mt-2 text-xs leading-5 text-zinc-500 dark:text-zinc-400">{{ $activeGateway['webhook_note'] }}</p>
        </div>

        <flux:field>
            <flux:label>{{ $snapshot['active_gateway_name'] }} client ID</flux:label>
            <flux:input
                wire:model.blur="client_id"
                icon="identification"
                placeholder="{{ $snapshot['client_id_configured'] ? 'Leave blank to keep saved client ID' : 'Paste platform gateway client ID' }}"
            />
            <flux:error name="client_id" />
        </flux:field>

        <flux:field>
            <flux:label>{{ $snapshot['active_gateway_name'] }} client secret</flux:label>
            <flux:input
                wire:model.blur="client_secret"
                icon="key"
                placeholder="{{ $snapshot['client_secret_configured'] ? 'Leave blank to keep saved client secret' : 'Paste matching platform client secret' }}"
                viewable
            />
            <flux:error name="client_secret" />
        </flux:field>

        <flux:field>
            <flux:label>{{ $snapshot['active_gateway_name'] }} webhook secret</flux:label>
            <flux:input
                wire:model.blur="webhook_secret_hash"
                icon="shield-check"
                placeholder="{{ $snapshot['webhook_secret_configured'] ? 'Leave blank to keep saved webhook secret' : 'Paste platform gateway webhook secret' }}"
                viewable
            />
            <flux:description>Use this on the platform gateway webhook settings, not tenant/shop accounts.</flux:description>
            <flux:error name="webhook_secret_hash" />
        </flux:field>

        <flux:field>
            <flux:label>Default billing method</flux:label>
            <flux:select wire:model.live="default_payment_method">
                <flux:select.option value="opay">OPay</flux:select.option>
                <flux:select.option value="bank_transfer">Bank transfer</flux:select.option>
                <flux:select.option value="card">Card</flux:select.option>
            </flux:select>
            <flux:description>Used for platform subscription checkout initialization.</flux:description>
            <flux:error name="default_payment_method" />
        </flux:field>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2">
        @if ($snapshot['client_id_configured'] || $snapshot['client_secret_configured'])
            <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:checkbox wire:model.live="clear_client_credentials" label="Clear platform client credentials" />
            </div>
        @endif

        @if ($snapshot['webhook_secret_configured'])
            <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:checkbox wire:model.live="clear_webhook_secret" label="Clear platform webhook secret" />
            </div>
        @endif
    </div>

    <div class="mt-5 flex justify-end">
        <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save platform settings</span>
            <span wire:loading wire:target="save">Saving...</span>
        </flux:button>
    </div>
</form>
