@props([
    'model',
    'livewire' => false,
    'options' => [10, 25, 50, 100],
    'storageKey' => null,
])

<label class="mb-0! flex h-7 items-center gap-1.5 text-[11px] text-neutral-500 dark:text-fg-dim"
    x-data="{
        selectedPageSize: @js((int) $options[0]),
        customPageSize: @js((int) $options[0]),
        customizingPageSize: false,
        syncCustomOption(value) {
            const select = this.$refs.pageSizePreset;
            let customOption = select?.querySelector('[data-custom-page-size]');
            if (@js(array_map('intval', $options)).includes(Number(value))) {
                customOption?.remove();
            } else if (select) {
                if (!customOption) {
                    customOption = document.createElement('option');
                    customOption.dataset.customPageSize = '';
                    select.insertBefore(customOption, select.lastElementChild);
                }
                customOption.value = String(value);
                customOption.textContent = String(value);
            }
            if (select) select.value = String(value);
        },
        applyPageSize(value) {
            const pageSizeValue = Math.min(100, Math.max(1, Number(value) || 1));
            this.selectedPageSize = pageSizeValue;
            this.customPageSize = pageSizeValue;
            this.customizingPageSize = false;
            this.$nextTick(() => {
                this.syncCustomOption(pageSizeValue);
            });
            @if ($livewire)
                $wire.set(@js($model), pageSizeValue);
            @else
                {{ $model }} = pageSizeValue;
                page = 1;
            @endif
            @if (filled($storageKey))
                localStorage.setItem(@js($storageKey), pageSizeValue);
            @endif
        }
    }"
    x-init="
        @if (filled($storageKey))
            const savedPageSize = Number(localStorage.getItem(@js($storageKey)));
            if (savedPageSize >= 1 && savedPageSize <= 100) applyPageSize(savedPageSize);
        @endif
    ">
    <span class="hidden sm:inline">Rows</span>
    <span x-show="!customizingPageSize" class="relative inline-flex h-7 w-12 items-center">
        <select x-ref="pageSizePreset" aria-label="Items per page"
            x-on:change="$el.value === 'custom' ? (customizingPageSize = true, $nextTick(() => $refs.customPageSize.focus())) : applyPageSize($el.value)"
            class="peer absolute inset-0 z-10 h-7! w-12! cursor-pointer opacity-0">
            @foreach ($options as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
            <option value="custom">Custom…</option>
        </select>
        <span
            class="pointer-events-none inline-flex h-7 w-12 items-center justify-between border-0 px-1 text-[11px]! leading-none! tabular-nums text-neutral-500 transition-colors peer-hover:text-black dark:text-fg-dim dark:peer-hover:text-fg">
            <span x-text="selectedPageSize"></span>
            <x-reicon name="chevron-down" class="size-3 text-neutral-400 dark:text-fg-faint" />
        </span>
    </span>
    <input x-cloak x-show="customizingPageSize" x-ref="customPageSize" x-model.number="customPageSize"
        x-on:keydown.enter.prevent="applyPageSize(customPageSize)" x-on:keydown.escape.prevent="customizingPageSize = false"
        x-on:blur="if (customizingPageSize) applyPageSize(customPageSize)" type="number" min="1" max="100" inputmode="numeric"
        aria-label="Custom items per page"
        class="mb-0! h-7! w-14! rounded-md! border-neutral-200! bg-transparent! px-1.5! py-0! text-[11px]! tabular-nums shadow-none! focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:text-fg-dim!" />
</label>
