<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bank Transfer - {{ $shop->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
                <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
            </div>

            <h1 class="mt-4 text-2xl font-semibold">Pay by bank transfer</h1>
            <p class="mt-2 text-sm text-zinc-600">Transfer the exact amount to this account. Access activates automatically when Flutterwave confirms the payment.</p>

            @if ($statusMessage ?? null)
                <div class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">{{ $statusMessage }}</div>
            @endif

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Bank</dt>
                    <dd class="text-lg font-semibold">{{ $transfer['bank_name'] ?? 'Flutterwave MFB' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Account number</dt>
                    <dd class="font-mono text-3xl font-semibold tracking-wide">{{ $transfer['account_number'] ?? 'Pending' }}</dd>
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
                    <dt class="text-zinc-500">Reference</dt>
                    <dd class="font-mono text-xs font-medium">{{ $payment->tx_ref }}</dd>
                </div>
                @if ($transfer['expires_at'] ?? null)
                    <div>
                        <dt class="text-zinc-500">Expires</dt>
                        <dd class="font-medium">{{ \Illuminate\Support\Carbon::parse($transfer['expires_at'])->toDayDateTimeString() }}</dd>
                    </div>
                @endif
            </dl>

            <form method="POST" action="{{ route('hotspot.payment.bank-transfer.check') }}" class="mt-6">
                @csrf
                <input type="hidden" name="tx_ref" value="{{ $payment->tx_ref }}">
                <button class="w-full rounded-md px-4 py-2 text-sm font-medium text-white" style="background-color: var(--brand)">
                    I have paid
                </button>
            </form>

            <p class="mt-4 text-center text-xs text-zinc-500">Keep this page open after paying. Do not reuse this account for another package.</p>
        </section>
    </main>
</body>
</html>
