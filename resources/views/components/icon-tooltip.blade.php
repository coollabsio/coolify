<div x-data="{
    visible: false,
    positioned: false,
    text: '',
    x: 0,
    y: 0,
    below: false,
    activeTarget: null,
    isIconAction(target) {
        if (target.matches('[data-icon-tooltip-ignore]')) return false;
        if (target.matches('[data-tooltip], .icon-button')) return true;
        return target.hasAttribute('aria-label')
            && target.childElementCount === 1
            && target.firstElementChild?.matches('svg');
    },
    prepare(root) {
        root.querySelectorAll?.('button[title], a[title], [data-tooltip]').forEach((target) => {
            if (!this.isIconAction(target)) return;
            target.dataset.iconTooltip = target.dataset.tooltip || target.getAttribute('aria-label') || target.title;
            if (!target.getAttribute('aria-label')) target.setAttribute('aria-label', target.dataset.iconTooltip);
            target.removeAttribute('title');
        });
    },
    findTarget(event) {
        const target = event.target.closest('button, a, [data-tooltip]');
        if (target?.matches('.menu-item, .menu-subitem') && target.closest('nav')) return null;
        return target && this.isIconAction(target) ? target : null;
    },
    show(event) {
        const target = this.findTarget(event);
        if (!target) return;
        if (target === this.activeTarget && this.visible) return;
        const text = target.dataset.tooltip || target.dataset.iconTooltip || target.getAttribute('aria-label');
        if (!text) return;
        this.activeTarget = target;
        this.text = text;
        this.positioned = false;
        this.visible = true;
        const rect = target.getBoundingClientRect();
        this.below = rect.top < 48;
        this.x = rect.left + rect.width / 2;
        this.y = this.below ? rect.bottom + 8 : rect.top - 8;
        this.$nextTick(() => {
            const width = this.$refs.tooltip?.offsetWidth || 0;
            this.x = Math.max(8, Math.min(window.innerWidth - width - 8, this.x - width / 2));
            this.$nextTick(() => this.positioned = true);
        });
    },
    hide(event) {
        if (event?.relatedTarget && this.activeTarget?.contains(event.relatedTarget)) return;
        this.visible = false;
        this.positioned = false;
        this.activeTarget = null;
    },
    init() {
        this.prepare(document);
        new MutationObserver((mutations) => mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (node.nodeType !== Node.ELEMENT_NODE) return;
            this.prepare(node);
            if (node.matches?.('button[title], a[title], [data-tooltip]')) this.prepare(node.parentElement);
        }))).observe(document.body, { childList: true, subtree: true });
        document.addEventListener('mouseover', (event) => this.show(event));
        document.addEventListener('mouseout', (event) => this.hide(event));
        document.addEventListener('focusin', (event) => this.show(event));
        document.addEventListener('focusout', (event) => this.hide(event));
        document.addEventListener('click', () => this.hide());
        window.addEventListener('scroll', () => this.hide(), true);
        window.addEventListener('resize', () => this.hide());
    },
}" class="contents">
    <div x-ref="tooltip" x-show="visible" x-cloak role="tooltip" x-text="text"
        :style="`left: ${x}px; top: ${y}px;`"
        :class="[below ? '' : '-translate-y-full', positioned ? 'visible' : 'invisible']"
        class="pointer-events-none fixed z-[10000] whitespace-nowrap rounded-lg border border-neutral-700 bg-neutral-900 px-2 py-1 text-xs font-medium text-white shadow-lg dark:border-white/10 dark:bg-raised">
    </div>
</div>
