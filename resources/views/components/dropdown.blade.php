<div x-data="{
    dropdownOpen: false,
    panelStyles: '',
    open() {
        this.dropdownOpen = true;
        this.updatePanelPosition();
    },
    close() {
        this.dropdownOpen = false;
    },
    updatePanelPosition() {
        if (window.innerWidth >= 768) {
            this.panelStyles = '';

            return;
        }

        this.$nextTick(() => {
            const triggerRect = this.$refs.trigger.getBoundingClientRect();
            const panelRect = this.$refs.panel.getBoundingClientRect();
            const viewportPadding = 8;
            let left = triggerRect.left;

            if ((left + panelRect.width + viewportPadding) > window.innerWidth) {
                left = window.innerWidth - panelRect.width - viewportPadding;
            }

            left = Math.max(viewportPadding, left);

            let top = triggerRect.bottom + 4;
            const maxTop = window.innerHeight - panelRect.height - viewportPadding;

            if (top > maxTop) {
                top = Math.max(viewportPadding, triggerRect.top - panelRect.height - viewportPadding);
            }

            this.panelStyles = `position: fixed; left: ${left}px; top: ${top}px;`;
        });
    }
}" class="relative" @click.outside="close()" x-on:resize.window="if (dropdownOpen) updatePanelPosition()">
    <button x-ref="trigger" @click="dropdownOpen ? close() : open()"
        class="inline-flex items-center justify-start pr-8 transition-colors focus:outline-hidden disabled:opacity-50 disabled:pointer-events-none">
        <span class="flex flex-col items-start h-full leading-none">
            {{ $title }}
        </span>
        <svg class="absolute right-0 w-4 h-4 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
            stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
        </svg>
    </button>

    <div x-ref="panel" x-show="dropdownOpen" @click.away="close()" x-transition:enter="ease-out duration-200"
        x-transition:enter-start="-translate-y-2" x-transition:enter-end="translate-y-0"
        :style="panelStyles" class="absolute top-full z-50 mt-1 min-w-max max-w-[calc(100vw-1rem)] md:top-0 md:mt-6" x-cloak>
        <div
            class="border border-neutral-300 bg-white p-1 shadow-sm dark:border-coolgray-300 dark:bg-coolgray-200">
            {{ $slot }}
        </div>
    </div>
</div>
