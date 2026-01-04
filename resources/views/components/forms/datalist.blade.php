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
                selected: @entangle($modelBinding).live,
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
                    // Focus input after selection
                    this.$refs.searchInput.focus();
                },

                removeOption(value, event) {
                    // Ensure selected is an array
                    if (!Array.isArray(this.selected)) {
                        this.selected = [];
                        return;
                    }
                    // Prevent triggering container click
                    event.stopPropagation();
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
            }" @click.outside="open = false" class="relative">

            {{-- Unified Input Container with Tags Inside --}}
            <div @click="$refs.searchInput.focus()" x-data="{ focused: false }" @focusin="focused = true" @focusout="focused = false"
                class="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto scrollbar py-1.5  px-2 w-full text-sm rounded-sm border-0 bg-white dark:bg-coolgray-100 cursor-text px-1 text-black dark:text-white"
                :style="focused ? 'box-shadow: inset 4px 0 0 #6b16ed, inset 0 0 0 2px #e5e5e5;' : 'box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px #e5e5e5;'"
                x-init="$watch('focused', () => { if ($root.classList.contains('dark') || document.documentElement.classList.contains('dark')) { $el.style.boxShadow = focused ? 'inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424' : 'inset 4px 0 0 transparent, inset 0 0 0 2px #242424'; } })"
                :class="{
                        'opacity-50': {{ $disabled ? 'true' : 'false' }}
                    }" wire:loading.class="opacity-50"
                wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]">

                {{-- Selected Tags Inside Input --}}
                <template x-for="value in selected" :key="value">
                    <button type="button" @click.stop="removeOption(value, $event)"
                        :disabled="{{ $disabled ? 'true' : 'false' }}"
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs bg-coolgray-200 dark:bg-coolgray-700 rounded whitespace-nowrap {{ $disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-red-100 dark:hover:bg-red-900/20 hover:text-red-600 dark:hover:text-red-400' }}"
                        aria-label="Remove">
                        <span x-text="getSelectedText(value)" class="max-w-[200px] truncate"></span>
                    </button>
                </template>

                {{-- Search Input (Borderless, Inside Container) --}}
                <input type="text" x-model="search" x-ref="searchInput" @input="filterOptions()" @focus="open = true"
                    @keydown.escape="open = false" :placeholder="(Array.isArray(selected) && selected.length > 0) ? '' :
                        {{ json_encode($placeholder ?: 'Search...') }}" @required($required) @readonly($readonly)
                    @disabled($disabled) @if ($autofocus) autofocus @endif
                    class="flex-1 min-w-[120px] text-sm border-0 outline-none bg-transparent p-0 focus:ring-0 placeholder:text-neutral-400 dark:placeholder:text-neutral-600 text-black dark:text-white" />
            </div>

            {{-- Dropdown Options --}}
            <div x-show="open && !{{ $disabled ? 'true' : 'false' }}" x-transition
                class="absolute z-50 w-full mt-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-400 rounded shadow-lg max-h-60 overflow-auto scrollbar">

                <template x-if="filteredOptions.length === 0">
                    <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                        No options found
                    </div>
                </template>

                <template x-for="option in filteredOptions" :key="option.value">
                    <div @click="toggleOption(option.value)"
                        class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200 flex items-center gap-3"
                        :class="{ 'bg-neutral-50 dark:bg-coolgray-300': isSelected(option.value) }">
                        <input type="checkbox" :checked="isSelected(option.value)"
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
            {{-- Single Selection Mode with Alpine.js --}}
            <div x-data="{
            open: false,
            search: '',
            selected: @entangle(($attributes->whereStartsWith('wire:model')->first() ? $attributes->wire('model')->value() : $modelBinding)).live,
            options: [],
            filteredOptions: [],

            init() {
                this.options = Array.from(this.$refs.datalist.querySelectorAll('option')).map(opt => {
                    // Skip disabled options
                    if (opt.disabled) {
                        return null;
                    }
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
                }).filter(opt => opt !== null);
                this.filteredOptions = this.options;
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

            selectOption(value) {
                this.selected = value;
                this.search = '';
                this.open = false;
                this.filterOptions();
            },

            openDropdown() {
                if ({{ $disabled ? 'true' : 'false' }}) return;
                this.open = true;
                this.$nextTick(() => {
                    if (this.$refs.searchInput) {
                        this.$refs.searchInput.focus();
                    }
                });
            },

            getSelectedText() {
                if (!this.selected || this.selected === 'default') return '';
                const option = this.options.find(opt => opt.value == this.selected);
                return option ? option.text : this.selected;
            },

            isDefaultValue() {
                return !this.selected || this.selected === 'default' || this.selected === '';
            }
        }" @click.outside="open = false" class="relative">

                {{-- Hidden input for form validation --}}
                <input type="hidden" :value="selected" @required($required) />

                {{-- Input Container --}}
                <div @click="openDropdown()" x-data="{ focused: false }" @focusin="focused = true" @focusout="focused = false"
                    class="flex items-center gap-2 py-1.5 w-full text-sm rounded-sm border-0 bg-white dark:bg-coolgray-100 cursor-text text-black dark:text-white"
                    :style="focused ? 'box-shadow: inset 4px 0 0 #6b16ed, inset 0 0 0 2px #e5e5e5;' : 'box-shadow: inset 4px 0 0 transparent, inset 0 0 0 2px #e5e5e5;'"
                    x-init="$watch('focused', () => { if ($root.classList.contains('dark') || document.documentElement.classList.contains('dark')) { $el.style.boxShadow = focused ? 'inset 4px 0 0 #fcd452, inset 0 0 0 2px #242424' : 'inset 4px 0 0 transparent, inset 0 0 0 2px #242424'; } })"
                    :class="{
                    'opacity-50': {{ $disabled ? 'true' : 'false' }}
                }" wire:loading.class="opacity-50" wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]">

                    {{-- Display Selected Value or Search Input --}}
                    <div class="flex-1 flex items-center min-w-0 px-1">
                        <template x-if="!isDefaultValue() && !open">
                            <span class="text-sm flex-1 truncate text-black dark:text-white px-2"
                                x-text="getSelectedText()"></span>
                        </template>
                        <input type="text" x-show="isDefaultValue() || open" x-model="search" x-ref="searchInput"
                            @input="filterOptions()" @focus="open = true" @keydown.escape="open = false"
                            :placeholder="{{ json_encode($placeholder ?: 'Search...') }}" @readonly($readonly)
                            @disabled($disabled) @if ($autofocus) autofocus @endif
                            class="flex-1 text-sm border-0 outline-none bg-transparent p-0 focus:ring-0 placeholder:text-neutral-400 dark:placeholder:text-neutral-600 text-black dark:text-white px-2" />
                    </div>

                    {{-- Dropdown Arrow --}}
                    <button type="button" @click.stop="open = !open" :disabled="{{ $disabled ? 'true' : 'false' }}"
                        class="shrink-0 text-neutral-400 px-2 {{ $disabled ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                </div>

                {{-- Dropdown Options --}}
                <div x-show="open && !{{ $disabled ? 'true' : 'false' }}" x-transition
                    class="absolute z-50 w-full mt-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-400 rounded shadow-lg max-h-60 overflow-auto scrollbar">

                    <template x-if="filteredOptions.length === 0">
                        <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                            No options found
                        </div>
                    </template>

                    <template x-for="option in filteredOptions" :key="option.value">
                        <div @click="selectOption(option.value)"
                            class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200"
                            :class="{ 'bg-neutral-50 dark:bg-coolgray-300': selected == option.value }">
                            <span class="text-sm" x-text="option.text"></span>
                        </div>
                    </template>
                </div>

                {{-- Hidden datalist for options --}}
                <datalist x-ref="datalist" style="display: none;">
                    {{ $slot }}
                </datalist>
            </div>
    @endif

    @error($modelBinding)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>