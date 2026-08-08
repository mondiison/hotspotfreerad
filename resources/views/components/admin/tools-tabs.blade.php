@props(['active'])

<div class="mb-5 flex gap-2 border-b border-zinc-200">
    @foreach ([
        'hotspot' => ['label' => 'Hotspot', 'route' => 'admin.tools.script-generator'],
        'ptp' => ['label' => 'PTP', 'route' => 'admin.tools.ptp-generator'],
    ] as $key => $tab)
        <a
            href="{{ route($tab['route']) }}"
            wire:navigate
            class="border-b-2 px-3 py-2 text-sm font-medium {{ $active === $key ? 'border-zinc-950 text-zinc-950' : 'border-transparent text-zinc-500 hover:text-zinc-700' }}"
        >{{ $tab['label'] }}</a>
    @endforeach
</div>
