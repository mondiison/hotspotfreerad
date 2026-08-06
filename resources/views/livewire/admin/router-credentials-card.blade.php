<div>
    <div>
        <dt class="text-zinc-500">WireGuard public key</dt>
        @if ($router->wireguard_public_key)
            <dd class="break-all font-mono text-xs">{{ $router->wireguard_public_key }}</dd>
            <dd class="mt-1 text-xs text-zinc-500">Baked into the script below and registered as a Pi peer automatically by the scheduled WireGuard sync &mdash; no manual copying required.</dd>
        @else
            <dd class="text-xs text-amber-600">Not generated yet. This router predates app-managed WireGuard keys.</dd>
        @endif
        <dd class="mt-2">
            <button type="button" wire:click="confirmRegenerateWireguardKey" class="text-xs font-medium text-blue-600 hover:underline">
                {{ $router->wireguard_public_key ? 'Regenerate WireGuard key' : 'Generate WireGuard key' }}
            </button>
        </dd>
    </div>

    <flux:modal wire:model.self="showRegenerateWireguardKeyModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $router->wireguard_public_key ? 'Regenerate' : 'Generate' }} WireGuard key</flux:heading>
                <flux:text class="mt-2">This replaces the WireGuard key MMS Radius manages for this router. You will need to re-run the WireGuard section of the script on the physical router afterward.</flux:text>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showRegenerateWireguardKeyModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="primary" wire:click="regenerateWireguardKey" wire:loading.attr="disabled" wire:target="regenerateWireguardKey">
                    <span wire:loading.remove wire:target="regenerateWireguardKey">{{ $router->wireguard_public_key ? 'Regenerate key' : 'Generate key' }}</span>
                    <span wire:loading wire:target="regenerateWireguardKey">Generating...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <div>
        <dt class="text-zinc-500">RouterOS API (monitoring)</dt>
        @if ($router->api_username)
            <dd class="font-mono text-xs">{{ $router->api_username }}@{{ $router->wireguard_internal_ip }}:{{ $router->api_port }}</dd>
            <dd class="mt-1 text-xs text-zinc-500">Read-only user baked into the script below. Powers live bandwidth, Wi-Fi scan, and topology.</dd>
        @else
            <dd class="text-xs text-amber-600">Not generated yet. Save this router again to generate API credentials.</dd>
        @endif
        <dd class="mt-2 flex flex-wrap gap-3">
            <button
                type="button"
                wire:click="testConnection"
                wire:loading.attr="disabled"
                wire:target="testConnection"
                class="text-xs font-medium text-blue-600 hover:underline disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="testConnection">Test connection</span>
                <span wire:loading wire:target="testConnection">Testing...</span>
            </button>
            <button type="button" wire:click="confirmRegenerateApiCredentials" class="text-xs font-medium text-blue-600 hover:underline">
                {{ $router->api_username ? 'Regenerate credentials' : 'Generate credentials' }}
            </button>
        </dd>
    </div>

    <flux:modal wire:model.self="showRegenerateApiCredentialsModal" class="md:w-lg" :dismissible="false">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $router->api_username ? 'Regenerate' : 'Generate' }} RouterOS API credentials</flux:heading>
                <flux:text class="mt-2">This replaces the RouterOS API credentials MMS Radius manages for this router. You will need to re-run the script on the physical router afterward.</flux:text>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showRegenerateApiCredentialsModal', false)">Cancel</flux:button>
                <flux:button type="button" variant="primary" wire:click="regenerateApiCredentials" wire:loading.attr="disabled" wire:target="regenerateApiCredentials">
                    <span wire:loading.remove wire:target="regenerateApiCredentials">{{ $router->api_username ? 'Regenerate credentials' : 'Generate credentials' }}</span>
                    <span wire:loading wire:target="regenerateApiCredentials">Generating...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
