@php
    $navGroupLinks = \App\Support\NavGroups::visibleLinksFor(auth()->user(), $group);
@endphp

@if (count($navGroupLinks) > 1)
    <flux:tab.group class="mb-6">
        <flux:tabs variant="segmented" scrollable>
            @foreach ($navGroupLinks as $navGroupLink)
                <flux:tab
                    href="{{ route($navGroupLink['route']) }}"
                    wire:navigate
                    icon="{{ $navGroupLink['icon'] }}"
                    :selected="request()->routeIs(\Illuminate\Support\Str::beforeLast($navGroupLink['route'], '.').'.*')"
                >
                    {{ $navGroupLink['label'] }}
                </flux:tab>
            @endforeach
        </flux:tabs>
    </flux:tab.group>
@endif
