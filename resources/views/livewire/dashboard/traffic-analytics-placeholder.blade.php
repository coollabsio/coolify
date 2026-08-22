<section class="mb-0! min-w-0">
    {{-- Real section header; only the KPI grid below is a skeleton. --}}
    <div class="mb-3 flex items-end justify-between gap-4">
        <div>
            <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                Traffic analytics
            </h2>
            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                Team-wide request volume across traffic-enabled servers
            </p>
        </div>
        <div class="flex items-center gap-2">
            <x-skeleton class="h-8 w-40 rounded-lg" />
            <x-skeleton class="h-4 w-24 rounded-full" />
        </div>
    </div>

    <x-skeleton.tiles :count="4" grid="grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" rounded="rounded-xl" />
</section>
