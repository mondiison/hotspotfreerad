<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Lookup Needed</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-white antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col justify-center px-5 py-8">
        <section class="rounded-lg border border-white/10 bg-white p-6 text-zinc-950">
            <p class="text-sm font-medium text-amber-700">Payment lookup needed</p>
            <h1 class="mt-2 text-2xl font-semibold">We could not match this payment return.</h1>
            <p class="mt-3 text-sm leading-6 text-zinc-600">
                The payment provider returned to MMS Radius, but the transaction reference was missing or did not match a pending hotspot payment.
            </p>

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">Transaction reference</dt>
                    <dd class="font-mono text-xs font-medium">{{ $txRef ?: 'Not provided' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Provider reference</dt>
                    <dd class="font-mono text-xs font-medium">{{ $providerReference ?: 'Not provided' }}</dd>
                </div>
            </dl>

            <div class="mt-6 rounded-md bg-zinc-100 p-4 text-sm leading-6 text-zinc-700">
                If money was debited, contact the hotspot operator with the references shown here. Do not retry repeatedly until the operator confirms the payment status.
            </div>
        </section>
    </main>
</body>
</html>
