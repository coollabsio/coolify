@props([
    'count' => 6,
    'label' => 'Loading',
])

{{-- Card grid skeleton: an icon tile, a title, and two description lines per card. --}}
<div role="status" {{ $attributes->class(['grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3']) }}>
    <span class="sr-only">{{ $label }}</span>
    @for ($i = 0; $i < (int) $count; $i++)
        <div aria-hidden="true"
            class="flex min-h-48 flex-col rounded-xl border border-neutral-200 p-4 dark:border-white/[0.08]">
            <div class="flex items-center gap-3">
                <x-skeleton class="size-11 shrink-0 rounded-xl" />
                <div class="flex flex-1 flex-col gap-2">
                    <x-skeleton class="h-3 w-1/2 rounded-full" />
                    <x-skeleton class="h-2.5 w-1/4 rounded-full" />
                </div>
            </div>
            <x-skeleton class="mt-4 h-2.5 w-full rounded-full" />
            <x-skeleton class="mt-2 h-2.5 w-3/4 rounded-full" />
        </div>
    @endfor
</div>
