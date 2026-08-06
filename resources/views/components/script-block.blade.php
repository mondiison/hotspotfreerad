@props(['label' => 'Copy'])

<div x-data="{ copied: false }" class="relative">
    <pre x-ref="scriptText" class="block max-w-full overflow-x-auto p-5 text-sm leading-6 text-zinc-900"><code class="block min-w-max">{{ $slot }}</code></pre>
    <button
        type="button"
        @click="window.copyText($refs.scriptText.innerText).then((ok) => { if (ok) { copied = true; setTimeout(() => copied = false, 1500) } })"
        class="absolute top-3 right-3 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-600 shadow-sm hover:bg-zinc-50"
    >
        <span x-show="! copied">{{ $label }}</span>
        <span x-show="copied" x-cloak>Copied!</span>
    </button>
</div>
