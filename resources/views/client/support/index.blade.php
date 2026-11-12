<x-layouts.client title="Support">
    <h1 class="mb-1 text-xl font-semibold">Support</h1>
    <p class="mb-5 text-sm text-zinc-500">Questions about your account, billing, or your shop's setup.</p>

    @php $tenant = $client->tenant; @endphp

    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-5">
        <h2 class="font-medium">Contact {{ $tenant->company_name }}</h2>
        <p class="mt-1 text-sm text-zinc-500">{{ $tenant->company_name }} manages your account and can help with billing, packages, or your router.</p>

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($tenant->contact_phone)
                <a href="https://wa.me/{{ preg_replace('/\D+/', '', $tenant->contact_phone) }}" target="_blank" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    Message on WhatsApp
                </a>
                <a href="tel:{{ $tenant->contact_phone }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:border-zinc-400">
                    Call {{ $tenant->contact_phone }}
                </a>
            @endif
            @if ($tenant->contact_email)
                <a href="mailto:{{ $tenant->contact_email }}" class="rounded-md border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:border-zinc-400">
                    Email {{ $tenant->contact_email }}
                </a>
            @endif
        </div>

        @if (! $tenant->contact_phone && ! $tenant->contact_email)
            <p class="mt-3 text-sm text-amber-700">Your provider hasn't added a contact number or email yet.</p>
        @endif
    </div>

    <div class="rounded-lg border border-zinc-200 bg-white p-5">
        <h2 class="font-medium">Frequently asked questions</h2>
        <div class="mt-4 space-y-4 text-sm">
            <div>
                <p class="font-medium">My subscription says "past due" and I can't log in. What do I do?</p>
                <p class="mt-1 text-zinc-600">Go to Billing and pay for the current cycle. Your customers' hotspot access is never affected by this -- only your own dashboard login.</p>
            </div>
            <div>
                <p class="font-medium">How do I change my Wi-Fi name?</p>
                <p class="mt-1 text-zinc-600">Open a router from your dashboard -- if it has built-in Wi-Fi, you'll see a "Wi-Fi name" field you can edit directly.</p>
            </div>
            <div>
                <p class="font-medium">Can I add or remove a router myself?</p>
                <p class="mt-1 text-zinc-600">Not yet -- router setup is handled by {{ $tenant->company_name }}. Contact them above to add a new router.</p>
            </div>
            <div>
                <p class="font-medium">Where do voucher sales show up?</p>
                <p class="mt-1 text-zinc-600">Mark a voucher "sold" when you hand it to a customer, and it appears in Reports immediately.</p>
            </div>
        </div>
    </div>
</x-layouts.client>
