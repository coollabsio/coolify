@props([
    'panelClass' => '',
    'role' => 'listbox',
    'multiselectable' => false,
])

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <div @click="open = !open">
        {{ $trigger }}
    </div>

    <div x-show="open" x-cloak
        class="listbox-panel absolute top-full! right-0! left-auto! mt-1! {{ $panelClass }}" role="{{ $role }}"
        @if ($multiselectable) aria-multiselectable="true" @endif>
        {{ $slot }}
    </div>
</div>
