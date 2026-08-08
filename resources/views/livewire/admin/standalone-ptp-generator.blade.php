<div>
    <div class="mb-5 flex items-center gap-2 text-sm">
        @foreach (['Link & radios', 'Roles & mode', 'RF & security', 'Distance & IPs', 'Review & generate'] as $index => $label)
            @php($stepNumber = $index + 1)
            <div class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold {{ $step >= $stepNumber ? 'bg-zinc-950 text-white' : 'bg-zinc-100 text-zinc-500' }}">{{ $stepNumber }}</span>
                <span class="hidden text-xs font-medium {{ $step === $stepNumber ? 'text-zinc-950' : 'text-zinc-500' }} md:inline">{{ $label }}</span>
            </div>
            @if (! $loop->last)
                <span class="h-px w-6 bg-zinc-200"></span>
            @endif
        @endforeach
    </div>

    @if ($step === 1)
        <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <flux:field>
                <flux:label>Link name</flux:label>
                <flux:input wire:model.blur="link_name" placeholder="e.g. Tower A to Tower B" />
                <flux:description>Used in the SSID default and script comments -- not pushed to either router's identity.</flux:description>
                <flux:error name="link_name" />
            </flux:field>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="space-y-4 rounded-lg border border-zinc-100 p-4">
                    <flux:heading size="sm">Radio A</flux:heading>
                    <flux:field>
                        <flux:label>Identity</flux:label>
                        <flux:input wire:model.blur="radio_a.identity" placeholder="e.g. Tower A" />
                        <flux:error name="radio_a.identity" />
                    </flux:field>
                    <flux:field>
                        <flux:label>RouterOS version</flux:label>
                        <flux:select wire:model.live="radio_a.ros_version">
                            <flux:select.option value="7">RouterOS 7</flux:select.option>
                            <flux:select.option value="6">RouterOS 6</flux:select.option>
                        </flux:select>
                        <flux:error name="radio_a.ros_version" />
                    </flux:field>
                </div>

                <div class="space-y-4 rounded-lg border border-zinc-100 p-4">
                    <flux:heading size="sm">Radio B</flux:heading>
                    <flux:field>
                        <flux:label>Identity</flux:label>
                        <flux:input wire:model.blur="radio_b.identity" placeholder="e.g. Tower B" />
                        <flux:error name="radio_b.identity" />
                    </flux:field>
                    <flux:field>
                        <flux:label>RouterOS version</flux:label>
                        <flux:select wire:model.live="radio_b.ros_version">
                            <flux:select.option value="7">RouterOS 7</flux:select.option>
                            <flux:select.option value="6">RouterOS 6</flux:select.option>
                        </flux:select>
                        <flux:error name="radio_b.ros_version" />
                    </flux:field>
                </div>
            </div>

            <div class="flex justify-end">
                <flux:button type="button" variant="primary" wire:click="nextStep">Next: Roles &amp; mode</flux:button>
            </div>
        </div>
    @endif

    @if ($step === 2)
        <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <flux:field>
                <flux:label>Link topology</flux:label>
                <flux:select wire:model.live="link_topology">
                    <flux:select.option value="ptp">Point-to-point -- one peer only</flux:select.option>
                    <flux:select.option value="ptmp">Point-to-multipoint -- AP end is a hub for multiple stations</flux:select.option>
                </flux:select>
                <flux:description>Point-to-point uses "bridge" mode on the AP end (single peer, works on CPE-class hardware). Point-to-multipoint uses "ap-bridge" (true multi-client AP, needs AP-capable hardware) -- re-run this wizard once per additional station.</flux:description>
                <flux:error name="link_topology" />
            </flux:field>

            <flux:field>
                <flux:label>Which end is the AP/master?</flux:label>
                <flux:select wire:model="ap_end">
                    <flux:select.option value="radio_a" :disabled="$link_topology === 'ptmp' && $radio_a['cpe_only']">Radio A ({{ $radio_a['identity'] }}){{ $link_topology === 'ptmp' && $radio_a['cpe_only'] ? ' -- CPE-only, can\'t run ap-bridge' : '' }}</flux:select.option>
                    <flux:select.option value="radio_b" :disabled="$link_topology === 'ptmp' && $radio_b['cpe_only']">Radio B ({{ $radio_b['identity'] }}){{ $link_topology === 'ptmp' && $radio_b['cpe_only'] ? ' -- CPE-only, can\'t run ap-bridge' : '' }}</flux:select.option>
                </flux:select>
                <flux:description>The other radio is configured as the Station.</flux:description>
                <flux:error name="ap_end" />
            </flux:field>

            <flux:field>
                <flux:label>Link mode</flux:label>
                <flux:select wire:model.live="link_mode">
                    <flux:select.option value="routed">Routed -- static IP each side, no L2 bridging</flux:select.option>
                    <flux:select.option value="bridged">Bridged -- transparent link (station-pseudobridge)</flux:select.option>
                </flux:select>
                <flux:description>Bridged uses station-pseudobridge for the widest hardware compatibility across MikroTik radios, rather than true station-bridge (which needs matching chipset support on both ends).</flux:description>
                <flux:error name="link_mode" />
            </flux:field>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="space-y-3">
                    <flux:field>
                        <flux:label>Radio A wireless interface</flux:label>
                        <flux:input wire:model.blur="radio_a.wireless_interface" placeholder="{{ $radio_a['ros_version'] === '6' ? 'wlan1' : 'wifi1' }}" />
                        <flux:description>Check <code>/interface {{ $radio_a['ros_version'] === '6' ? 'wireless' : 'wifi' }} print</code> on the router.</flux:description>
                        <flux:error name="radio_a.wireless_interface" />
                    </flux:field>
                    <flux:field>
                        <flux:checkbox wire:model.live="radio_a.cpe_only" label="This is a CPE/dish radio (e.g. SXTsq, LHG, Disc, Metal)" />
                        <flux:description>Fine as a point-to-point AP end (bridge mode). Can't run the AP end of a point-to-multipoint hub (ap-bridge needs AP-capable hardware).</flux:description>
                    </flux:field>
                    @if ($link_mode === 'bridged')
                        <flux:field>
                            <flux:label>LAN port to bridge in (optional)</flux:label>
                            <flux:input wire:model.blur="radio_a.lan_port" placeholder="ether1" />
                            <flux:description>Joins this Ethernet port to bridge-ptp so a LAN device on this end can reach across the link. Leave blank to bridge only the radio itself.</flux:description>
                            <flux:error name="radio_a.lan_port" />
                        </flux:field>
                    @endif
                </div>

                <div class="space-y-3">
                    <flux:field>
                        <flux:label>Radio B wireless interface</flux:label>
                        <flux:input wire:model.blur="radio_b.wireless_interface" placeholder="{{ $radio_b['ros_version'] === '6' ? 'wlan1' : 'wifi1' }}" />
                        <flux:description>Check <code>/interface {{ $radio_b['ros_version'] === '6' ? 'wireless' : 'wifi' }} print</code> on the router.</flux:description>
                        <flux:error name="radio_b.wireless_interface" />
                    </flux:field>
                    <flux:field>
                        <flux:checkbox wire:model.live="radio_b.cpe_only" label="This is a CPE/dish radio (e.g. SXTsq, LHG, Disc, Metal)" />
                        <flux:description>Fine as a point-to-point AP end (bridge mode). Can't run the AP end of a point-to-multipoint hub (ap-bridge needs AP-capable hardware).</flux:description>
                        <flux:error name="radio_b.cpe_only" />
                    </flux:field>
                    @if ($link_mode === 'bridged')
                        <flux:field>
                            <flux:label>LAN port to bridge in (optional)</flux:label>
                            <flux:input wire:model.blur="radio_b.lan_port" placeholder="ether1" />
                            <flux:description>Joins this Ethernet port to bridge-ptp so a LAN device on this end can reach across the link. Leave blank to bridge only the radio itself.</flux:description>
                            <flux:error name="radio_b.lan_port" />
                        </flux:field>
                    @endif
                </div>
            </div>

            <div class="flex justify-between">
                <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                <flux:button type="button" variant="primary" wire:click="nextStep">Next: RF &amp; security</flux:button>
            </div>
        </div>
    @endif

    @if ($step === 3)
        <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="grid gap-5 md:grid-cols-2">
                <flux:field>
                    <flux:label>Frequency (MHz)</flux:label>
                    <flux:input type="number" wire:model.blur="frequency_mhz" />
                    <flux:description>Must match on both radios. e.g. 5745 for a common 5GHz channel.</flux:description>
                    <flux:error name="frequency_mhz" />
                </flux:field>

                <flux:field>
                    <flux:label>Channel width</flux:label>
                    <flux:select wire:model="channel_width_mhz">
                        <flux:select.option value="20">20 MHz</flux:select.option>
                        <flux:select.option value="40">40 MHz</flux:select.option>
                    </flux:select>
                    <flux:error name="channel_width_mhz" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>SSID</flux:label>
                <flux:input wire:model.blur="ssid" />
                <flux:error name="ssid" />
            </flux:field>

            <flux:field>
                <flux:label>Security</flux:label>
                <flux:select wire:model="security_mode">
                    <flux:select.option value="wpa2">WPA2-PSK</flux:select.option>
                    <flux:select.option value="wpa2_wpa3">WPA2 + WPA3-PSK (mixed, RouterOS 7 both ends only)</flux:select.option>
                </flux:select>
                <flux:error name="security_mode" />
            </flux:field>

            <flux:field>
                <flux:label>Pre-shared key</flux:label>
                <flux:input type="password" wire:model.blur="psk" />
                <flux:error name="psk" />
            </flux:field>

            <div class="flex justify-between">
                <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                <flux:button type="button" variant="primary" wire:click="nextStep">Next: Distance &amp; IPs</flux:button>
            </div>
        </div>
    @endif

    @if ($step === 4)
        <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
            <flux:field>
                <flux:label>Link distance (km)</flux:label>
                <flux:input type="number" step="0.1" wire:model.blur="distance_km" />
                <flux:description>Used to set <code>distance=</code> for ACK-timeout tuning on long links -- verify behavior on your hardware.</flux:description>
                <flux:error name="distance_km" />
            </flux:field>

            <flux:field>
                <flux:label>Point-to-point subnet</flux:label>
                <flux:input wire:model.blur="ptp_subnet" placeholder="10.20.30.0/30" />
                <flux:description>The first two usable addresses are assigned to Radio A and Radio B.</flux:description>
                <flux:error name="ptp_subnet" />
            </flux:field>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="space-y-3 rounded-lg border border-zinc-100 p-4">
                    <flux:field>
                        <flux:checkbox wire:model.live="radio_a.add_remote_route" label="Add a route on Radio A to a network behind Radio B" />
                    </flux:field>
                    @if ($radio_a['add_remote_route'])
                        <flux:field>
                            <flux:label>Remote network (behind Radio B)</flux:label>
                            <flux:input wire:model.blur="radio_a.remote_network" placeholder="192.168.10.0/24" />
                            <flux:error name="radio_a.remote_network" />
                        </flux:field>
                    @endif
                </div>

                <div class="space-y-3 rounded-lg border border-zinc-100 p-4">
                    <flux:field>
                        <flux:checkbox wire:model.live="radio_b.add_remote_route" label="Add a route on Radio B to a network behind Radio A" />
                    </flux:field>
                    @if ($radio_b['add_remote_route'])
                        <flux:field>
                            <flux:label>Remote network (behind Radio A)</flux:label>
                            <flux:input wire:model.blur="radio_b.remote_network" placeholder="192.168.20.0/24" />
                            <flux:error name="radio_b.remote_network" />
                        </flux:field>
                    @endif
                </div>
            </div>

            <div class="flex justify-between">
                <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                <flux:button type="button" variant="primary" wire:click="generate">Generate scripts</flux:button>
            </div>
        </div>
    @endif

    @if ($step === 5 && $generatedScripts)
        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">Scripts ready</flux:heading>
                <div class="flex gap-2">
                    <flux:button type="button" variant="outline" wire:click="previousStep">Back to change settings</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="startOver">Start over</flux:button>
                </div>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <flux:label>Radio A script ({{ $radio_a['identity'] }})</flux:label>
                        <div x-data="{}">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="outline"
                                x-on:click="const blob = new Blob([@js($generatedScripts['script_a'])], {type: 'text/plain'}); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'ptp-radio-a.rsc'; a.click(); URL.revokeObjectURL(url);"
                            >Download .rsc</flux:button>
                        </div>
                    </div>
                    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                        <x-script-block>{{ $generatedScripts['script_a'] }}</x-script-block>
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <flux:label>Radio B script ({{ $radio_b['identity'] }})</flux:label>
                        <div x-data="{}">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="outline"
                                x-on:click="const blob = new Blob([@js($generatedScripts['script_b'])], {type: 'text/plain'}); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'ptp-radio-b.rsc'; a.click(); URL.revokeObjectURL(url);"
                            >Download .rsc</flux:button>
                        </div>
                    </div>
                    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                        <x-script-block>{{ $generatedScripts['script_b'] }}</x-script-block>
                    </div>
                </div>
            </div>

            <p class="text-xs text-zinc-500">Paste each script into a RouterOS terminal (Winbox &gt; New Terminal, or SSH) on the matching radio. Review interface names against your hardware first.</p>
        </div>
    @endif
</div>
