@props(['active'])

<div class="mb-5 flex gap-2 border-b border-zinc-200 dark:border-zinc-700">
    @foreach ([
        'hotspot' => ['label' => 'Hotspot', 'route' => 'admin.tools.script-generator'],
        'ptp' => ['label' => 'PTP', 'route' => 'admin.tools.ptp-generator'],
    ] as $key => $tab)
        <a
            href="{{ route($tab['route']) }}"
            wire:navigate
            class="border-b-2 px-3 py-2 text-sm font-medium {{ $active === $key ? 'border-zinc-950 text-zinc-950 dark:border-zinc-100 dark:text-zinc-100' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' }}"
        >{{ $tab['label'] }}</a>
    @endforeach
</div>
