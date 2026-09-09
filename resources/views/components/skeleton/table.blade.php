@props([
    'rows' => 6,
    'flush' => false,
])

@php
    // Vary the leading-column widths so rows read as content, not a solid block.
    $widths = ['w-3/5', 'w-2/5', 'w-1/2', 'w-3/4', 'w-1/3', 'w-2/3'];
@endphp

@if ($flush)
    {{-- Edge-to-edge list rows with dividers, matching a `flush` settings-section list. --}}
    <div class="flex flex-col">
        @for ($i = 0; $i < (int) $rows; $i++)
            <div class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                <x-skeleton class="h-3 {{ $widths[$i % count($widths)] }} rounded-full" />
                <x-skeleton class="ml-auto h-3 w-10 shrink-0 rounded-full" />
            </div>
        @endfor
    </div>
@else
    {{-- Padded list rows: a label column and a trailing value per row. --}}
    <div class="flex flex-col gap-2.5">
        @for ($i = 0; $i < (int) $rows; $i++)
            <div class="flex items-center justify-between gap-4">
                <x-skeleton class="h-3 {{ $widths[$i % count($widths)] }} rounded-full" />
                <x-skeleton class="h-3 w-10 shrink-0 rounded-full" />
            </div>
        @endfor
    </div>
@endif
