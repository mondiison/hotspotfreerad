<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manual Transfer - {{ $shop->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $shop->tenant->brand_color ?? '#10b981' }}">
    @php
        $tenant = $shop->tenant;
        $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
    @endphp

    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <div class="flex items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-cover">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                @endif
                <div>
                    <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
                    <p class="text-xs text-zinc-500">{{ $shop->name }}</p>
                </div>
            </div>

            <h1 class="mt-4 text-2xl font-semibold">Pay by manual bank transfer</h1>
            <p class="mt-2 text-sm text-zinc-600">Transfer the exact amount below. Your internet access starts after the hotspot operator confirms the payment.</p>

            @if ($statusMessage ?? null)
                <div class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">{{ $statusMessage }}</div>
            @endif

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Bank</dt>
                    <dd class="text-lg font-semibold">{{ $transfer['bank_name'] ?? 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Account name</dt>
                    <dd class="font-semibold">{{ $transfer['account_name'] ?? 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Account number</dt>
                    <dd class="font-mono text-3xl font-semibold tracking-wide">{{ $transfer['account_number'] ?? 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Amount</dt>
                    <dd class="font-semibold">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Package</dt>
                    <dd class="font-medium">{{ $package->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Transfer narration/reference</dt>
                    <dd class="break-all font-mono text-xs font-medium">{{ $payment->tx_ref }}</dd>
                </div>
            </dl>

            <div class="mt-5 rounded-md bg-zinc-100 p-4 text-sm leading-6 text-zinc-700">
                {{ $transfer['instructions'] ?? 'Use the transaction reference as your transfer narration, then wait for confirmation.' }}
            </div>

            <form method="POST" action="{{ route('hotspot.payment.bank-transfer.check') }}" class="mt-6" x-data="{ checking: false }" @submit="checking = true">
                @csrf
                <input type="hidden" name="tx_ref" value="{{ $payment->tx_ref }}">
                <button class="flex w-full items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white disabled:cursor-wait disabled:opacity-75" style="background-color: var(--brand)" :disabled="checking">
                    <span x-show="checking" x-cloak class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    <span x-text="checking ? 'Checking confirmation...' : 'I have paid'">I have paid</span>
                </button>
            </form>

            <p class="mt-4 text-center text-xs text-zinc-500">Do not reuse this reference for another package.</p>
        </section>
    </main>
</body>
</html>
