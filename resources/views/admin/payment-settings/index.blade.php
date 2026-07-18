<x-layouts.admin title="Payment Setup" heading="Payment Setup" subheading="Connect each shop to its own Flutterwave account for customer hotspot payments.">
    <section class="grid gap-4 md:grid-cols-3">
        @foreach ([
            ['label' => 'Shops', 'value' => number_format($summary['shops']), 'hint' => 'Locations this admin can manage'],
            ['label' => 'Payments configured', 'value' => number_format($summary['configured']), 'hint' => 'Client ID and client secret saved'],
            ['label' => 'Webhooks ready', 'value' => number_format($summary['webhook_ready']), 'hint' => 'Webhook secret hash saved'],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-3 text-2xl font-semibold">{{ $stat['value'] }}</p>
                <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </section>

    <section class="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm leading-6 text-blue-900">
        Customer hotspot payments use the shop's Flutterwave v4 client ID and client secret. Platform Flutterwave settings are reserved for tenant subscription billing, so customer money only goes through a tenant shop after that shop is configured.
    </section>

    <div class="mt-6 grid gap-5 xl:grid-cols-2">
        @forelse ($shops as $shop)
            <form method="POST" action="{{ route('admin.payment-settings.update', $shop) }}" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                @csrf
                @method('PUT')

                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                    <div>
                        <h2 class="text-base font-semibold">{{ $shop->name }}</h2>
                        <p class="mt-1 text-sm text-zinc-500">{{ $shop->tenant->company_name }}{{ $shop->location_city ? ' - '.$shop->location_city : '' }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ number_format($shop->payments_count) }} customer payment {{ \Illuminate\Support\Str::plural('record', $shop->payments_count) }}</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:badge :color="$shop->hasCompleteFlutterwaveCredentials() ? 'emerald' : 'amber'" size="sm">
                            {{ $shop->hasCompleteFlutterwaveCredentials() ? 'Payments configured' : 'Payments not configured' }}
                        </flux:badge>
                        <flux:badge :color="$shop->hasFlutterwaveWebhookSecret() ? 'emerald' : 'zinc'" size="sm">
                            {{ $shop->hasFlutterwaveWebhookSecret() ? 'Webhook ready' : 'Webhook missing' }}
                        </flux:badge>
                    </div>
                </div>

                <flux:separator class="my-5" />

                <div class="mt-5 grid gap-4">
                    <flux:field>
                        <flux:label>Flutterwave client ID</flux:label>
                        <flux:input
                            name="flutterwave_client_id"
                            value="{{ old('flutterwave_client_id') }}"
                            icon="identification"
                            placeholder="{{ $shop->hasCompleteFlutterwaveCredentials() ? 'Leave blank to keep saved client ID' : 'Paste tenant Flutterwave v4 client ID' }}"
                        />
                        <flux:error name="flutterwave_client_id" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Flutterwave client secret</flux:label>
                        <flux:input
                            name="flutterwave_client_secret"
                            value="{{ old('flutterwave_client_secret') }}"
                            icon="key"
                            placeholder="{{ $shop->hasCompleteFlutterwaveCredentials() ? 'Leave blank to keep saved client secret' : 'Paste tenant Flutterwave v4 client secret' }}"
                            viewable
                        />
                        <flux:description>Client ID and secret must be saved together before online customer payment is enabled.</flux:description>
                        <flux:error name="flutterwave_client_secret" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Webhook secret hash</flux:label>
                        <flux:input
                            name="flutterwave_webhook_secret"
                            value="{{ old('flutterwave_webhook_secret') }}"
                            icon="shield-check"
                            placeholder="{{ $shop->hasFlutterwaveWebhookSecret() ? 'Leave blank to keep saved webhook secret' : 'Paste Flutterwave webhook verif-hash' }}"
                            viewable
                        />
                        <flux:description>Needed for automatic webhook confirmation. Payment callbacks can still verify successful payments after customer redirect.</flux:description>
                        <flux:error name="flutterwave_webhook_secret" />
                    </flux:field>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @if ($shop->hasCompleteFlutterwaveCredentials())
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox name="clear_flutterwave_credentials" value="1" label="Clear client credentials" />
                        </div>
                    @endif

                    @if ($shop->hasFlutterwaveWebhookSecret())
                        <div class="rounded-md border border-zinc-200 p-3">
                            <flux:checkbox name="clear_flutterwave_webhook_secret" value="1" label="Clear webhook secret" />
                        </div>
                    @endif
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary" icon="check">Save payment settings</flux:button>
                    <flux:button href="{{ route('admin.shops.edit', $shop) }}" variant="outline" icon="arrow-top-right-on-square">Open shop</flux:button>
                </div>
            </form>
        @empty
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center text-zinc-500 xl:col-span-2">
                No shops have been created yet.
            </div>
        @endforelse
    </div>
</x-layouts.admin>
