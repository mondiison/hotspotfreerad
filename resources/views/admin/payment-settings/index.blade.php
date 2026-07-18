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
                        <span class="rounded-full px-2 py-1 text-xs font-medium {{ $shop->hasCompleteFlutterwaveCredentials() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ $shop->hasCompleteFlutterwaveCredentials() ? 'Payments configured' : 'Payments not configured' }}
                        </span>
                        <span class="rounded-full px-2 py-1 text-xs font-medium {{ $shop->hasFlutterwaveWebhookSecret() ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600' }}">
                            {{ $shop->hasFlutterwaveWebhookSecret() ? 'Webhook ready' : 'Webhook missing' }}
                        </span>
                    </div>
                </div>

                <div class="mt-5 grid gap-4">
                    <label class="block">
                        <span class="text-sm font-medium">Flutterwave client ID</span>
                        <input name="flutterwave_client_id" value="{{ old('flutterwave_client_id') }}" placeholder="{{ $shop->hasCompleteFlutterwaveCredentials() ? 'Leave blank to keep saved client ID' : 'Paste tenant Flutterwave v4 client ID' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                        @error('flutterwave_client_id') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Flutterwave client secret</span>
                        <input name="flutterwave_client_secret" value="{{ old('flutterwave_client_secret') }}" placeholder="{{ $shop->hasCompleteFlutterwaveCredentials() ? 'Leave blank to keep saved client secret' : 'Paste tenant Flutterwave v4 client secret' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                        <span class="mt-1 block text-xs text-zinc-500">Client ID and secret must be saved together before online customer payment is enabled.</span>
                        @error('flutterwave_client_secret') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Webhook secret hash</span>
                        <input name="flutterwave_webhook_secret" value="{{ old('flutterwave_webhook_secret') }}" placeholder="{{ $shop->hasFlutterwaveWebhookSecret() ? 'Leave blank to keep saved webhook secret' : 'Paste Flutterwave webhook verif-hash' }}" class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                        <span class="mt-1 block text-xs text-zinc-500">Needed for automatic webhook confirmation. Payment callbacks can still verify successful payments after customer redirect.</span>
                        @error('flutterwave_webhook_secret') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @if ($shop->hasCompleteFlutterwaveCredentials())
                        <label class="flex items-center gap-2 rounded-md border border-zinc-200 p-3 text-sm">
                            <input type="checkbox" name="clear_flutterwave_credentials" value="1" class="rounded border-zinc-300">
                            Clear client credentials
                        </label>
                    @endif

                    @if ($shop->hasFlutterwaveWebhookSecret())
                        <label class="flex items-center gap-2 rounded-md border border-zinc-200 p-3 text-sm">
                            <input type="checkbox" name="clear_flutterwave_webhook_secret" value="1" class="rounded border-zinc-300">
                            Clear webhook secret
                        </label>
                    @endif
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <button class="rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white">Save payment settings</button>
                    <a href="{{ route('admin.shops.edit', $shop) }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm">Open shop</a>
                </div>
            </form>
        @empty
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center text-zinc-500 xl:col-span-2">
                No shops have been created yet.
            </div>
        @endforelse
    </div>
</x-layouts.admin>
