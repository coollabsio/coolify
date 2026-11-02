{{--
    One sortable column header for a traffic list. Expects an enclosing Alpine
    scope from `trafficTable()` (resources/js/traffic-table.js).

    @param string  $column  key in the rows' `data-row` JSON
    @param string  $label   header text
    @param ?string $class   width and responsive classes of the column; a column that
                            hides on small screens passes `hidden <breakpoint>:inline-flex`
    @param ?string $title   tooltip
    @param ?bool   $text    true for a text column: left-aligned, first sort ascending
    @param ?string $dir     first sort direction, when it differs from the default of the alignment
--}}
@php
    $text = $text ?? false;
    $firstDir = $dir ?? ($text ? 'asc' : 'desc');
    $class = $class ?? '';
    // `hidden` and `inline-flex` on one element conflict, so add the display only when the caller sets none.
    $display = str_contains($class, 'hidden') ? '' : 'inline-flex';
@endphp
<button type="button" @click="sortBy('{{ $column }}', '{{ $firstDir }}')"
    title="{{ $title ?? 'Sort by '.strtolower($label) }}"
    :aria-label="'Sort by {{ strtolower($label) }}' + (sorted('{{ $column }}') ? ', sorted ' + (sorted('{{ $column }}') === 'asc' ? 'ascending' : 'descending') : '')"
    class="group {{ $display }} items-center gap-1 whitespace-nowrap rounded-sm transition-colors hover:text-black focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:hover:text-fg {{ $text ? 'justify-start text-left' : 'flex-row-reverse justify-start text-right' }} {{ $class }}"
    :class="sorted('{{ $column }}') ? 'text-black dark:text-fg' : ''">
    <span>{{ $label }}</span>
    <svg class="size-3 shrink-0 transition-[opacity,transform]" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2.5" aria-hidden="true"
        :class="[sorted('{{ $column }}') ? 'opacity-100' : 'opacity-0 group-hover:opacity-50', sorted('{{ $column }}') === 'asc' ? 'rotate-180' : '']">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
    </svg>
</button>
