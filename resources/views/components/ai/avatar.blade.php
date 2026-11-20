@props([
    'size' => 'md',
    'variant' => 'solid',
    'online' => false,
])

{{-- Assistant identity glyph, shared by the /assistant page, the floating widget,
     and the in-thread message labels. `solid` is the brand avatar used in headers
     and empty states; `tint` is the quiet inline label next to a message. --}}
@php
    $box = match ($size) {
        'xs' => 'size-4 rounded',
        'sm' => 'size-6 rounded-lg',
        'lg' => 'size-11 rounded-2xl',
        default => 'size-7 rounded-lg',
    };
    $glyph = match ($size) {
        'xs' => 'size-2.5',
        'sm' => 'size-3.5',
        'lg' => 'size-5',
        default => 'size-4',
    };
    $skin = $variant === 'tint'
        ? 'bg-coollabs/10 text-coollabs dark:bg-warning/10 dark:text-warning'
        : 'bg-coollabs text-white';
@endphp

<span {{ $attributes->class("relative flex shrink-0 items-center justify-center $box $skin") }}>
    <x-reicon name="feedback" class="{{ $glyph }}" />
    @if ($online)
        <span
            class="absolute -bottom-0.5 -right-0.5 size-2.5 rounded-full bg-success ring-2 ring-[var(--coollabs-elevated)]"></span>
    @endif
</span>
