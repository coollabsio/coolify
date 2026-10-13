@props([
    'panelClass' => '',
    'role' => 'listbox',
    'multiselectable' => false,
])

<div class="relative" x-data="{
    open: false,
    panelStyle: 'position: fixed; min-width: 0; visibility: hidden;',
    toggle() {
        if (this.open) {
            this.open = false;
            return;
        }

        this.panelStyle = 'position: fixed; min-width: 0; visibility: hidden;';
        this.open = true;
        this.$nextTick(() => this.updatePosition());
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

        this.panelStyle = `position: fixed; left: ${left}px; top: ${top}px; min-width: 0;`;
    }
}" @click.outside="open = false" @keydown.escape.window="open = false"
    x-on:resize.window="if (open) updatePosition()" x-on:scroll.window="if (open) updatePosition()">
    <div x-ref="trigger" @click="toggle()">
        {{ $trigger }}
    </div>

    <div x-ref="panel" x-show="open" x-cloak :style="panelStyle"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.98]"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.98]"
        class="listbox-panel origin-top fixed! right-auto! bottom-auto! z-[90]! mt-0! {{ $panelClass }}" role="{{ $role }}"
        @if ($multiselectable) aria-multiselectable="true" @endif>
        {{ $slot }}
    </div>
</div>
