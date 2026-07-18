<x-layouts.admin
    title="Dashboard"
    heading="Dashboard"
    subheading="Operational overview for the hotspot control database."
>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['label' => 'Tenants', 'value' => $tenantCount],
            ['label' => 'Shops', 'value' => $shopCount],
            ['label' => 'Routers', 'value' => $routerCount],
            ['label' => 'Packages', 'value' => $packageCount],
            ['label' => 'Active Sessions', 'value' => $activeSessionCount],
        ] as $stat)
            <div class="rounded-lg border border-zinc-200 bg-white p-5">
                <p class="text-sm text-zinc-500">{{ $stat['label'] }}</p>
                <p class="mt-2 text-3xl font-semibold">{{ number_format($stat['value']) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5">
        <h2 class="text-base font-semibold">Build Order</h2>
        <div class="mt-4 grid gap-3 md:grid-cols-4">
            <a href="{{ route('admin.tenants.create') }}" class="rounded-md border border-zinc-200 px-4 py-3 text-sm hover:bg-zinc-50">1. Add tenant</a>
            <a href="{{ route('admin.shops.create') }}" class="rounded-md border border-zinc-200 px-4 py-3 text-sm hover:bg-zinc-50">2. Add shop</a>
            <a href="{{ route('admin.routers.create') }}" class="rounded-md border border-zinc-200 px-4 py-3 text-sm hover:bg-zinc-50">3. Add router</a>
            <a href="{{ route('admin.packages.create') }}" class="rounded-md border border-zinc-200 px-4 py-3 text-sm hover:bg-zinc-50">4. Add package</a>
        </div>
    </div>
</x-layouts.admin>
