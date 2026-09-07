@props([
    'id' => null,
    'htmlId' => null,
    'label' => null,
    'helper' => null,
    'required' => false,
    'options' => [], // list of ['value' => ..., 'label' => ..., 'disabled' => bool]
    'placeholder' => 'Select…',
    'emptyText' => 'No options available.',
    'live' => false,
    'onChange' => null, // optional $wire method to call after a selection
    'onChangeArgs' => null, // optional arguments followed by the selected value
    'wire' => true, // false = purely client-side value (no Livewire binding)
    'value' => null, // initial value when wire=false
    'disabled' => false,
    'tooltip' => true,
    'portal' => false,
    'preserveValue' => false,
    'canGate' => null,
    'canResource' => null,
    'autoDisable' => true,
])

@php
    if ($canGate && $canResource && $autoDisable && ! Illuminate\Support\Facades\Gate::allows($canGate, $canResource)) {
        $disabled = true;
    }

    $triggerId = ($htmlId ?? $id).'-trigger';
    $panelId = ($htmlId ?? $id).'-panel';
@endphp

<div class="w-full min-w-0">
    @if ($label)
        {{--
            Keep helper outside the label so taps do not open the listbox trigger.
            Margin lives only on this wrapper (mb-0 on the label) so spacing matches
            plain text labels and is not doubled by .application-settings-form label rules.
        --}}
        {{-- Fixed h-4 matches the helper icon so side-by-side fields align with or without a helper. --}}
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $triggerId }}" class="mb-0! flex items-center gap-1.5 leading-4">
                {{ $label }}
                @if ($required)
                    <x-highlighted text="*" />
                @endif
            </label>
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </div>
    @endif
    <div class="relative min-w-0" x-data="{
        open: false,
        positioned: false,
        saving: false,
        options: @js(array_values($options)),
        value: @if (!$wire) @js($value) @elseif ($live && ! $onChange) @entangle($id).live @else @entangle($id) @endif,
        get current() {
            const found = this.options.find((option) => String(option.value) === String(this.value));
            return found ? found.label : @js($placeholder);
        },
        async choose(option) {
            if (this.saving || option.disabled) return;
            this.open = false;
            if (String(option.value) === String(this.value)) return;
            this.value = option.value;
            this.$dispatch('listbox-change', { value: option.value });
            @if ($onChange && is_array($onChangeArgs))
                this.saving = true;
                try {
                    await this.$wire.{{ $onChange }}(...@js($onChangeArgs), option.value);
                } finally {
                    this.saving = false;
                }
            @elseif ($onChange)
                this.saving = true;
                try {
                    await this.$wire.{{ $onChange }}();
                } finally {
                    this.saving = false;
                }
            @endif
        },
        toggle() {
            this.open = !this.open;
            this.positioned = false;
            if (this.open && @js($portal)) {
                this.$nextTick(() => requestAnimationFrame(() => this.positionPanel()));
            }
        },
        positionPanel(panel = null) {
            const trigger = this.$refs.trigger;
            panel ??= document.getElementById(@js($panelId));
            if (!trigger || !panel) return;

            const gap = 4;
            const edge = 12;
            const triggerRect = trigger.getBoundingClientRect();
            panel.style.width = 'max-content';
            panel.style.minWidth = `${triggerRect.width}px`;
            panel.style.maxWidth = `${window.innerWidth - (edge * 2)}px`;
            const panelWidth = Math.min(
                Math.max(triggerRect.width, panel.offsetWidth),
                window.innerWidth - (edge * 2),
            );
            const panelHeight = Math.min(panel.scrollHeight, 256);
            const fitsBelow = window.innerHeight - triggerRect.bottom - gap >= panelHeight;
            const top = fitsBelow
                ? triggerRect.bottom + gap
                : Math.max(edge, triggerRect.top - gap - panelHeight);
            const left = Math.min(
                Math.max(edge, triggerRect.left),
                window.innerWidth - panelWidth - edge,
            );

            panel.style.top = `${top}px`;
            panel.style.left = `${left}px`;
            panel.style.width = `${panelWidth}px`;
            this.positioned = true;
        },
    }" x-modelable="value" :class="{ 'pointer-events-none opacity-70': saving }"
        {{ $attributes->whereStartsWith('x-model') }}
        {{ $attributes->whereStartsWith('x-effect') }}
        @if ($preserveValue) wire:ignore @endif
        @click.outside="open = false" @keydown.escape="open = false" @resize.window="open && positionPanel()">
        <button x-ref="trigger" id="{{ $triggerId }}" type="button" class="listbox-trigger" @click="toggle()"
            @disabled($disabled) {{ $attributes->whereStartsWith('x-bind:disabled') }} aria-haspopup="listbox"
            :aria-expanded="open" @if ($tooltip) :title="current" @endif>
            <span class="listbox-trigger-label" x-text="current"></span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
            </svg>
        </button>
        @if ($portal)
            <div id="{{ $panelId }}" class="listbox-panel"
                style="position: fixed; z-index: 9999; visibility: hidden" x-show="open"
                x-cloak :style="{ visibility: positioned ? 'visible' : 'hidden' }"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.98]"
                x-effect="if (open) requestAnimationFrame(() => positionPanel($el))" role="listbox">
                <div x-show="options.length === 0"
                    class="px-3 py-2 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ $emptyText }}
                </div>
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
        @else
            <div x-ref="panel" class="listbox-panel" x-show="open" x-cloak
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.98]" role="listbox">
                <div x-show="options.length === 0"
                    class="px-3 py-2 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ $emptyText }}
                </div>
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
        @endif
    </div>
</div>
