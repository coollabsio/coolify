@props([
    'model',
    'livewire' => false,
    'options' => [10, 25, 50, 100],
    'storageKey' => null,
    'canGate' => null,
    'canResource' => null,
])

@php
    $disabled = $canGate && $canResource
        && ! Illuminate\Support\Facades\Gate::allows($canGate, $canResource);
@endphp

<div class="mb-0! flex h-7 items-center gap-1.5 text-[11px] text-neutral-500 dark:text-fg-dim"
    x-data="{
        selectedPageSize: @js((int) $options[0]),
        customPageSize: @js((int) $options[0]),
        customizingPageSize: false,
        applyPageSize(value) {
            if (value === '' || value === null) {
                this.customizingPageSize = false;
                return;
            }
            if (Number(value) > 100) {
                window.toast?.('Maximum is 100 rows per page.', { type: 'warning' });
            }
            const pageSizeValue = Math.min(100, Math.max(1, Number(value) || 1));
            this.selectedPageSize = pageSizeValue;
            this.customPageSize = pageSizeValue;
            this.customizingPageSize = false;
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
    <div x-show="!customizingPageSize">
        <x-table.dropdown panel-class="min-w-24!">
            <x-slot:trigger>
                <button type="button" aria-label="Items per page" aria-haspopup="listbox" :aria-expanded="open"
                    @disabled($disabled)
                    class="flex h-7! items-center gap-1 whitespace-nowrap rounded-md border border-neutral-200 pr-1.5 pl-2 text-[11px]! leading-none! tabular-nums text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                    <span x-text="selectedPageSize"></span>
                    <span class="text-neutral-400 dark:text-fg-faint">/ page</span>
                    <x-reicon name="chevron-down" class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" />
                </button>
            </x-slot:trigger>
            @foreach ($options as $option)
                <button type="button" class="listbox-option" role="option"
                    @disabled($disabled)
                    :aria-selected="selectedPageSize === {{ (int) $option }}"
                    x-on:click="close(); applyPageSize({{ (int) $option }})">
                    <span>{{ $option }}</span>
                    <x-reicon x-show="selectedPageSize === {{ (int) $option }}" name="check" class="size-3.5" />
                </button>
            @endforeach
            <button type="button" class="listbox-option" role="option"
                @disabled($disabled)
                x-on:click="close(); customPageSize = ''; customizingPageSize = true; $nextTick(() => $refs.customPageSize.focus())">
                Custom…
            </button>
        </x-table.dropdown>
    </div>
    <input x-cloak x-show="customizingPageSize" x-ref="customPageSize" x-model.number="customPageSize"
        x-on:keydown.enter.prevent="applyPageSize(customPageSize)" x-on:keydown.escape.prevent="customizingPageSize = false"
        x-on:blur="if (customizingPageSize) applyPageSize(customPageSize)" type="number" min="1" max="100" placeholder="1–100" inputmode="numeric"
        aria-label="Custom items per page" @disabled($disabled)
        class="mb-0! h-7! w-14! rounded-md! border-neutral-200! bg-transparent! px-1.5! py-0! text-[11px]! tabular-nums shadow-none! focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:text-fg-dim!" />
</div>
