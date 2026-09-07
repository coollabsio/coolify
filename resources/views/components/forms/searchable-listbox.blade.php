@props([
    'id' => null,
    'label' => null,
    'helper' => null,
    'required' => false,
    'options' => [], // list of ['value' => ..., 'label' => ..., 'disabled' => bool]
    'placeholder' => 'Select…',
    'searchPlaceholder' => 'Search…',
    'emptyText' => 'No matching options',
    'live' => false,
    'onChange' => null, // optional $wire method to call after a selection
    'wire' => true, // false = purely client-side value (no Livewire binding)
    'value' => null, // initial value when wire=false
    'disabled' => false,
])

<div class="w-full min-w-0">
    @if ($label)
        {{--
            Keep helper outside the label so taps do not open the listbox trigger.
            Margin lives only on this wrapper (mb-0 on the label) so spacing matches
            plain text labels and is not doubled by .application-settings-form label rules.
        --}}
        {{-- Fixed h-4 matches the helper icon so side-by-side fields align with or without a helper. --}}
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}-trigger" class="mb-0! flex items-center gap-1.5 leading-4">
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
        query: '',
        saving: false,
        options: @js(array_values($options)),
        value: @if (!$wire) @js($value) @elseif ($live && ! $onChange) @entangle($id).live @else @entangle($id) @endif,
        get current() {
            const found = this.options.find((option) => String(option.value) === String(this.value));
            return found ? found.label : @js($placeholder);
        },
        get filtered() {
            const query = this.query.toLowerCase().trim();
            if (query === '') {
                return this.options;
            }

            return this.options.filter((option) => {
                const label = String(option.label ?? '').toLowerCase();
                const value = String(option.value ?? '').toLowerCase();

                return label.includes(query) || value.includes(query);
            });
        },
        toggle() {
            if (@js((bool) $disabled)) {
                return;
            }

            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.$refs.search?.focus());
            } else {
                this.query = '';
            }
        },
        close() {
            this.open = false;
            this.query = '';
        },
        async choose(option) {
            if (this.saving || option.disabled) {
                return;
            }

            this.close();
            if (String(option.value) === String(this.value)) {
                return;
            }

            this.value = option.value;
            @if ($onChange)
                this.saving = true;
                try {
                    await this.$wire.{{ $onChange }}();
                } finally {
                    this.saving = false;
                }
            @endif
        }
    }" x-modelable="value" :class="{ 'pointer-events-none opacity-70': saving }"
        {{ $attributes->whereStartsWith('x-model') }}
        {{ $attributes->whereStartsWith('x-effect') }}
        @click.outside="close()" @keydown.escape.window="open && close()">
        <button id="{{ $id }}-trigger" type="button" class="listbox-trigger" @click="toggle()"
            @disabled($disabled)
            {{ $attributes->whereStartsWith('x-bind:disabled') }} aria-haspopup="listbox"
            :aria-expanded="open" :title="current">
            <span class="listbox-trigger-label" x-text="current"></span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
            </svg>
        </button>
        <div class="listbox-panel searchable-listbox-panel" x-show="open" x-cloak role="listbox"
            @click.stop>
            <div class="searchable-listbox-search">
                <x-reicon name="search"
                    class="pointer-events-none absolute top-1/2 left-3 size-3 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                <input x-ref="search" type="search" x-model="query" autocomplete="off"
                    placeholder="{{ $searchPlaceholder }}"
                    class="searchable-listbox-search-input"
                    @keydown.enter.prevent="filtered.length === 1 && choose(filtered[0])"
                    @keydown.escape.stop="close()" />
            </div>
            <div class="searchable-listbox-options">
                <template x-for="option in filtered" :key="String(option.value)">
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
                <p x-show="filtered.length === 0" class="searchable-listbox-empty">
                    {{ $emptyText }}
                </p>
            </div>
        </div>
    </div>
</div>
