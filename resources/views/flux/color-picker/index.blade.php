@props([
    'name' => null,
    'value' => '#0f766e',
    'label' => null,
    'format' => 'hex',
    'copyable' => false,
    'swatches' => [],
])

@php
    if ($attributes->has(':swatches') && is_array($attributes->get(':swatches'))) {
        $swatches = $attributes->get(':swatches');
    }

    $wireModel = $attributes->wire('model');
    $modelName = $wireModel->value();
    $fieldId = $attributes->get('id') ?? 'color-picker-'.str()->random(8);
    $inputName = $name ?? $modelName;
    $currentValue = old($inputName, $value ?: '#0f766e');
@endphp

<div {{ $attributes->except(['id', ':swatches'])->whereDoesntStartWith('wire:model')->class(['space-y-2']) }} data-flux-color-picker>
    @if ($label)
        <label for="{{ $fieldId }}" class="block text-sm font-medium">{{ $label }}</label>
    @endif

    <div class="flex overflow-hidden rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900">
        <input
            id="{{ $fieldId }}"
            type="color"
            value="{{ $currentValue }}"
            class="h-10 w-14 border-0 bg-white dark:bg-zinc-900 p-1"
            data-color-picker-swatch
            aria-label="{{ $label ?? 'Color picker' }}"
        >
        <input
            name="{{ $inputName }}"
            value="{{ $currentValue }}"
            inputmode="text"
            pattern="#[0-9A-Fa-f]{6}"
            class="min-w-0 flex-1 border-0 px-3 py-2 font-mono text-sm uppercase focus:ring-0"
            data-color-picker-value
            {{ $wireModel }}
            @required($attributes->has('required'))
        >
        @if ($copyable)
            <button type="button" class="border-l border-zinc-200 dark:border-zinc-700 px-3 text-sm font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800" data-color-picker-copy>
                Copy
            </button>
        @endif
    </div>

    @if ($swatches)
        <div class="flex flex-wrap gap-2">
            @foreach ($swatches as $swatch)
                <button
                    type="button"
                    class="h-7 w-7 rounded-md border border-zinc-200 dark:border-zinc-700 ring-offset-2 hover:ring-2 hover:ring-zinc-300 dark:hover:ring-zinc-600"
                    style="background-color: {{ $swatch }}"
                    data-color-picker-preset="{{ $swatch }}"
                    aria-label="Use {{ $swatch }}"
                ></button>
            @endforeach
        </div>
    @endif
</div>

<script>
    document.querySelectorAll('[data-flux-color-picker]').forEach((picker) => {
        if (picker.dataset.bound === '1') {
            return;
        }

        picker.dataset.bound = '1';

        const swatch = picker.querySelector('[data-color-picker-swatch]');
        const value = picker.querySelector('[data-color-picker-value]');
        const copy = picker.querySelector('[data-color-picker-copy]');

        const setColor = (color) => {
            if (! color) {
                return;
            }

            const normalized = color.toUpperCase();
            value.value = normalized;
            swatch.value = normalized;
            value.dispatchEvent(new Event('input', { bubbles: true }));
            value.dispatchEvent(new Event('change', { bubbles: true }));
        };

        swatch?.addEventListener('input', () => setColor(swatch.value));
        value?.addEventListener('input', () => {
            if (/^#[0-9A-Fa-f]{6}$/.test(value.value)) {
                swatch.value = value.value;
            }
        });

        picker.querySelectorAll('[data-color-picker-preset]').forEach((button) => {
            button.addEventListener('click', () => setColor(button.dataset.colorPickerPreset));
        });

        copy?.addEventListener('click', () => navigator.clipboard?.writeText(value.value));
    });
</script>
