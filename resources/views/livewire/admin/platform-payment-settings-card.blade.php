<form wire:submit="save" class="relative rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
    <div wire:loading.flex wire:target="save" class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
        <div class="rounded-md border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow-sm">
            Saving platform payment settings...
        </div>
    </div>

    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold">Platform Flutterwave Account</h2>
                <flux:badge color="{{ $snapshot['source'] === 'database' ? 'blue' : 'zinc' }}" size="sm">
                    {{ $snapshot['source'] === 'database' ? 'Managed in app' : '.env fallback' }}
                </flux:badge>
            </div>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-zinc-500">
                Used only for tenant subscription payments. Tenant customer collections still use each shop's own payment setup.
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

    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Platform client ID</flux:label>
            <flux:input
                wire:model.blur="client_id"
                icon="identification"
                placeholder="{{ $snapshot['client_id_configured'] ? 'Leave blank to keep saved client ID' : 'Paste platform Flutterwave v4 client ID' }}"
            />
            <flux:error name="client_id" />
        </flux:field>

        <flux:field>
            <flux:label>Platform client secret</flux:label>
            <flux:input
                wire:model.blur="client_secret"
                icon="key"
                placeholder="{{ $snapshot['client_secret_configured'] ? 'Leave blank to keep saved client secret' : 'Paste matching platform client secret' }}"
                viewable
            />
            <flux:error name="client_secret" />
        </flux:field>

        <flux:field>
            <flux:label>Platform webhook secret hash</flux:label>
            <flux:input
                wire:model.blur="webhook_secret_hash"
                icon="shield-check"
                placeholder="{{ $snapshot['webhook_secret_configured'] ? 'Leave blank to keep saved webhook secret' : 'Paste platform Flutterwave verif-hash' }}"
                viewable
            />
            <flux:description>Use this on the platform Flutterwave webhook settings, not tenant/shop accounts.</flux:description>
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
            <div class="rounded-md border border-zinc-200 p-3">
                <flux:checkbox wire:model.live="clear_client_credentials" label="Clear platform client credentials" />
            </div>
        @endif

        @if ($snapshot['webhook_secret_configured'])
            <div class="rounded-md border border-zinc-200 p-3">
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
