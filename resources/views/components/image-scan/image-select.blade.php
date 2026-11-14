@props([
    // The image refs of the resource. The picker renders only for more than one.
    'options' => [],
    // The selected refs; the picker shows one, or "All images".
    'selected' => [],
    // The Livewire method that takes one ref, or '' for every image.
    'action',
])

@php
    $current = count($selected) === 1 ? $selected[0] : null;
@endphp

@if (count($options) > 1)
    <x-table.dropdown panel-class="min-w-56! max-w-80! p-1!">
        <x-slot:trigger>
            <button type="button" aria-haspopup="listbox" :aria-expanded="open"
                @class(['button max-w-64 min-w-0 normal-case!', 'button-highlighted' => $current !== null])>
                <x-reicon name="layers" class="size-3.5 shrink-0" />
                <span class="truncate" title="{{ $current ?? 'All images' }}">{{ $current ?? 'All images' }}</span>
                <x-reicon name="chevron-down" class="size-3.5 shrink-0" />
            </button>
        </x-slot:trigger>
        @foreach (['' => 'All images', ...array_combine($options, $options)] as $value => $label)
            <button type="button" class="listbox-option" role="option"
                aria-selected="{{ ($current ?? '') === $value ? 'true' : 'false' }}"
                wire:click="{{ $action }}({{ \Illuminate\Support\Js::from((string) $value) }})" @click="close()">
                <span @class(['truncate', 'font-mono text-[12px]' => $value !== '']) title="{{ $label }}">{{ $label }}</span>
                @if (($current ?? '') === $value)
                    <x-reicon name="check" class="size-3.5 shrink-0" />
                @endif
            </button>
        @endforeach
    </x-table.dropdown>
@endif
