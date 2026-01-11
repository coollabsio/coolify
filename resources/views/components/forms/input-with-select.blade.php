@php
    $inputId = $htmlId !== 'null' ? $htmlId . '-input' : null;
    $selectId = $htmlId !== 'null' ? $htmlId . '-select' : null;
@endphp

<style>
    /* Apply dirty styling to visible input when hidden input is dirty */
    .input-with-select-container input[type="hidden"].dirty-tracker ~ input {
        box-shadow: inset 4px 0 0 #6b16ed, inset 0 0 0 2px #e5e5e5 !important;
    }
    .dark .input-with-select-container input[type="hidden"].dirty-tracker ~ input {
        box-shadow: inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424 !important;
    }
</style>

<div class="w-full"
     x-data="inputWithSelect({
        defaultUnit: @js($defaultOption ?? ''),
        min: @js($min),
        max: @js($max),
        validUnits: @js(array_keys($options)),
        @if ($modelBinding !== 'null')
            entangled: @entangle($combinedBinding),
        @else
            entangled: @js($value ?? '0'),
        @endif
     })"
     x-ref="container">
    @if ($label)
        <label @if ($inputId) for="{{ $inputId }}" @endif class="flex gap-1 items-center mb-1 text-sm font-medium">
            {{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif

    <div class="flex input-with-select-container">
        {{-- Hidden input for wire:dirty tracking (binds to combinedValue which has the full value with unit) --}}
        @if ($modelBinding !== 'null')
            <input type="hidden"
                wire:model={{ $combinedBinding }}
                wire:dirty.class="dirty-tracker"
            />
        @endif

        {{-- Input --}}
        <input
            type="{{ $type }}"
            @if ($inputId) id="{{ $inputId }}" @endif
            x-model="value"
            @blur="commit()"
            @disabled($disabled)
            @readonly($readonly)
            placeholder="{{ $placeholder }}"
            autocomplete="{{ $autocomplete }}"
            name="{{ $name }}-input"
            @if ($min !== null) min="{{ $min }}" @endif
            @if ($max !== null) max="{{ $max }}" @endif
            minlength="{{ $minlength }}"
            maxlength="{{ $maxlength }}"
            class="{{ $defaultClass }} rounded-r-none flex-1 border-r-0"
            @if ($autofocus) x-ref="autofocusInput" @endif
            aria-label="{{ $label }}"
            x-ref="input"
        />

        {{-- Select --}}
        <select
            @if ($selectId) id="{{ $selectId }}" @endif
            x-model="unit"
            @change="commit()"
            @disabled($disabled)
            name="{{ $name }}-select"
            class="select rounded-l-none w-auto min-w-[70px] border-l-0"
            aria-label="{{ $label }} unit"
        >
            @foreach($options as $key => $display)
                <option value="{{ $key }}">{{ $display }}</option>
            @endforeach
        </select>
    </div>

    @if (!$label && $helper)
        <x-helper :helper="$helper" />
    @endif
    @error($modelBinding)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>

<script>
(function() {
    let registered = false;

    function registerInputWithSelect() {
        // Prevent duplicate registration
        if (registered) {
            return;
        }

        Alpine.data('inputWithSelect', ({ defaultUnit, validUnits, entangled }) => ({
            value: '',
            unit: defaultUnit,
            entangled: entangled,

            init() {
                this.fromCombined(this.entangled);

                // Watch for external changes from Livewire
                this.$watch('entangled', (newVal) => {
                    const current = this.combined;
                    if (newVal !== current) {
                        this.fromCombined(newVal);
                    }
                });
            },

            get combined() {
                return this.value ? this.value + this.unit : '0';
            },

            commit() {
                this.entangled = this.combined;
            },

            fromCombined(raw) {
                if (!raw || raw === '0' || raw === 'null' || raw === null) {
                    this.value = '';
                    this.unit = defaultUnit;
                    return;
                }

                const units = [...validUnits].sort((a, b) => b.length - a.length);
                for (const u of units) {
                    if (raw.endsWith(u)) {
                        const val = raw.slice(0, -u.length);
                        if (val) {
                            this.value = val;
                            this.unit = u;
                            return;
                        }
                    }
                }

                this.value = raw;
                this.unit = defaultUnit;
            }
        }));

        registered = true;
    }

    // Alpine already initialized (SPA navigation) - register immediately
    if (window.Alpine) {
        registerInputWithSelect();
    }

    // Also listen for alpine:init (initial page load)
    document.addEventListener('alpine:init', registerInputWithSelect);
})();
</script>
