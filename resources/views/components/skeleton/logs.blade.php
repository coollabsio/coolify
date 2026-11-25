@php
    // Vary the line widths so rows read as log output, not a solid block.
    $widths = ['w-3/5', 'w-2/5', 'w-4/5', 'w-1/2', 'w-3/4', 'w-1/3', 'w-2/3', 'w-1/2'];
@endphp

{{-- Runtime log panel skeleton: header, toolbar, and log lines. --}}
<div role="status" {{ $attributes->class(['runtime-log-shell overflow-hidden']) }}>
    <span class="sr-only">Loading containers</span>
    <div class="flex min-h-11 items-center gap-2 px-3.5">
        <x-skeleton class="size-4 rounded" />
        <x-skeleton class="h-3 w-48 rounded-full" />
    </div>
    <div class="flex items-center gap-2 border-y border-neutral-200 px-3.5 py-2.5 dark:border-white/[0.07]">
        @for ($i = 0; $i < 4; $i++)
            <x-skeleton class="size-6" />
        @endfor
        <x-skeleton class="ml-auto hidden h-7 w-56 rounded-lg sm:block" />
        <x-skeleton class="hidden h-7 w-32 rounded-lg sm:block" />
        <x-skeleton class="h-7 w-20 rounded-lg" />
        <x-skeleton class="h-7 w-20 rounded-lg" />
    </div>
    <div class="flex flex-col gap-2.5 px-4 py-4">
        @foreach ($widths as $width)
            <div class="flex items-center gap-3">
                <x-skeleton class="hidden h-3 w-36 shrink-0 rounded-full sm:block" />
                <x-skeleton class="h-3 {{ $width }} rounded-full" />
            </div>
        @endforeach
    </div>
</div>
