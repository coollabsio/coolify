@php
    $inputId = $htmlId !== 'null' ? $htmlId . '-input' : null;
    $selectId = $htmlId !== 'null' ? $htmlId . '-select' : null;
@endphp

<div class="w-full" 
     x-data="inputWithSelect({
        defaultUnit: @js($defaultOption ?? ''),
        min: @js($min),
        max: @js($max),
        validUnits: @js(array_keys($options)),
        @if ($modelBinding !== 'null')
            combinedValue: @entangle($combinedBinding),
            structuredValue: @entangle($structuredBinding),
        @else
            combinedValue: @js($value ?? '0'),
            structuredValue: @js(['value' => $value ?? '', 'unit' => $defaultOption ?? '']),
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
            @change="updateStructured()"
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
        structuredValue: config.structuredValue,
        pendingSync: null,

        init() {
            // If structuredValue is empty/invalid, parse from combinedValue
            if (!this.structuredValue || !this.structuredValue.value) {
                this.parseFromCombined();
                this.structuredValue = this.toStructured();
            } else {
                // Use existing structuredValue
                this.inputValue = this.structuredValue.value || '';
                this.selectValue = this.structuredValue.unit || config.defaultUnit;
            }
            
            // Watch combinedValue for external changes
            this.$watch('combinedValue', (newVal) => {
                const current = this.toStructured();
                const parsed = this.parseCombinedValue(newVal);
                if (current.value !== parsed.value || current.unit !== parsed.unit) {
                    this.parseFromCombined();
                    this.structuredValue = this.toStructured();
                }
            });
            
            // Watch structuredValue for external changes
            this.$watch('structuredValue', (newVal) => {
                if (newVal && newVal.value !== undefined) {
                    const current = this.toStructured();
                    if (current.value !== newVal.value || current.unit !== newVal.unit) {
                        this.inputValue = newVal.value || '';
                        this.selectValue = newVal.unit || config.defaultUnit;
                        this.updateCombined();
                    }
                }
            }, { deep: true });
        },

        destroy() {
            if (this.pendingSync) {
                clearTimeout(this.pendingSync);
            }
        },

        parseFromCombined() {
            const parsed = this.parseCombinedValue(this.combinedValue || '');
            this.inputValue = parsed.value;
            this.selectValue = parsed.unit;
        },

        parseCombinedValue(combined) {
            // Parse combinedValue (e.g., "512m") into structured format
            // Only matches if suffix is a valid unit to avoid footguns
            if (!combined || combined === '0') {
                return { value: '', unit: config.defaultUnit };
            }

            // Try to match valid unit suffix (longest first to handle multi-char units if needed)
            const sortedUnits = [...config.validUnits].sort((a, b) => b.length - a.length);
            for (const unit of sortedUnits) {
                if (combined.endsWith(unit)) {
                    const value = combined.slice(0, -unit.length);
                    if (value && !isNaN(parseFloat(value))) {
                        return { value, unit };
                    }
                }
            }

            // No valid unit found, treat entire value as number with default unit
            return { value: combined, unit: config.defaultUnit };
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

        toStructured() {
            return {
                value: this.inputValue || '',
                unit: this.selectValue || config.defaultUnit
            };
        },

        updateCombined() {
            const structured = this.toStructured();
            if (!structured.value) {
                this.combinedValue = '0';
            } else {
                this.combinedValue = structured.value + structured.unit;
            }
        },

        updateStructured() {
            this.validateAndClamp();
            this.structuredValue = this.toStructured();
            this.updateCombined();
        },

        handleInputChange() {
            this.validateAndClamp();
            
            if (this.pendingSync) {
                clearTimeout(this.pendingSync);
            }
            this.pendingSync = setTimeout(() => {
                if (this.pendingSync !== null && document.activeElement === this.$refs.input) {
                    this.structuredValue = this.toStructured();
                    this.updateCombined();
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
            this.updateStructured();
        }
    }));
});
</script>
