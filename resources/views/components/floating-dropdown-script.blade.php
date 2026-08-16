<script>
    window.floatingDropdown = function floatingDropdown(config, state = {}) {
        state.open = false;
        state.positioned = false;

        state.toggle = function toggle() {
            this.open = !this.open;
            this.positioned = false;

            if (this.open && config.portal) {
                this.$nextTick(() => this.schedulePosition());
            }
        };

        state.close = function close() {
            this.open = false;
            this.positioned = false;
        };

        state.schedulePosition = function schedulePosition(panel = null) {
            window.requestAnimationFrame(() => this.positionPanel(panel));
        };

        state.positionPanel = function positionPanel(panel = null) {
            const trigger = this.$refs.trigger;
            panel ??= document.getElementById(config.panelId);

            if (!trigger || !panel) {
                return;
            }

            const gap = 4;
            const edge = 12;
            const availableWidth = window.innerWidth - (edge * 2);
            const availableHeight = window.innerHeight - (edge * 2);
            const triggerRect = trigger.getBoundingClientRect();
            const desiredWidth = config.matchTriggerWidth
                ? triggerRect.width
                : panel.offsetWidth;
            const panelWidth = Math.min(desiredWidth, availableWidth);
            const panelHeight = Math.min(panel.scrollHeight, config.maxHeight ?? availableHeight);
            const fitsBelow = window.innerHeight - triggerRect.bottom - gap >= panelHeight;
            const top = fitsBelow
                ? triggerRect.bottom + gap
                : Math.max(edge, triggerRect.top - gap - panelHeight);
            const alignedLeft = config.align === 'right'
                ? triggerRect.right - panelWidth
                : triggerRect.left;
            const left = window.innerWidth < 768
                ? (window.innerWidth - panelWidth) / 2
                : Math.min(Math.max(edge, alignedLeft), window.innerWidth - panelWidth - edge);

            panel.style.top = `${top}px`;
            panel.style.left = `${left}px`;
            panel.style.width = `${panelWidth}px`;
            panel.style.maxWidth = `${availableWidth}px`;

            if (config.matchTriggerWidth) {
                panel.style.minWidth = `${triggerRect.width}px`;
            } else {
                panel.style.maxHeight = `${availableHeight}px`;
            }

            this.positioned = true;
        };

        return state;
    };
</script>
