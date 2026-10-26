@props([
    'count' => null,
    'label' => '',
])

{{-- Count pill at the end of a sidebar item. Renders nothing for null or 0. --}}
@if ((int) $count > 0)
    <span data-tooltip="{{ $count }} {{ $label }}"
        {{ $attributes->class(['ml-auto shrink-0 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[10px] leading-none font-medium tabular-nums text-neutral-600 dark:bg-white/[0.07] dark:text-fg-dim']) }}>
        <span aria-hidden="true">{{ $count }}</span>
        <span class="sr-only">{{ $count }} {{ $label }}</span>
    </span>
@endif
