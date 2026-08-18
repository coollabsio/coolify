<div x-show="filteredItems.length === 0" class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white px-6 text-center dark:border-white/[0.08] dark:bg-white/[0.025]">
    <x-reicon name="search" class="mb-3 size-6 text-neutral-300 dark:text-fg-faint" />
    <p class="text-[13px] font-medium">No matching {{ $label }}</p>
    <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">Try a different search.</p>
</div>
