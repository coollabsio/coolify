@props([
    'value' => null,
    'resolve' => null,
    'label' => 'Copy to clipboard',
])

@php
    $valueExpression = $resolve ?? \Illuminate\Support\Js::from($value);
@endphp

<button type="button" title="{{ $label }}" aria-label="{{ $label }}"
    {{ $attributes->class(['icon-button shrink-0']) }} @disabled($resolve === null && blank($value))
    x-data="copyButton" @click="copy(await ({{ $valueExpression }}))">
    <x-reicon name="copy" x-show="!copied" class="size-3.5" />
    <x-reicon name="check" x-cloak x-show="copied" class="size-3.5 text-success" />
</button>
