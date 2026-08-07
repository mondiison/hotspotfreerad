<div>
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
        }
        .print-only { display: none; }
    </style>

    <div class="no-print">
        <div class="mb-5 flex items-center gap-2 text-sm">
            @foreach (['Router basics', 'Management network', 'Hotspot & profiles', 'Voucher template', 'Review & generate'] as $index => $label)
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
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Router identity</flux:label>
                        <flux:input wire:model.blur="router_identity" placeholder="e.g. Bebeji-Router01" />
                        <flux:description>Internal admin-facing label (<code>/system identity</code>). Can differ from the hotspot name customers see.</flux:description>
                        <flux:error name="router_identity" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Hotspot name</flux:label>
                        <flux:input wire:model.blur="hotspot_name" placeholder="e.g. Bebeji Hotspot" />
                        <flux:description>Shown to customers as the SSID and portal branding.</flux:description>
                        <flux:error name="hotspot_name" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>RouterOS version</flux:label>
                    <flux:select wire:model.live="ros_version">
                        <flux:select.option value="7">RouterOS 7</flux:select.option>
                        <flux:select.option value="6">RouterOS 6</flux:select.option>
                    </flux:select>
                    <flux:description>RouterOS 7 uses the built-in User Manager for vouchers (validity starts at first login). RouterOS 6 approximates this with an on-login script.</flux:description>
                    <flux:error name="ros_version" />
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model.live="has_wireless" label="This router has built-in wireless" />
                    <flux:description>Turn off for wired-only routers (e.g. hEX, CCR) -- the hotspot SSID will instead be provided by an external access point.</flux:description>
                </flux:field>

                <div class="grid gap-5 md:grid-cols-2">
                    <flux:field>
                        <flux:label>WAN port</flux:label>
                        <flux:input wire:model.blur="wan_port" placeholder="ether1" />
                        <flux:description>The port connected to the internet (ISP/Starlink router).</flux:description>
                        <flux:error name="wan_port" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Total Ethernet ports</flux:label>
                        <flux:input type="number" min="2" wire:model.blur="ethernet_port_count" />
                        <flux:description>Every port except WAN is bridged into the hotspot -- e.g. a 5-port router with WAN on ether1 bridges ether2-ether5.</flux:description>
                        <flux:error name="ethernet_port_count" />
                    </flux:field>
                </div>

                @if ($has_wireless)
                    <flux:field>
                        <flux:label>Wireless interface</flux:label>
                        <flux:input wire:model.blur="wireless_interface" placeholder="{{ $ros_version === '6' ? 'wlan1' : 'wifi1' }}" />
                        <flux:description>The built-in radio's interface name (check <code>/interface {{ $ros_version === '6' ? 'wireless' : 'wifi' }} print</code> on the router).</flux:description>
                        <flux:error name="wireless_interface" />
                    </flux:field>
                @endif

                <div class="flex justify-end">
                    <flux:button type="button" variant="primary" wire:click="nextStep">Next: Management network</flux:button>
                </div>
            </div>
        @endif

        @if ($step === 2)
            <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <flux:field>
                    <flux:checkbox wire:model.live="enable_mgmt_network" label="Add an isolated management network" />
                    <flux:description>A separate network for administering the router, kept off-limits to hotspot customers.</flux:description>
                </flux:field>

                @if ($enable_mgmt_network)
                    <flux:field>
                        <flux:label>Management network</flux:label>
                        <flux:input wire:model.blur="mgmt_network" placeholder="10.10.20.1/24" />
                        <flux:description>Gateway address and subnet size, e.g. <code>10.10.20.1/24</code>.</flux:description>
                        <flux:error name="mgmt_network" />
                    </flux:field>

                    @if ($has_wireless)
                        <flux:field>
                            <flux:label>Management Wi-Fi password</flux:label>
                            <flux:input wire:model.blur="mgmt_password" type="password" viewable placeholder="At least 8 characters" />
                            <flux:description>A second, WPA2/WPA3-protected SSID (named "{{ $hotspot_name }} Mgmt") is created for this network, separate from the open hotspot SSID.</flux:description>
                            <flux:error name="mgmt_password" />
                        </flux:field>
                    @else
                        <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-600">
                            This router has no built-in wireless, so the management network needs its own wired port -- wire a dedicated cable to it rather than sharing a hotspot LAN port.
                        </div>
                    @endif
                @endif

                <div class="flex justify-between">
                    <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                    <flux:button type="button" variant="primary" wire:click="nextStep">Next: Hotspot &amp; profiles</flux:button>
                </div>
            </div>
        @endif

        @if ($step === 3)
            <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <flux:field>
                    <flux:label>Hotspot network</flux:label>
                    <flux:input wire:model.blur="hotspot_network" placeholder="10.10.10.1/24" />
                    <flux:description>Gateway address and subnet size for customer devices, e.g. <code>10.10.10.1/24</code>.</flux:description>
                    <flux:error name="hotspot_network" />
                </flux:field>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <flux:label>Profiles &amp; limits</flux:label>
                        <flux:button type="button" size="sm" variant="outline" icon="plus" wire:click="addProfile">Add profile</flux:button>
                    </div>
                    <flux:error name="profiles" />

                    <div class="space-y-4">
                        @foreach ($profiles as $index => $profile)
                            <div class="rounded-lg border border-zinc-200 p-4" wire:key="profile-{{ $index }}">
                                <div class="grid gap-3 md:grid-cols-6">
                                    <flux:field class="md:col-span-2">
                                        <flux:label size="sm">Name</flux:label>
                                        <flux:input wire:model.blur="profiles.{{ $index }}.name" placeholder="1 Hour" />
                                        <flux:error name="profiles.{{ $index }}.name" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label size="sm">Download (Mbps)</flux:label>
                                        <flux:input type="number" min="1" wire:model.blur="profiles.{{ $index }}.download_mbps" />
                                        <flux:error name="profiles.{{ $index }}.download_mbps" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label size="sm">Upload (Mbps)</flux:label>
                                        <flux:input type="number" min="1" wire:model.blur="profiles.{{ $index }}.upload_mbps" />
                                        <flux:error name="profiles.{{ $index }}.upload_mbps" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label size="sm">Validity (minutes)</flux:label>
                                        <flux:input type="number" min="1" wire:model.blur="profiles.{{ $index }}.session_minutes" />
                                        <flux:error name="profiles.{{ $index }}.session_minutes" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label size="sm">Shared devices</flux:label>
                                        <flux:input type="number" min="1" wire:model.blur="profiles.{{ $index }}.shared_users" />
                                        <flux:error name="profiles.{{ $index }}.shared_users" />
                                    </flux:field>
                                </div>
                                <div class="mt-3 grid gap-3 md:grid-cols-4">
                                    <flux:field>
                                        <flux:label size="sm">Vouchers to generate</flux:label>
                                        <flux:input type="number" min="0" wire:model.blur="profiles.{{ $index }}.voucher_count" />
                                        <flux:error name="profiles.{{ $index }}.voucher_count" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label size="sm">Code prefix (optional)</flux:label>
                                        <flux:input wire:model.blur="profiles.{{ $index }}.voucher_prefix" placeholder="e.g. DLY-" maxlength="8" />
                                        <flux:error name="profiles.{{ $index }}.voucher_prefix" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label size="sm">Code length</flux:label>
                                        <flux:input type="number" min="4" max="16" wire:model.blur="profiles.{{ $index }}.voucher_code_length" />
                                        <flux:error name="profiles.{{ $index }}.voucher_code_length" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label size="sm">Characters</flux:label>
                                        <flux:select wire:model.blur="profiles.{{ $index }}.voucher_character_set">
                                            <flux:select.option value="alnum_safe">Letters &amp; numbers (recommended)</flux:select.option>
                                            <flux:select.option value="numeric">Numbers only</flux:select.option>
                                            <flux:select.option value="alpha_safe">Letters only</flux:select.option>
                                            <flux:select.option value="alnum_full">Letters &amp; numbers (all, case-sensitive)</flux:select.option>
                                        </flux:select>
                                        <flux:error name="profiles.{{ $index }}.voucher_character_set" />
                                    </flux:field>
                                </div>
                                <div class="mt-3 flex items-end justify-between gap-3">
                                    <flux:field>
                                        <flux:checkbox wire:model.blur="profiles.{{ $index }}.voucher_username_password_same" label="Username and password are the same" />
                                        <flux:description>Turn off to generate a different, independent password for each voucher.</flux:description>
                                    </flux:field>
                                    @if (count($profiles) > 1)
                                        <flux:button type="button" size="sm" variant="danger" icon="trash" wire:click="removeProfile({{ $index }})">Remove</flux:button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-between">
                    <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                    <flux:button type="button" variant="primary" wire:click="nextStep">Next: Voucher template</flux:button>
                </div>
            </div>
        @endif

        @if ($step === 4)
            <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <flux:label>Voucher print template</flux:label>
                <flux:error name="voucher_template" />

                <div class="grid gap-3 md:grid-cols-3">
                    <button
                        type="button"
                        wire:click="$set('voucher_template', 'grid')"
                        class="rounded-lg border p-4 text-left {{ $voucher_template === 'grid' ? 'border-zinc-950 bg-zinc-50 ring-2 ring-zinc-950/10' : 'border-zinc-200 hover:border-zinc-400' }}"
                    >
                        <p class="text-sm font-semibold text-zinc-950">Card grid</p>
                        <p class="mt-1 text-xs leading-5 text-zinc-500">Dashed-border cards, 3 per row -- good for cutting apart with scissors.</p>
                    </button>
                    <button
                        type="button"
                        wire:click="$set('voucher_template', 'compact')"
                        class="rounded-lg border p-4 text-left {{ $voucher_template === 'compact' ? 'border-zinc-950 bg-zinc-50 ring-2 ring-zinc-950/10' : 'border-zinc-200 hover:border-zinc-400' }}"
                    >
                        <p class="text-sm font-semibold text-zinc-950">Compact grid</p>
                        <p class="mt-1 text-xs leading-5 text-zinc-500">Minimal text, 6 per row -- fits far more vouchers per sheet to cut printing cost.</p>
                    </button>
                    <button
                        type="button"
                        wire:click="$set('voucher_template', 'receipt')"
                        class="rounded-lg border p-4 text-left {{ $voucher_template === 'receipt' ? 'border-zinc-950 bg-zinc-50 ring-2 ring-zinc-950/10' : 'border-zinc-200 hover:border-zinc-400' }}"
                    >
                        <p class="text-sm font-semibold text-zinc-950">Receipt / narrow strip</p>
                        <p class="mt-1 text-xs leading-5 text-zinc-500">One code per strip, large text -- good for 58-80mm thermal receipt printers.</p>
                    </button>
                </div>

                <div class="flex justify-between">
                    <flux:button type="button" variant="outline" wire:click="previousStep">Back</flux:button>
                    <flux:button type="button" variant="primary" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
                        <span wire:loading.remove wire:target="generate">Generate script &amp; vouchers</span>
                        <span wire:loading wire:target="generate">Generating...</span>
                    </flux:button>
                </div>
            </div>
        @endif

        @if ($step === 5 && $generatedScript)
            <div class="space-y-5">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Script &amp; vouchers ready</flux:heading>
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
                                x-on:click="const blob = new Blob([@js($generatedScript)], {type: 'text/plain'}); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'standalone-hotspot.rsc'; a.click(); URL.revokeObjectURL(url);"
                            >Download .rsc</flux:button>
                        </div>
                    </div>
                    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
                        <x-script-block>{{ $generatedScript }}</x-script-block>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">Paste this into a RouterOS terminal (Winbox &gt; New Terminal, or SSH) on a fresh or lightly-configured router. Review port names against your hardware first.</p>
                </div>

                @if ($generatedVouchers !== [])
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <flux:label>{{ count($generatedVouchers) }} voucher {{ \Illuminate\Support\Str::plural('code', count($generatedVouchers)) }}</flux:label>
                            <flux:button type="button" size="sm" variant="primary" icon="printer" x-data @click="window.print()">Print vouchers</flux:button>
                        </div>
                        <p class="text-xs text-zinc-500">Each voucher above is also created as a local hotspot user on the router with the username and password shown on the printable sheet.</p>
                    </div>
                @endif
            </div>
        @endif
    </div>

    @if ($step === 5 && $generatedVouchers !== [])
        <div class="print-only">
            @if ($voucher_template === 'compact')
                <div style="display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 3mm; font-family: Arial, sans-serif; color: #111827;">
                    @foreach ($generatedVouchers as $voucher)
                        <div style="min-height: 22mm; border: 1px dashed #71717a; border-radius: 4px; padding: 2mm; break-inside: avoid; text-align: center;">
                            <div style="font-size: 8px; color: #52525b;">{{ $voucher['profile'] }}</div>
                            @if ($voucher['username'] === $voucher['password'])
                                <div style="margin: 3px 0; padding: 3px; border-radius: 3px; background: #111827; color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .3px; overflow-wrap: anywhere;">{{ $voucher['username'] }}</div>
                            @else
                                <div style="margin-top: 3px; padding: 2px; border-radius: 3px; background: #111827; color: #fff; font-size: 9px; font-weight: 700; overflow-wrap: anywhere;">U: {{ $voucher['username'] }}</div>
                                <div style="margin-top: 2px; padding: 2px; border-radius: 3px; background: #52525b; color: #fff; font-size: 9px; font-weight: 700; overflow-wrap: anywhere;">P: {{ $voucher['password'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif ($voucher_template === 'receipt')
                <div style="font-family: Arial, sans-serif; color: #111827;">
                    @foreach ($generatedVouchers as $voucher)
                        <div style="width: 72mm; padding: 4mm; border-bottom: 1px dashed #71717a; page-break-inside: avoid;">
                            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase;">{{ $hotspot_name }}</div>
                            <div style="margin-top: 2px; font-size: 10px; color: #52525b;">{{ $voucher['profile'] }}</div>
                            @if ($voucher['username'] === $voucher['password'])
                                <div style="margin: 6px 0; padding: 6px; text-align: center; font-size: 20px; font-weight: 700; letter-spacing: 1px; border: 1px solid #111827; border-radius: 4px;">{{ $voucher['username'] }}</div>
                                <div style="font-size: 9px; color: #71717a;">Connect to the "{{ $hotspot_name }}" Wi-Fi, open the portal, and enter this code as both username and password.</div>
                            @else
                                <div style="margin: 6px 0; font-size: 13px;">
                                    <div style="padding: 5px; text-align: center; font-weight: 700; letter-spacing: .5px; border: 1px solid #111827; border-radius: 4px 4px 0 0;">User: {{ $voucher['username'] }}</div>
                                    <div style="padding: 5px; text-align: center; font-weight: 700; letter-spacing: .5px; border: 1px solid #111827; border-top: none; border-radius: 0 0 4px 4px;">Pass: {{ $voucher['password'] }}</div>
                                </div>
                                <div style="font-size: 9px; color: #71717a;">Connect to the "{{ $hotspot_name }}" Wi-Fi, open the portal, and enter this username and password.</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6mm; font-family: Arial, sans-serif; color: #111827;">
                    @foreach ($generatedVouchers as $voucher)
                        <div style="min-height: 40mm; border: 1px dashed #71717a; border-radius: 6px; padding: 4mm; break-inside: avoid; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase;">{{ $hotspot_name }}</div>
                                <div style="margin-top: 2px; font-size: 10px; color: #52525b;">{{ $voucher['profile'] }}</div>
                                @if ($voucher['username'] === $voucher['password'])
                                    <div style="margin: 8px 0; padding: 7px; border-radius: 4px; background: #111827; color: #fff; text-align: center; font-size: 17px; font-weight: 700; letter-spacing: .5px;">{{ $voucher['username'] }}</div>
                                @else
                                    <div style="margin-top: 8px; padding: 6px; border-radius: 4px 4px 0 0; background: #111827; color: #fff; text-align: center; font-size: 13px; font-weight: 700; letter-spacing: .3px;">User: {{ $voucher['username'] }}</div>
                                    <div style="padding: 6px; border-radius: 0 0 4px 4px; background: #52525b; color: #fff; text-align: center; font-size: 13px; font-weight: 700; letter-spacing: .3px;">Pass: {{ $voucher['password'] }}</div>
                                @endif
                            </div>
                            <div style="margin-top: 6px; font-size: 9px; color: #71717a;">Connect to Wi-Fi, open the portal, and enter this {{ $voucher['username'] === $voucher['password'] ? 'code as both username and password' : 'username and password' }}.</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
