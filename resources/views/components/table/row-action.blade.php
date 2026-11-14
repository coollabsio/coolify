{{-- One item of <x-table.row-actions>. Renders a link (with wire:navigate when enabled) when href is set. --}}
@props([
    'href' => null,
])

@if ($href)
    <a href="{{ $href }}" {{ wireNavigate() }} role="menuitem" {{ $attributes->class('listbox-option justify-start!') }}>{{ $slot }}</a>
@else
    <button type="button" role="menuitem" {{ $attributes->class('listbox-option justify-start!') }}>{{ $slot }}</button>
@endif
