@props([
    'panelClass' => '',
    'role' => 'listbox',
    'multiselectable' => false,
])

@php($panelId = 'table-dropdown-'.uniqid())

<div class="relative"
    x-data="floatingDropdown({ panelId: @js($panelId), portal: true, align: 'right', matchTriggerWidth: false })"
    @click.outside="close()" @keydown.escape.window="close()"
    @resize.window="open && schedulePosition()" @scroll.window="open && schedulePosition()">
    <div x-ref="trigger" @click="toggle()">
        {{ $trigger }}
    </div>

    <div id="{{ $panelId }}" x-show="open" x-cloak
        class="listbox-panel floating-dropdown-panel z-[9999]! {{ $panelClass }}" style="position: fixed; visibility: hidden"
        :style="{ visibility: positioned ? 'visible' : 'hidden' }"
        x-effect="if (open) schedulePosition($el)" role="{{ $role }}"
        @if ($multiselectable) aria-multiselectable="true" @endif>
        {{ $slot }}
    </div>
</div>
