<div class="w-full">
    @if ($label)
        <label class="flex gap-1 items-center mb-1 text-sm font-medium {{ $disabled ? 'text-neutral-600' : '' }}">
            {{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif

    @if ($multiple)
        {{-- Multiple Selection Mode with Alpine.js --}}
        <div x-data="{
            open: false,
            search: '',
            selected: @entangle($id).live,
            options: [],
            filteredOptions: [],

            init() {
                this.options = Array.from(this.$refs.datalist.querySelectorAll('option')).map(opt => {
                    // Try to parse as integer, fallback to string
                    let value = opt.value;
                    const intValue = parseInt(value, 10);
                    if (!isNaN(intValue) && intValue.toString() === value) {
                        value = intValue;
                    }
                    return {
                        value: value,
                        text: opt.textContent.trim()
                    };
                });
                this.filteredOptions = this.options;
                // Ensure selected is always an array
                if (!Array.isArray(this.selected)) {
                    this.selected = [];
                }
            },

            filterOptions() {
                if (!this.search) {
                    this.filteredOptions = this.options;
                    return;
                }
                const searchLower = this.search.toLowerCase();
                this.filteredOptions = this.options.filter(opt =>
                    opt.text.toLowerCase().includes(searchLower)
                );
            },

            toggleOption(value) {
                // Ensure selected is an array
                if (!Array.isArray(this.selected)) {
                    this.selected = [];
                }
                const index = this.selected.indexOf(value);
                if (index > -1) {
                    this.selected.splice(index, 1);
                } else {
                    this.selected.push(value);
                }
                this.search = '';
                this.filterOptions();
            },

            removeOption(value) {
                // Ensure selected is an array
                if (!Array.isArray(this.selected)) {
                    this.selected = [];
                    return;
                }
                const index = this.selected.indexOf(value);
                if (index > -1) {
                    this.selected.splice(index, 1);
                }
            },

            isSelected(value) {
                // Ensure selected is an array
                if (!Array.isArray(this.selected)) {
                    return false;
                }
                return this.selected.includes(value);
            },

            getSelectedText(value) {
                const option = this.options.find(opt => opt.value == value);
                return option ? option.text : value;
            }
        }"
        @click.outside="open = false"
        class="relative">

            {{-- Selected Items Display --}}
            <div class="grid grid-cols-2 gap-2 mb-2 max-h-32 overflow-y-auto" x-show="Array.isArray(selected) && selected.length > 0">
                <template x-for="value in selected" :key="value">
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 text-sm bg-coolgray-200 dark:bg-coolgray-700 rounded">
                        <span x-text="getSelectedText(value)" class="truncate flex-1"></span>
                        <button
                            type="button"
                            @click="removeOption(value)"
                            :disabled="{{ $disabled ? 'true' : 'false' }}"
                            class="text-lg leading-none hover:text-red-500 {{ $disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}"
                            aria-label="Remove">
                            ×
                        </button>
                    </span>
                </template>
            </div>

            {{-- Search Input --}}
            <input
                type="text"
                x-model="search"
                @input="filterOptions()"
                @focus="open = true"
                @keydown.escape="open = false"
                :placeholder="{{ json_encode($placeholder ?: 'Search...') }}"
                {{ $attributes->merge(['class' => $defaultClass]) }}
                @required($required)
                @readonly($readonly)
                @disabled($disabled)
                wire:dirty.class="dark:ring-warning ring-warning"
                wire:loading.attr="disabled"
                @if ($autofocus) x-ref="autofocusInput" @endif
            >

            {{-- Dropdown Options --}}
            <div
                x-show="open && !{{ $disabled ? 'true' : 'false' }}"
                x-transition
                class="absolute z-50 w-full mt-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-400 rounded shadow-lg max-h-60 overflow-auto">

                <template x-if="filteredOptions.length === 0">
                    <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                        No options found
                    </div>
                </template>

                <template x-for="option in filteredOptions" :key="option.value">
                    <div
                        @click="toggleOption(option.value)"
                        class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200 flex items-center gap-3"
                        :class="{ 'bg-neutral-50 dark:bg-coolgray-300': isSelected(option.value) }">
                        <input
                            type="checkbox"
                            :checked="isSelected(option.value)"
                            class="w-4 h-4 rounded border-neutral-300 dark:border-neutral-600 bg-white dark:bg-coolgray-100 text-black dark:text-white checked:bg-white dark:checked:bg-coolgray-100 focus:ring-coollabs dark:focus:ring-warning pointer-events-none"
                            tabindex="-1">
                        <span class="text-sm flex-1" x-text="option.text"></span>
                    </div>
                </template>
            </div>

            {{-- Hidden datalist for options --}}
            <datalist x-ref="datalist" style="display: none;">
                {{ $slot }}
            </datalist>
        </div>
    @else
        {{-- Single Selection Mode (Standard HTML5 Datalist) --}}
        <input
            list="{{ $id }}"
            {{ $attributes->merge(['class' => $defaultClass]) }}
            @required($required)
            @readonly($readonly)
            @disabled($disabled)
            wire:dirty.class="dark:ring-warning ring-warning"
            wire:loading.attr="disabled"
            name="{{ $id }}"
            @if ($value) value="{{ $value }}" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($attributes->whereStartsWith('wire:model')->first())
                {{ $attributes->whereStartsWith('wire:model')->first() }}
            @else
                wire:model="{{ $id }}"
            @endif
            @if ($instantSave)
                wire:change="{{ $instantSave === 'instantSave' || $instantSave == '1' ? 'instantSave' : $instantSave }}"
                wire:blur="{{ $instantSave === 'instantSave' || $instantSave == '1' ? 'instantSave' : $instantSave }}"
            @endif
            @if ($autofocus) x-ref="autofocusInput" @endif
        >
        <datalist id="{{ $id }}">
            {{ $slot }}
        </datalist>
    @endif

    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>
