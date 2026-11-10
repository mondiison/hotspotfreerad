<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manual Transfer - {{ $subscriber->shop->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $tenant = $subscriber->shop->tenant;
    $logoImageUrl = $tenant->logo_image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_image_path) : null;
@endphp
<body class="min-h-screen bg-zinc-950 text-white antialiased" style="--brand: {{ $tenant->brand_color ?? '#10b981' }}">
    <main class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <div class="flex items-center gap-3">
                @if ($logoImageUrl)
                    <img src="{{ $logoImageUrl }}" alt="{{ $tenant->company_name }} logo" class="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-cover">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white" style="background-color: var(--brand)">{{ str($tenant->company_name)->substr(0, 1)->upper() }}</span>
                @endif
                <div>
                    <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
                    <p class="text-xs text-zinc-500">{{ $subscriber->shop->name }}</p>
                </div>
            </div>

            <h1 class="mt-4 text-2xl font-semibold">Pay by manual bank transfer</h1>
            <p class="mt-2 text-sm text-zinc-600">Transfer the exact amount below. Your renewal is applied once the operator confirms the payment.</p>

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
                    <dt class="text-zinc-500">Account</dt>
                    <dd class="font-mono font-medium">{{ $subscriber->username }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Transfer narration/reference</dt>
                    <dd class="break-all font-mono text-xs font-medium">{{ $payment->tx_ref }}</dd>
                </div>
            </dl>

            <div class="mt-5 rounded-md bg-zinc-100 p-4 text-sm leading-6 text-zinc-700">
                {{ $transfer['instructions'] ?? 'Use the transaction reference as your transfer narration, then wait for confirmation.' }}
            </div>

            <div class="mt-6 rounded-md bg-amber-50 p-4 text-sm text-amber-800">
                Your renewal will be applied once the operator confirms this transfer -- no further action is needed from you here.
            </div>
        </section>
    </main>
</body>
</html>
