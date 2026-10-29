@props([
    'rows' => 4,
    'label' => 'Loading',
])

@php
    // Vary the title widths so rows read as content, not a solid block.
    $widths = ['w-2/5', 'w-1/3', 'w-1/2', 'w-1/4'];
@endphp

{{-- List rows with a leading icon tile, a title, a meta line, and a trailing button. --}}
<div role="status" {{ $attributes->class(['flex flex-col']) }}>
    <span class="sr-only">{{ $label }}</span>
    @for ($i = 0; $i < (int) $rows; $i++)
        <div aria-hidden="true"
            class="flex items-center gap-3 border-b border-neutral-200 px-4 py-3.5 last:border-b-0 dark:border-white/[0.07]">
            <x-skeleton class="size-9 shrink-0 rounded-lg" />
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <x-skeleton class="h-3 {{ $widths[$i % count($widths)] }} rounded-full" />
                <x-skeleton class="h-2.5 w-32 rounded-full" />
            </div>
            <x-skeleton class="hidden h-8 w-24 shrink-0 rounded-lg sm:block" />
        </div>
    @endfor
</div>
