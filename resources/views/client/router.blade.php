<x-layouts.client :title="$router->name">
    <a href="{{ route('client.dashboard') }}" class="text-sm text-zinc-500 hover:underline">&larr; Back to dashboard</a>

    <h1 class="mt-3 text-xl font-semibold">{{ $router->name }}</h1>
    <p class="text-sm text-zinc-500">{{ $router->shop->name }} &middot; {{ $router->detected_status }}</p>

    @if ($liveError)
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            Could not reach this router right now: {{ $liveError }}
        </div>
    @else
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-zinc-200 bg-white p-4">
                <p class="text-xs text-zinc-500 uppercase">CPU load</p>
                <p class="text-lg font-semibold">{{ $liveResource['cpu_percent'] ?? '—' }}%</p>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-white p-4">
                <p class="text-xs text-zinc-500 uppercase">Memory used</p>
                <p class="text-lg font-semibold">{{ number_format(($liveResource['ram_used_bytes'] ?? 0) / 1048576, 0) }} / {{ number_format(($liveResource['ram_total_bytes'] ?? 0) / 1048576, 0) }} MB</p>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-white p-4">
                <p class="text-xs text-zinc-500 uppercase">Uptime</p>
                <p class="text-lg font-semibold">{{ isset($liveResource['uptime_seconds']) ? \Carbon\CarbonInterval::seconds($liveResource['uptime_seconds'])->cascade()->forHumans(['short' => true]) : '—' }}</p>
            </div>
        </div>

        @if (!empty($health))
            <h2 class="mt-6 mb-2 text-sm font-semibold tracking-wide text-zinc-500 uppercase">Health</h2>
            <div class="rounded-lg border border-zinc-200 bg-white p-4 text-sm">
                @foreach ($health as $key => $value)
                    <div class="flex justify-between border-b border-zinc-100 py-1 last:border-0">
                        <span class="text-zinc-500">{{ $key }}</span>
                        <span class="font-medium">{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    @if ($router->provisioning_settings['enable_builtin_wifi'] ?? false)
        <h2 class="mt-6 mb-2 text-sm font-semibold tracking-wide text-zinc-500 uppercase">Wi-Fi name</h2>
        <div class="rounded-lg border border-zinc-200 bg-white p-4">
            <form method="POST" action="{{ route('client.routers.update-ssid', $router) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                @method('PUT')
                <flux:field class="min-w-[220px]">
                    <flux:label>Customers see this SSID when connecting</flux:label>
                    <flux:input name="hotspot_ssid" value="{{ old('hotspot_ssid', $router->provisioning_settings['hotspot_ssid'] ?? 'MMS Hotspot') }}" maxlength="32" required />
                    <flux:error name="hotspot_ssid" />
                </flux:field>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </form>
        </div>
    @endif
</x-layouts.client>
