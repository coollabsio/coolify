@php
    $inputId = $htmlId !== 'null' ? $htmlId . '-input' : null;
    $selectId = $htmlId !== 'null' ? $htmlId . '-select' : null;
@endphp

<div class="w-full" 
     x-data="inputWithSelect({
        defaultUnit: @js($defaultOption ?? ''),
        min: @js($min),
        max: @js($max),
        @if ($modelBinding !== 'null')
            combinedValue: @entangle($modelBinding),
        @else
            combinedValue: @js($value ?? '0'),
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

    <div class="flex">
        {{-- Input --}}
        <input 
            type="{{ $type }}"
            @if ($inputId) id="{{ $inputId }}" @endif
            x-model="inputValue"
            @input="handleInputChange()"
            @blur="handleInputBlur($event)"
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
            x-model="selectValue"
            @change="updateCombined()"
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
document.addEventListener('alpine:init', () => {
    Alpine.data('inputWithSelect', (config) => ({
        inputValue: '',
        selectValue: config.defaultUnit,
        combinedValue: config.combinedValue,
        pendingSync: null,

        init() {
            this.parseValue(this.combinedValue);
            
            // Watch for external changes to combinedValue (from Livewire)
            this.$watch('combinedValue', (newVal) => {
                if (newVal !== this.combined()) {
                    this.parseValue(newVal);
                }
            });
        },

        destroy() {
            if (this.pendingSync) {
                clearTimeout(this.pendingSync);
            }
        },

        parseValue(val) {
            if (!val || val === '0') {
                this.inputValue = '';
                this.selectValue = config.defaultUnit;
                return;
            }
            const match = String(val).match(/^([\d.]+)\s*(.*)$/);
            if (match) {
                this.inputValue = match[1];
                this.selectValue = match[2] || config.defaultUnit;
            } else {
                this.inputValue = val;
            }
        },

        validateAndClamp() {
            const numValue = parseFloat(this.inputValue);
            if (isNaN(numValue)) return;
            if (config.min !== null && numValue < config.min) {
                this.inputValue = String(config.min);
            } else if (config.max !== null && numValue > config.max) {
                this.inputValue = String(config.max);
            }
        },

        combined() {
            if (!this.inputValue) return '0';
            return this.inputValue + this.selectValue;
        },

        updateCombined() {
            this.validateAndClamp();
            this.combinedValue = this.combined();
        },

        handleInputChange() {
            // Validate/clamp immediately as user types
            this.validateAndClamp();
            
            // Debounce the combined value update
            if (this.pendingSync) {
                clearTimeout(this.pendingSync);
            }
            this.pendingSync = setTimeout(() => {
                if (this.pendingSync !== null && document.activeElement === this.$refs.input) {
                    this.combinedValue = this.combined();
                }
                this.pendingSync = null;
            }, 500);
        },

        handleInputBlur(event) {
            if (this.pendingSync) {
                clearTimeout(this.pendingSync);
                this.pendingSync = null;
            }
            const relatedTarget = event.relatedTarget;
            const container = this.$refs.container;
            if (relatedTarget && container && container.contains(relatedTarget)) {
                return;
            }
            this.updateCombined();
        }
    }));
});
</script>
