@props(['label', 'storageKey'])

<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div class="relative w-full sm:max-w-sm">
        <x-reicon name="search"
            class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
        <input x-model.debounce.150ms="search" type="search" placeholder="Search {{ strtolower($label) }}"
            class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
        <button x-cloak x-show="search" @click="search = ''" type="button"
            class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
            aria-label="Clear search">
            <x-reicon name="x" class="size-3" />
        </button>
    </div>

    <div
        class="flex h-9 w-fit items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.035]">
        <button type="button" @click="viewMode = 'list'; localStorage.setItem('{{ $storageKey }}', 'list')"
            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
            :class="viewMode === 'list' ? 'control-selected' : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
            aria-label="List view" title="List view">
            <x-reicon name="unordered-list" class="size-3.5" />
        </button>
        <button type="button" @click="viewMode = 'grid'; localStorage.setItem('{{ $storageKey }}', 'grid')"
            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
            :class="viewMode === 'grid' ? 'control-selected' : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
            aria-label="Grid view" title="Grid view">
            <x-reicon name="grid" class="size-3.5" />
        </button>
    </div>
</div>
