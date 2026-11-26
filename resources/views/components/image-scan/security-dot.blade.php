@props([
    // A SecurityStatusType value. Only update_now (red) and update_available (yellow) get a dot.
    'status' => null,
])

@php
    $dot = match ($status) {
        'update_now' => ['class' => 'bg-error', 'label' => 'Security: update now'],
        'update_available' => ['class' => 'bg-warning', 'label' => 'Security: security update'],
        default => null,
    };
@endphp

@if ($dot !== null)
    <span role="img" aria-label="{{ $dot['label'] }}" data-tooltip="{{ $dot['label'] }}"
        {{ $attributes->class(['ml-auto size-2 shrink-0 rounded-full', $dot['class']]) }}></span>
@endif
