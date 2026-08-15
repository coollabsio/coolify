@props([
    'panelClass' => '',
    'role' => 'listbox',
    'multiselectable' => false,
])

@php($panelId = 'table-dropdown-'.uniqid())

<div class="relative" x-data="{
    open: false,
    positioned: false,
    toggle() {
        this.open = !this.open;
        this.positioned = false;

        if (this.open) {
            this.$nextTick(() => requestAnimationFrame(() => this.positionPanel()));
        }
    },
    close() {
        this.open = false;
        this.positioned = false;
    },
    positionPanel() {
        const trigger = this.$refs.trigger;
        const panel = document.getElementById(@js($panelId));

        if (!trigger || !panel) return;

        const gap = 4;
        const edge = 12;
        const triggerRect = trigger.getBoundingClientRect();
        const panelWidth = Math.min(panel.offsetWidth, window.innerWidth - (edge * 2));
        const panelHeight = Math.min(panel.scrollHeight, window.innerHeight - (edge * 2));
        const fitsBelow = window.innerHeight - triggerRect.bottom - gap >= panelHeight;
        const top = fitsBelow
            ? triggerRect.bottom + gap
            : Math.max(edge, triggerRect.top - gap - panelHeight);
        const left = window.innerWidth < 768
            ? (window.innerWidth - panelWidth) / 2
            : Math.min(
                Math.max(edge, triggerRect.right - panelWidth),
                window.innerWidth - panelWidth - edge,
            );

        panel.style.top = `${top}px`;
        panel.style.left = `${left}px`;
        panel.style.width = `${panelWidth}px`;
        panel.style.maxWidth = `${window.innerWidth - (edge * 2)}px`;
        panel.style.maxHeight = `${window.innerHeight - (edge * 2)}px`;
        this.positioned = true;
    }
}" @click.outside="close()" @keydown.escape.window="close()"
    @resize.window="open && positionPanel()" @scroll.window="open && positionPanel()">
    <div x-ref="trigger" @click="toggle()">
        {{ $trigger }}
    </div>

    <template x-teleport="body">
        <div id="{{ $panelId }}" x-show="open" x-cloak
            class="listbox-panel floating-dropdown-panel z-[9999]! {{ $panelClass }}" style="position: fixed; visibility: hidden"
            :style="{ visibility: positioned ? 'visible' : 'hidden' }"
            x-effect="if (open) requestAnimationFrame(() => positionPanel())" role="{{ $role }}"
            @if ($multiselectable) aria-multiselectable="true" @endif>
            {{ $slot }}
        </div>
    </template>
</div>
