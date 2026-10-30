<div>
    <div class="mb-5 flex items-center gap-2 text-sm">
        @foreach (['Router & Starlink', 'Ports & networks', 'Plans & fair use', 'Generate'] as $index => $label)
            @php($stepNumber = $index + 1)
            <button type="button" wire:click="goToStep({{ $stepNumber }})" class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold {{ $step >= $stepNumber ? 'bg-zinc-950 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400' }}">{{ $stepNumber }}</span>
                <span class="hidden text-xs font-medium {{ $step === $stepNumber ? 'text-zinc-950 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400' }} md:inline">{{ $label }}</span>
            </button>
            @if (! $loop->last)
                <span class="h-px w-6 bg-zinc-200 dark:bg-zinc-700"></span>
            @endif
        @endforeach
    </div>

    @if ($step === 1)
        <div class="space-y-5 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
            <flux:field>
                <flux:label>Router identity</flux:label>
                <flux:input wire:model.blur="router_identity" placeholder="e.g. Village Starlink" />
                <flux:error name="router_identity" />
            </flux:field>

            <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 p-4 text-sm text-zinc-600 dark:text-zinc-400">
                RouterOS 6.40 or newer, ether1 connected to Starlink (DHCP). Tested target: RB750Gr3 (hEX).
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <flux:field>
                    <flux:label>Starlink download speed (Mbps)</flux:label>
                    <flux:input type="number" step="0.1" min="0.1" wire:model.blur="wan_download_mbps" />
                    <flux:description>Use a typical real speed test result, not the advertised maximum.</flux:description>
                    <flux:error name="wan_download_mbps" />
                </flux:field>
                <flux:field>
                    <flux:label>Starlink upload speed (Mbps)</flux:label>
                    <flux:input type="number" step="0.1" min="0.1" wire:model.blur="wan_upload_mbps" />
                    <flux:description>Clients share 90% of these speeds, so Starlink itself never gets congested.</flux:description>
                    <flux:error name="wan_upload_mbps" />
                </flux:field>
            </div>

            <div class="flex justify-end">
                <flux:button type="button" variant="primary" wire:click="nextStep">Next: Ports &amp; networks</flux:button>
            </div>
        </div>
    @endif

    @if ($step === 2)
        <div class="space-y-5 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
            <div>
                <flux:label>Port roles</flux:label>
                <flux:description>ether1 is the Starlink uplink. Choose what each other port does.</flux:description>
                <flux:error name="port_roles" />
                <div class="mt-3 grid gap-3 md:grid-cols-4">
                    @foreach (\App\Services\StandalonePppoeScriptGenerator::PORTS as $port)
                        <flux:field wire:key="port-{{ $port }}">
                            <flux:label size="sm">{{ $port }}</flux:label>
                            <flux:select wire:model.live="port_roles.{{ $port }}">
                                @foreach (\App\Services\StandalonePppoeScriptGenerator::PORT_ROLES as $role => $roleLabel)
                                    <flux:select.option value="{{ $role }}">{{ $roleLabel }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                    @endforeach
                </div>
            </div>

            @if ($this->usesRole('pppoe'))
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>PPPoE client network</flux:label>
                        <flux:input wire:model.blur="pppoe_network" placeholder="10.20.0.1/24" />
                        <flux:description>Router address and /24 for PPPoE clients (up to about 245 clients).</flux:description>
                        <flux:error name="pppoe_network" />
                    </flux:field>
                    <flux:field>
                        <flux:label>PPPoE service name</flux:label>
                        <flux:input wire:model.blur="pppoe_service_name" placeholder="internet" />
                        <flux:error name="pppoe_service_name" />
                    </flux:field>
                </div>
            @endif

            @if ($this->usesRole('lan'))
                <flux:field>
                    <flux:label>Plain LAN network</flux:label>
                    <flux:input wire:model.blur="lan_network" placeholder="192.168.88.1/24" />
                    <flux:error name="lan_network" />
                </flux:field>
            @endif

            @if ($this->usesRole('mgmt'))
                <flux:field>
                    <flux:label>Management network</flux:label>
                    <flux:input wire:model.blur="mgmt_network" placeholder="10.10.20.1/24" />
                    <flux:description>Winbox, SSH and the web interface only answer on this network.</flux:description>
                    <flux:error name="mgmt_network" />
                </flux:field>
            @else
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                    No management port: the router can be administered from client networks. Set one port to Management to keep clients out of Winbox.
                </div>
            @endif

            <div class="flex justify-between">
                <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                <flux:button type="button" variant="primary" wire:click="nextStep">Next: Plans &amp; fair use</flux:button>
            </div>
        </div>
    @endif

    @if ($step === 3)
        <div class="space-y-5 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
            @if ($this->usesRole('pppoe'))
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <flux:label>PPPoE plans</flux:label>
                        <flux:button type="button" size="sm" variant="outline" icon="plus" wire:click="addPlan">Add plan</flux:button>
                    </div>
                    <flux:description>Each client is capped at their plan. When Starlink is full, active clients share it evenly. The first plan is the default.</flux:description>
                    <flux:error name="plans" />

                    <div class="mt-3 space-y-3">
                        @foreach ($plans as $index => $plan)
                            <div class="grid items-end gap-3 rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 md:grid-cols-4" wire:key="plan-{{ $index }}">
                                <flux:field class="md:col-span-2">
                                    <flux:label size="sm">Plan name</flux:label>
                                    <flux:input wire:model.blur="plans.{{ $index }}.name" placeholder="Basic 5M" />
                                    <flux:error name="plans.{{ $index }}.name" />
                                </flux:field>
                                <flux:field>
                                    <flux:label size="sm">Download (Mbps)</flux:label>
                                    <flux:input type="number" step="0.1" min="0.1" wire:model.blur="plans.{{ $index }}.download_mbps" />
                                    <flux:error name="plans.{{ $index }}.download_mbps" />
                                </flux:field>
                                <div class="flex items-end gap-2">
                                    <flux:field class="flex-1">
                                        <flux:label size="sm">Upload (Mbps)</flux:label>
                                        <flux:input type="number" step="0.1" min="0.1" wire:model.blur="plans.{{ $index }}.upload_mbps" />
                                        <flux:error name="plans.{{ $index }}.upload_mbps" />
                                    </flux:field>
                                    @if (count($plans) > 1)
                                        <flux:button type="button" size="sm" variant="danger" icon="trash" wire:click="removePlan({{ $index }})" />
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->usesRole('lan'))
                <div>
                    <flux:label>Plain LAN devices</flux:label>
                    <flux:description>Each device (or client router) on a LAN port is capped at this speed and gets an equal share when Starlink is full.</flux:description>
                    <div class="mt-3 grid gap-5 md:grid-cols-2">
                        <flux:field>
                            <flux:label size="sm">Per-device download (Mbps)</flux:label>
                            <flux:input type="number" step="0.1" min="0.1" wire:model.blur="lan_device_download_mbps" />
                            <flux:error name="lan_device_download_mbps" />
                        </flux:field>
                        <flux:field>
                            <flux:label size="sm">Per-device upload (Mbps)</flux:label>
                            <flux:input type="number" step="0.1" min="0.1" wire:model.blur="lan_device_upload_mbps" />
                            <flux:error name="lan_device_upload_mbps" />
                        </flux:field>
                    </div>
                </div>
            @endif

            <div class="flex justify-between">
                <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                <flux:button type="button" variant="primary" wire:click="generate">Generate script</flux:button>
            </div>
        </div>
    @endif

    @if ($step === 4 && $generatedScript)
        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">Script ready</flux:heading>
                <div class="flex gap-2">
                    <flux:button type="button" variant="outline" wire:click="previousStep">Back to change settings</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="startOver">Start over</flux:button>
                </div>
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <flux:label>RouterOS script</flux:label>
                    <div x-data="{}">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="outline"
                            x-on:click="const blob = new Blob([@js($generatedScript)], {type: 'text/plain'}); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'pppoe-fair-bandwidth.rsc'; a.click(); URL.revokeObjectURL(url);"
                        >Download .rsc</flux:button>
                    </div>
                </div>
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                    <x-script-block>{{ $generatedScript }}</x-script-block>
                </div>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Paste into a RouterOS terminal on a router reset with no default configuration (connect with MAC Winbox). Then add each client in PPP &gt; Secrets with one of the plans.</p>
            </div>
        </div>
    @endif
</div>
