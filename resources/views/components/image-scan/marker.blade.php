@props([
    'count' => 0,
    'label' => '',
])

@if ((int) $count > 0)
    <span role="img" aria-label="{{ $label }}" data-tooltip="{{ $label }}"
        {{ $attributes->class(['inline-flex shrink-0 cursor-help items-center gap-1 text-xs font-medium leading-none text-neutral-700 dark:text-white']) }}>
        <x-reicon name="shield-alert" class="size-4 shrink-0" />
        <span aria-hidden="true">{{ $count }}</span>
    </span>
@endif
