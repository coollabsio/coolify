@props([
    'id' => null,
    'label' => null,
    'helper' => null,
    'required' => false,
    'options' => [], // list of ['value' => ..., 'label' => ..., 'disabled' => bool]
    'placeholder' => 'Select…',
    'live' => false,
    'onChange' => null, // optional $wire method to call after a selection
    'wire' => true, // false = purely client-side value (no Livewire binding)
    'value' => null, // initial value when wire=false
    'disabled' => false,
])

<div class="w-full">
    @if ($label)
        <label for="{{ $id }}-trigger" class="mb-1.5 flex w-fit items-center gap-1.5">
            {{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    <div class="relative" x-data="{
        open: false,
        options: @js(array_values($options)),
        value: @if (!$wire) @js($value) @elseif ($live) @entangle($id).live @else @entangle($id) @endif,
        get current() {
            const found = this.options.find((option) => String(option.value) === String(this.value));
            return found ? found.label : @js($placeholder);
        },
        choose(option) {
            if (option.disabled) return;
            this.open = false;
            if (String(option.value) === String(this.value)) return;
            this.value = option.value;
            @if ($onChange) this.$nextTick(() => this.$wire.{{ $onChange }}()); @endif
        }
    }" x-modelable="value" {{ $attributes->whereStartsWith('x-model') }}
        {{ $attributes->whereStartsWith('x-effect') }}
        @click.outside="open = false" @keydown.escape="open = false">
        <button id="{{ $id }}-trigger" type="button" class="listbox-trigger" @click="open = !open"
            @disabled($disabled)
            {{ $attributes->whereStartsWith('x-bind:disabled') }} aria-haspopup="listbox"
            :aria-expanded="open">
            <span class="truncate" x-text="current"></span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
            </svg>
        </button>
        <div class="listbox-panel" x-show="open" x-cloak role="listbox">
            <template x-for="option in options" :key="String(option.value)">
                <button type="button" class="listbox-option" role="option"
                    :class="{ 'listbox-option-disabled': option.disabled }"
                    :aria-selected="String(option.value) === String(value)" @click="choose(option)">
                    <span class="truncate" x-text="option.label"></span>
                    <svg x-show="String(option.value) === String(value)" xmlns="http://www.w3.org/2000/svg"
                        fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"
                        class="size-3.5 shrink-0">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </button>
            </template>
        </div>
    </div>
</div>
