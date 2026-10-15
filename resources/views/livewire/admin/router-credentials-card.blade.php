<div>
    <div>
        <dt class="text-zinc-500 dark:text-zinc-400">WireGuard public key</dt>
        @if ($router->wireguard_public_key)
            <dd class="break-all font-mono text-xs">{{ $router->wireguard_public_key }}</dd>
            <dd class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Baked into the script below and registered as a Pi peer automatically by the scheduled WireGuard sync &mdash; no manual copying required.</dd>
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
        <dt class="text-zinc-500 dark:text-zinc-400">RouterOS API (monitoring)</dt>
        @if ($router->api_username)
            <dd class="font-mono text-xs">{{ $router->api_username }}@{{ $router->wireguard_internal_ip }}:{{ $router->api_port }}</dd>
            <dd class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Read-only user baked into the script below. Powers live bandwidth, Wi-Fi scan, and topology.</dd>
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

    @if (in_array($router->tunnel_mode, ['wireguard_zerotier', 'zerotier'], true))
        <div class="mt-4">
            <dt class="text-zinc-500 dark:text-zinc-400">ZeroTier node ID</dt>
            <dd class="mt-1 flex flex-wrap items-center gap-2">
                <input
                    type="text"
                    wire:model="zerotierNodeId"
                    placeholder="e.g. a943bf5013"
                    maxlength="16"
                    class="w-36 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-2 py-1 font-mono text-xs"
                />
                <button
                    type="button"
                    wire:click="saveZeroTierNodeId"
                    wire:loading.attr="disabled"
                    wire:target="saveZeroTierNodeId"
                    class="text-xs font-medium text-blue-600 hover:underline disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="saveZeroTierNodeId">Save</span>
                    <span wire:loading wire:target="saveZeroTierNodeId">Saving...</span>
                </button>
            </dd>
            @error('zerotier_node_id')
                <dd class="mt-1 text-xs text-red-600">{{ $message }}</dd>
            @enderror
            <dd class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                IP: {{ $router->zerotier_ip ?: 'no IP saved' }} &mdash;
                @if ($router->zerotier_authorized_at)
                    authorized {{ $router->zerotier_authorized_at->diffForHumans() }}.
                @else
                    not authorized yet.
                @endif
            </dd>
            @if ($router->zerotier_node_id)
                <dd class="mt-2">
                    <button
                        type="button"
                        wire:click="authorizeZeroTier"
                        wire:loading.attr="disabled"
                        wire:target="authorizeZeroTier"
                        class="text-xs font-medium text-blue-600 hover:underline disabled:cursor-wait disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="authorizeZeroTier">Authorize &amp; connect now</span>
                        <span wire:loading wire:target="authorizeZeroTier">Authorizing...</span>
                    </button>
                </dd>
            @else
                <dd class="mt-1 text-xs text-amber-600">No node ID saved yet &mdash; enable ZeroTier on the physical router, copy the ID from "/zerotier print detail", paste it above, and click Save.</dd>
            @endif
            <dd class="mt-3">
                <details class="group rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                    <summary class="cursor-pointer select-none px-3 py-2 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100">
                        Troubleshooting: "Authorize &amp; connect now" fails / controller unreachable on the Pi
                    </summary>
                    <div class="border-t border-zinc-200 dark:border-zinc-700 px-3 py-3 text-xs leading-5 text-zinc-600 dark:text-zinc-400">
                        <p>
                            A past outage traced to a Debian/Raspberry Pi OS update resetting <code>authtoken.secret</code>'s permissions back to root-only, which the app (running as <code>www-data</code>) can't read &mdash; this breaks every ZeroTier controller-API call this button makes, including this one, without taking the tunnel itself down (routers already joined keep working; only authorizing new ones fails). Run this once on the Pi to install a permanent fix that survives future updates &mdash; it reapplies the correct permissions after every ZeroTier service restart, and is written to always exit cleanly even if the file is briefly missing, so it can never itself take the tunnel daemon down the way the original fix (removed) once did:
                        </p>
                        <x-script-block label="Copy">sudo tee /usr/local/sbin/hotspotfreerad-fix-zerotier-authtoken > /dev/null &lt;&lt;'EOF'
#!/bin/sh
# Reapplies group/permissions on authtoken.secret so www-data can read it
# for the app's ZeroTier controller-API calls. Always exits 0 -- this is a
# convenience fix, never something that should be able to take the core
# tunnel daemon itself down.
FILE=/var/lib/zerotier-one/authtoken.secret
if [ -f "$FILE" ]; then
    chgrp www-data "$FILE"
    chmod 640 "$FILE"
fi
exit 0
EOF
sudo chmod 755 /usr/local/sbin/hotspotfreerad-fix-zerotier-authtoken

sudo mkdir -p /etc/systemd/system/zerotier-one.service.d
sudo tee /etc/systemd/system/zerotier-one.service.d/override.conf > /dev/null &lt;&lt;'EOF'
[Service]
ExecStartPost=/usr/local/sbin/hotspotfreerad-fix-zerotier-authtoken
EOF
sudo systemctl daemon-reload
sudo systemctl restart zerotier-one</x-script-block>
                        <p class="mt-3">Quick one-off check instead (doesn't survive the next update that regenerates the file, use the script above for a lasting fix):</p>
                        <x-script-block label="Copy">sudo chgrp www-data /var/lib/zerotier-one/authtoken.secret
sudo chmod 640 /var/lib/zerotier-one/authtoken.secret</x-script-block>
                        <p class="mt-3">Confirm it worked: <code>sudo -u www-data cat /var/lib/zerotier-one/authtoken.secret</code> should print the token, not a permission error. Full write-up: <code>docs/zerotier-fallback-setup.md</code>, "If something goes wrong."</p>
                    </div>
                </details>
            </dd>
        </div>
    @endif
</div>
