<div x-data="{
    open: false,
    pinned: false,
    style: '',
    show(pinned = false) {
        this.pinned = pinned;
        this.open = true;
        this.$nextTick(() => this.position());
    },
    hide() {
        if (!this.pinned) {
            this.open = false;
        }
    },
    close() {
        this.pinned = false;
        this.open = false;
    },
    position() {
        const trigger = this.$refs.trigger;
        const popup = this.$refs.popup;

        if (!trigger || !popup) {
            return;
        }

        const triggerRect = trigger.getBoundingClientRect();
        const popupRect = popup.getBoundingClientRect();
        const padding = 8;
        let top = triggerRect.bottom + padding;
        let left = triggerRect.right - popupRect.width;

        if (top + popupRect.height > window.innerHeight - padding) {
            top = triggerRect.top - popupRect.height - padding;
        }

        left = Math.min(Math.max(padding, left), window.innerWidth - popupRect.width - padding);
        top = Math.max(padding, top);

        this.style = `top: ${top}px; left: ${left}px;`;
    }
}" @click.outside="close" @keydown.window.escape="close" @resize.window="open && position()" @scroll.window="open && position()"
    {{ $attributes->merge(['class' => 'relative z-10 inline-block align-middle']) }}>
    {{-- button (not div) so label-for associations do not steal the click on mobile --}}
    <button type="button" x-ref="trigger"
        class="info-helper relative z-10 inline-flex size-4 shrink-0 items-center justify-center border-0 bg-transparent p-0 leading-none"
        aria-label="More information" @mouseenter="show(false)" @mouseleave="hide"
        @click.prevent.stop="open && pinned ? close() : show(true)">
        @isset($icon)
            {{ $icon }}
        @else
            <x-reicon name="info-circle"
                class="size-4 text-neutral-400 transition-colors hover:text-neutral-600 dark:text-fg-faint dark:hover:text-fg-dim"
                aria-hidden="true" />
        @endisset
    </button>
    <template x-teleport="body">
        <div x-ref="popup" x-show="open" x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 translate-y-0.5"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            :style="style"
            class="info-helper-popup fixed z-[9999] w-max max-w-[min(20rem,calc(100vw-2rem))]"
            @click.stop>
            <div class="px-3 py-2.5 text-[13px] leading-5">
                {!! $helper !!}
            </div>
        </div>
    </template>
</div>
