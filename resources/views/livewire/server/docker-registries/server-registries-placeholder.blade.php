<div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
    <div aria-hidden="true"
        class="flex items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 dark:border-white/[0.08] dark:bg-white/[0.05]">
        <x-skeleton class="size-4 shrink-0 rounded" />
        <x-skeleton class="h-3 w-32 rounded-full" />
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <x-skeleton class="h-8 w-20 rounded-lg" />
            <x-skeleton class="h-8 w-16 rounded-lg" />
        </div>
    </div>
    <x-skeleton.list :rows="2" label="Loading registries" />
</div>
