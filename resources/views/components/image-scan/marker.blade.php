@props([
    'count' => 0,
    'label' => '',
    // An Alpine expression of a `{count, label}` object, for lists rendered by Alpine.
    'bind' => null,
])

@php
    $classes = 'inline-flex shrink-0 cursor-help items-center gap-1 text-xs font-medium leading-none text-neutral-700 dark:text-white';
@endphp

@if ($bind)
    <template x-if="{{ $bind }}?.count > 0">
        <span role="img" x-bind:aria-label="{{ $bind }}.label" x-bind:data-tooltip="{{ $bind }}.label"
            {{ $attributes->class([$classes]) }}>
            <x-reicon name="shield-alert" class="size-4 shrink-0" />
            <span aria-hidden="true" x-text="{{ $bind }}.count"></span>
        </span>
    </template>
@elseif ((int) $count > 0)
    <span role="img" aria-label="{{ $label }}" data-tooltip="{{ $label }}" {{ $attributes->class([$classes]) }}>
        <x-reicon name="shield-alert" class="size-4 shrink-0" />
        <span aria-hidden="true">{{ $count }}</span>
    </span>
@endif
