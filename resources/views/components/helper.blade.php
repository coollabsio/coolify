@props([
    'helper',
    'label' => 'More information',
])

<div x-data="{
    open: false,
    hideTimer: null,
    style: '',
    visualViewport: null,
    reposition: null,
    init() {
        this.visualViewport = window.visualViewport;
        this.reposition = () => this.open && this.position();
        this.visualViewport?.addEventListener('resize', this.reposition);
        this.visualViewport?.addEventListener('scroll', this.reposition);
    },
    destroy() {
        this.visualViewport?.removeEventListener('resize', this.reposition);
        this.visualViewport?.removeEventListener('scroll', this.reposition);
    },
    show() {
        this.cancelHide();
        this.open = true;
        this.$nextTick(() => this.position());
    },
    hide() {
        this.cancelHide();
        this.hideTimer = setTimeout(() => {
            this.open = false;
        }, 150);
    },
    cancelHide() {
        if (this.hideTimer) {
            clearTimeout(this.hideTimer);
            this.hideTimer = null;
        }
    },
    close() {
        this.cancelHide();
        this.open = false;
    },
    closeWhenFocusLeaves() {
        this.$nextTick(() => {
            const activeElement = document.activeElement;
            if (!this.$refs.trigger?.contains(activeElement) && !this.$refs.popup?.contains(activeElement)) {
                this.close();
            }
        });
    },
    closeWhenPointerIsOutside(event) {
        if (!this.open || this.$refs.trigger?.contains(event.target) || this.$refs.popup?.contains(event.target)) {
            return;
        }

        this.close();
    },
    position() {
        const trigger = this.$refs.trigger;
        const popup = this.$refs.popup;

        if (!trigger || !popup) {
            return;
        }

        const padding = 8;
        const viewport = window.visualViewport;
        const viewportLeft = viewport?.offsetLeft ?? 0;
        const viewportTop = viewport?.offsetTop ?? 0;
        const viewportWidth = viewport?.width ?? window.innerWidth;
        const viewportHeight = viewport?.height ?? window.innerHeight;
        const viewportRight = viewportLeft + viewportWidth;
        const viewportBottom = viewportTop + viewportHeight;
        const availableWidth = Math.max(0, viewportWidth - padding * 2);
        const availableHeight = Math.max(0, viewportHeight - padding * 2);
        const maxPopupWidth = 320;
        const popupWidth = Math.min(maxPopupWidth, availableWidth);

        this.style = `max-width: ${popupWidth}px; max-height: ${availableHeight}px; overflow-y: auto;`;

        const triggerRect = trigger.getBoundingClientRect();
        const popupRect = popup.getBoundingClientRect();
        let top = triggerRect.bottom + padding;
        let left = triggerRect.right - popupRect.width;

        if (top + popupRect.height > viewportBottom - padding) {
            top = triggerRect.top - popupRect.height - padding;
        }

        left = Math.min(Math.max(viewportLeft + padding, left), viewportRight - popupRect.width - padding);
        top = Math.min(Math.max(viewportTop + padding, top), viewportBottom - popupRect.height - padding);

        this.style += ` top: ${top}px; left: ${left}px;`;
    }
}" @pointerdown.window="closeWhenPointerIsOutside($event)" @keydown.window.escape="close"
    @resize.window="open && position()" @scroll.window="open && position()"
    {{ $attributes->merge(['class' => 'relative inline-flex align-middle']) }}>
    {{-- button (not div) so label-for associations do not steal the click on mobile --}}
    <button type="button" x-ref="trigger" data-icon-tooltip-ignore
        @class([
            'info-helper relative inline-flex shrink-0 items-center justify-center border-0 bg-transparent p-0 leading-none',
            'size-3.5' => ! isset($trigger),
        ])
        aria-label="{{ $label }}" :aria-describedby="open ? $id('helper-popup') : null"
        @mouseenter="show()" @mouseleave="hide" @focus="show()" @blur="closeWhenFocusLeaves()"
        @click.prevent.stop="show()">
        @isset($trigger)
            {{ $trigger }}
        @elseif (isset($icon))
            {{ $icon }}
        @else
            <x-reicon name="info-circle"
                class="size-3.5 text-neutral-400 transition-colors hover:text-neutral-600 dark:text-fg-faint dark:hover:text-fg-dim"
                aria-hidden="true" />
        @endisset
    </button>
    <div x-ref="popup" x-show="open" x-cloak :id="$id('helper-popup')" role="tooltip"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 translate-y-0.5"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        :style="style"
        class="info-helper-popup fixed z-[10000] w-max max-w-[min(20rem,calc(100vw-2rem))] whitespace-normal"
        @mouseenter="cancelHide()" @mouseleave="hide()" @focusout="closeWhenFocusLeaves()" @click.stop>
        <div class="px-3 py-2.5 text-[13px] leading-5">
            {!! $helper !!}
        </div>
    </div>
</div>
