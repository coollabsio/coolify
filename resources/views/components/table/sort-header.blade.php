{{--
    A column header that sorts its table. The first click sorts by `first`, the next
    click flips the direction. An arrow shows the active direction.

    Alpine tables set `model` (the sort variable in the enclosing x-data scope) and an
    optional `then` expression that runs after the change (for example `page = 1`).
    Livewire tables set `action` (a component method that takes the sort value) and
    `current` (the current server-side sort value). While that request runs, the
    clicked header shows a spinner.

    @param string  $label
    @param string  $asc     sort value for ascending order
    @param string  $desc    sort value for descending order
    @param string  $first   'asc' or 'desc': the direction of the first click
--}}
@props([
    'label',
    'asc',
    'desc',
    'first' => 'asc',
    'model' => 'sortBy',
    'then' => null,
    'action' => null,
    'current' => null,
])

@php
    $firstValue = $first === 'desc' ? $desc : $asc;
    $otherValue = $first === 'desc' ? $asc : $desc;
    $ariaBase = 'Sort by '.strtolower($label);
@endphp

@if ($action)
    @php
        $direction = match ($current) {
            $asc => 'asc',
            $desc => 'desc',
            default => null,
        };
        $nextValue = $current === $firstValue ? $otherValue : $firstValue;
        $call = $action.'('.\Illuminate\Support\Js::from($nextValue).')';
    @endphp
    <button type="button" wire:click="{{ $call }}" wire:loading.attr="aria-busy" wire:target="{{ $call }}"
        aria-label="{{ $ariaBase }}{{ $direction ? ', sorted '.($direction === 'asc' ? 'ascending' : 'descending') : '' }}"
        {{ $attributes->class(['table-sort-header group', 'is-sorted' => $direction]) }}>
        <span class="truncate">{{ $label }}</span>
        <svg @class(['table-sort-header-icon', 'rotate-180' => $direction === 'desc', 'is-visible' => $direction]) viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"
            wire:loading.remove wire:target="{{ $call }}">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 15l6-6 6 6" />
        </svg>
        <svg class="size-3 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"
            wire:loading wire:target="{{ $call }}">
            <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
    </button>
@else
    <button type="button"
        x-on:click="{{ $model }} = ({{ $model }} === @js($firstValue) ? @js($otherValue) : @js($firstValue)){{ $then ? '; '.$then : '' }}"
        x-bind:aria-label="@js($ariaBase) + ({{ $model }} === @js($asc) ? ', sorted ascending' : ({{ $model }} === @js($desc) ? ', sorted descending' : ''))"
        x-bind:class="{ 'is-sorted': [@js($asc), @js($desc)].includes({{ $model }}) }"
        {{ $attributes->class(['table-sort-header group']) }}>
        <span class="truncate">{{ $label }}</span>
        <svg class="table-sort-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
            aria-hidden="true"
            x-bind:class="{ 'is-visible': [@js($asc), @js($desc)].includes({{ $model }}), 'rotate-180': {{ $model }} === @js($desc) }">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 15l6-6 6 6" />
        </svg>
    </button>
@endif
