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
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="size-4 stroke-current"
                aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        @endisset
    </button>
    <template x-teleport="body">
        <div x-ref="popup" x-show="open" x-cloak :style="style"
            class="fixed z-[9999] w-max max-w-[min(20rem,calc(100vw-2rem))] rounded-sm border border-neutral-300 bg-neutral-200 text-xs text-neutral-700 shadow-lg whitespace-normal break-words dark:border-coolgray-500 dark:bg-coolgray-400 dark:text-neutral-300"
            @click.stop>
            <div class="p-4">
                {!! $helper !!}
            </div>
        </div>
    </template>
</div>
