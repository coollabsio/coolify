@props([
    // From VulnerabilityMarkers::marker(): update_now (error color) or no_fix (neutral). Other statuses render nothing.
    'status' => null,
    'text' => '',
    'label' => '',
    // The primary action sentence, when there is one.
    'tooltip' => null,
    // An Alpine expression of a `{status, text, label, tooltip}` object, for lists rendered by Alpine.
    'bind' => null,
])

@php
    $classes = 'inline-flex shrink-0 items-center gap-1 text-xs font-medium leading-none';
    $tones = ['update_now' => 'text-error', 'no_fix' => 'text-neutral-600 dark:text-fg-dim'];
@endphp

@if ($bind)
    <template x-if="{{ $bind }}?.text">
        <span role="img" x-bind:aria-label="{{ $bind }}.label" x-bind:data-tooltip="{{ $bind }}.tooltip"
            x-bind:class="({{ $bind }}.status === 'update_now' ? 'text-error' : 'text-neutral-600 dark:text-fg-dim') + ({{ $bind }}.tooltip ? ' cursor-help' : '')"
            {{ $attributes->class([$classes]) }}>
            <x-reicon name="shield-alert" class="size-4 shrink-0" />
            <span aria-hidden="true" x-text="{{ $bind }}.text"></span>
        </span>
    </template>
@elseif (isset($tones[$status]) && filled($text))
    <span role="img" aria-label="{{ $label }}" @if (filled($tooltip)) data-tooltip="{{ $tooltip }}" @endif
        {{ $attributes->class([$classes, $tones[$status], 'cursor-help' => filled($tooltip)]) }}>
        <x-reicon name="shield-alert" class="size-4 shrink-0" />
        <span aria-hidden="true">{{ $text }}</span>
    </span>
@endif
