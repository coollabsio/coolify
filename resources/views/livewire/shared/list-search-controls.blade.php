<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="w-full sm:max-w-sm">
        <x-table.search :placeholder="$placeholder" x-model.debounce.150ms="search"
            clear-when="search" clear-action="search = ''"
            class="h-8! rounded-lg! border-neutral-200! bg-white! py-0! pr-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint" />
    </div>
    <div class="flex items-center gap-3">
        <span class="text-[11px] text-neutral-500 dark:text-fg-faint"><span x-text="filteredItems.length"></span> <span x-text="filteredItems.length === 1 ? '{{ $singular }}' : '{{ $plural }}'"></span></span>
        <div class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.035]">
            <button type="button" x-on:click="setViewMode('table')" class="flex size-7.5 items-center justify-center rounded-md transition-colors" :class="viewMode === 'table' ? 'control-selected' : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'" aria-label="Table view"><x-reicon name="unordered-list" class="size-3.5" /></button>
            <button type="button" x-on:click="setViewMode('grid')" class="flex size-7.5 items-center justify-center rounded-md transition-colors" :class="viewMode === 'grid' ? 'control-selected' : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'" aria-label="Grid view"><x-reicon name="grid" class="size-3.5" /></button>
        </div>
    </div>
</div>
