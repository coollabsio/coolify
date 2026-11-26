<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="w-full sm:max-w-sm">
        <x-table.search :placeholder="$placeholder" x-model.debounce.150ms="search"
            clear-when="search" clear-action="search = ''"
            class="h-8! rounded-lg! border-neutral-200! bg-white! py-0! pr-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint" />
    </div>
    <x-canvas-view-toggle />
</div>
