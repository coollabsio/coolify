@props([
    'panelClass' => '',
    'role' => 'listbox',
    'multiselectable' => false,
])

<div class="relative" x-data="{
    open: false,
    panelStyle: 'display: none;',
    toggle() {
        this.open = !this.open;

        if (this.open) {
            this.$nextTick(() => this.updatePosition());
        }
    },
    updatePosition() {
        const trigger = this.$refs.trigger.getBoundingClientRect();
        const panel = this.$refs.panel.getBoundingClientRect();
        const viewportPadding = 8;
        const left = Math.max(viewportPadding, Math.min(trigger.right - panel.width, window.innerWidth - panel.width - viewportPadding));
        const spaceBelow = window.innerHeight - trigger.bottom - viewportPadding;
        const top = spaceBelow >= panel.height
            ? trigger.bottom + 4
            : Math.max(viewportPadding, trigger.top - panel.height - 4);

        this.panelStyle = `position: fixed; left: ${left}px; top: ${top}px;`;
    }
}" @click.outside="open = false" @keydown.escape.window="open = false"
    x-on:resize.window="if (open) updatePosition()" x-on:scroll.window="if (open) updatePosition()">
    <div x-ref="trigger" @click="toggle()">
        {{ $trigger }}
    </div>

    <div x-ref="panel" x-show="open" x-cloak :style="panelStyle"
        class="listbox-panel fixed! top-auto! right-auto! bottom-auto! z-[90]! mt-0! {{ $panelClass }}" role="{{ $role }}"
        @if ($multiselectable) aria-multiselectable="true" @endif>
        {{ $slot }}
    </div>
</div>
