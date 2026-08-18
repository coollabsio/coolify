@props(['activeCount' => 0, 'activeText' => null, 'resetAction', 'resetLabel' => 'Reset filters'])

<div class="table-filter">
    <x-table.dropdown panel-class="w-44! overflow-hidden! p-0!" :multiselectable="true">
        <x-slot:trigger>
            <button type="button" aria-haspopup="listbox" :aria-expanded="open"
                @if ($activeText) title="{{ $activeText }}" @endif
                @class(['button max-w-80 min-w-0', 'button-highlighted' => $activeCount > 0])>
                <x-reicon name="filter" class="size-3.5 shrink-0" />
                <span class="truncate">Filter</span>
                @if ($activeCount > 0)
                    <span class="shrink-0 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-500 dark:bg-white/[0.07] dark:text-fg-dim">{{ $activeCount }}</span>
                @endif
            </button>
        </x-slot:trigger>
        <div class="max-h-80 overflow-y-auto p-1">{{ $slot }}</div>
        <div class="border-t border-neutral-200 bg-white p-1 dark:border-white/10 dark:bg-raised">
            <button type="button" class="listbox-option text-neutral-500 dark:text-fg-dim"
                wire:click="{{ $resetAction }}" @click="close()" @disabled($activeCount === 0)>
                <span>{{ $resetLabel }}</span>
                <x-reicon name="x" class="size-3.5" />
            </button>
        </div>
    </x-table.dropdown>
</div>
