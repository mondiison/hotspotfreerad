<div class="mb-4">
    <flux:button type="button" wire:click="openEditModal" variant="outline" size="sm" icon="pencil-square">
        Edit {{ $this->label() }} settings
    </flux:button>

    <flux:modal wire:model.self="showEditModal" class="md:w-xl" :dismissible="true">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $this->label() }} settings</flux:heading>
                <flux:text class="mt-2">
                    Saves to this router's settings. These fields are read by the Fresh Infrastructure Script (not this tab's own script) &mdash; see that tab for the updated script, and push it via API or paste it when ready. Nothing here is pushed live automatically.
                </flux:text>
            </div>

            @if ($this->supportsSsid() && ! $builtinWifiEnabled)
                <div class="rounded-md border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                    This router doesn't use its own built-in Wi-Fi radio, so SSID has no effect here &mdash; the SSID is configured directly on the external access point instead (see the AP / SSID Guide tab). VLAN, addressing, and ports below still apply either way.
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($this->supportsSsid())
                    <flux:field>
                        <flux:label>SSID</flux:label>
                        <flux:input wire:model="ssid" maxlength="32" :disabled="! $builtinWifiEnabled" />
                        <flux:error name="ssid" />
                    </flux:field>
                @endif

                @if ($this->supportsWifiPassword())
                    <flux:field>
                        <flux:label>Wi-Fi password</flux:label>
                        <flux:input type="password" wire:model="wifiPassword" placeholder="Leave blank to keep the saved password" />
                        <flux:error name="wifiPassword" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>VLAN</flux:label>
                    <flux:input type="number" wire:model="vlan" min="1" max="4094" />
                    <flux:error name="vlan" />
                </flux:field>

                @if ($this->supportsExtraPorts())
                    <flux:field>
                        <flux:label>Extra untagged ports</flux:label>
                        <flux:input wire:model="extraPorts" placeholder="{{ $portsAdvancedMode ? 'e.g. ether5,ether6' : 'e.g. 5,6' }}" />
                        <flux:description>{{ $portsAdvancedMode ? 'Advanced mode: raw interface names.' : 'Port numbers, comma-separated.' }}</flux:description>
                        <flux:error name="extraPorts" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Gateway</flux:label>
                    <flux:input wire:model="gateway" placeholder="e.g. 10.5.50.1/23" />
                    <flux:error name="gateway" />
                </flux:field>

                @if ($this->supportsAddressPool())
                    <flux:field>
                        <flux:label>Network (CIDR)</flux:label>
                        <flux:input wire:model="networkCidr" placeholder="e.g. 10.5.50.0/23" />
                        <flux:error name="networkCidr" />
                    </flux:field>

                    <flux:field class="sm:col-span-2">
                        <flux:label>DHCP pool</flux:label>
                        <flux:input wire:model="pool" placeholder="e.g. 10.5.50.10-10.5.51.250" />
                        <flux:error name="pool" />
                    </flux:field>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showEditModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save settings</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
