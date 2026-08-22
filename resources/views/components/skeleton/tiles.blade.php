@props([
    'count' => 5,
    'grid' => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
    'rounded' => 'rounded-lg',
])

{{-- KPI stat-tile grid skeleton, mirroring an analytics Overview tile grid. --}}
<div class="grid {{ $grid }} gap-px overflow-hidden {{ $rounded }} bg-neutral-200 dark:bg-white/[0.07]">
    @for ($i = 0; $i < (int) $count; $i++)
        <div class="flex flex-col bg-white px-4 py-3 dark:bg-base">
            <x-skeleton class="h-2.5 w-16" />
            <x-skeleton class="mt-2 h-6 w-20" />
            <div class="mt-auto pt-3">
                <x-skeleton class="h-8 w-full rounded" />
            </div>
        </div>
    @endfor
</div>
