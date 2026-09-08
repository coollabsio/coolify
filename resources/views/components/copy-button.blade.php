@props([
    'value' => null,
    'resolve' => null,
    'label' => 'Copy to clipboard',
])

@php
    $valueExpression = $resolve ?? \Illuminate\Support\Js::from($value);
@endphp

<button type="button" title="{{ $label }}" aria-label="{{ $label }}"
    {{ $attributes->class(['icon-button group shrink-0']) }} @disabled($resolve === null && blank($value))
    x-data="copyButton" @click="copy(await ({{ $valueExpression }}))">
    <span class="inline-flex transition-transform duration-150 ease-out group-active:scale-75">
        <x-reicon name="copy" x-show="!copied" class="size-3.5" />
        <x-reicon name="check" x-cloak x-show="copied" class="size-3.5 text-success"
            x-transition:enter="transition-transform duration-200 ease-out"
            x-transition:enter-start="scale-50" x-transition:enter-end="scale-100" />
    </span>
</button>
