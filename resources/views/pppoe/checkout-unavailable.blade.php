<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - {{ $subscriber->shop->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $shop = $subscriber->shop;
    $tenant = $shop->tenant;
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
                <p class="text-sm font-medium" style="color: var(--brand)">{{ $tenant->company_name }}</p>
            </div>

            <h1 class="mt-4 text-2xl font-semibold">Payment could not start</h1>
            <p class="mt-2 text-sm text-zinc-600">A pending payment was created, but online checkout could not continue.</p>

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Account</dt>
                    <dd class="font-mono font-medium">{{ $subscriber->username }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Transaction reference</dt>
                    <dd class="font-mono text-xs font-medium">{{ $payment->tx_ref }}</dd>
                </div>
            </dl>

            <div class="mt-6 rounded-md bg-amber-50 p-4 text-sm leading-6 text-amber-800">
                @if (($checkoutUnavailableReason ?? null) === 'missing_credentials')
                    This shop has no complete gateway client ID and client secret saved. Contact the operator.
                @elseif (($checkoutUnavailableReason ?? null) === 'missing_manual_bank_details')
                    Manual bank transfer is selected for this shop, but bank name, account name, or account number is missing. Contact the operator.
                @elseif (($checkoutUnavailableReason ?? null) === 'missing_gateway_secret_key')
                    {{ $shop->paymentGatewayName() }} checkout needs the gateway secret key. Contact the operator.
                @elseif (($checkoutUnavailableReason ?? null) === 'gateway_not_live')
                    {{ $shop->paymentGatewayName() }} has been selected for this shop, but its checkout adapter is not live yet. Contact the operator.
                @elseif (($checkoutUnavailableReason ?? null) === 'missing_checkout_url')
                    The payment gateway accepted the request, but did not return a checkout link. Contact the operator.
                @else
                    Gateway checkout could not start. Contact the operator, or try again in a moment.
                @endif
            </div>

            @if ($shop->contactPhone())
                <p class="mt-4 text-center text-xs text-zinc-500">Call {{ $shop->contactPhone() }} for help.</p>
            @endif
        </section>
    </main>
</body>
</html>
