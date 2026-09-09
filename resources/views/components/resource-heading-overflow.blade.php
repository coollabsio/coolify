{{--
    Resource header actions: render inline in the top bar, then collapse into
    an Actions dropdown when the remaining header width cannot fit them.
--}}
@props([
    'id' => null,
    'label' => 'Actions',
])

<div
    @if (filled($id)) id="{{ $id }}" @endif
    {{ $attributes->class('resource-heading-overflow relative shrink-0') }}
    data-resource-heading-overflow
    x-data="{
        collapsed: false,
        open: false,
        ro: null,
        onNavigated: null,
        update() {
            const needed = this.measureInlineWidth()
            const available = this.availableWidth()
            const next = needed > 0 && needed > available
            if (next === this.collapsed) {
                return
            }
            this.collapsed = next
            if (!next) {
                this.open = false
            }
        },
        hudSlot() {
            return this.$el.closest('#resource-action-hud-slot')
                ?? document.getElementById('resource-action-hud-slot')
        },
        headerRow() {
            return this.hudSlot()?.parentElement ?? null
        },
        measureInlineWidth() {
            const items = this.$refs.items
            if (!items) {
                return 0
            }
            items.classList.add('is-measuring')
            const width = items.scrollWidth
            items.classList.remove('is-measuring')
            return width
        },
        availableWidth() {
            const row = this.headerRow()
            const hud = this.hudSlot()
            if (!row || !hud) {
                return Infinity
            }

            let reserved = 0
            for (const child of row.children) {
                if (child === hud) {
                    continue
                }
                if (child.classList.contains('flex-1') || child.querySelector(':scope .flex-1')) {
                    reserved += 200
                    continue
                }
                reserved += child.getBoundingClientRect().width
            }

            let hudSiblings = 0
            const cluster = this.$el.parentElement
            if (cluster) {
                for (const child of cluster.children) {
                    if (child === this.$el) {
                        continue
                    }
                    hudSiblings += child.getBoundingClientRect().width
                }
            }

            return Math.max(0, row.clientWidth - reserved - hudSiblings - 16)
        },
        observe() {
            if (typeof ResizeObserver === 'undefined') {
                return
            }
            if (!this.ro) {
                this.ro = new ResizeObserver(() => this.update())
            }
            const row = this.headerRow()
            if (row) {
                this.ro.observe(row)
            }
            this.ro.observe(this.$el)
        },
        init() {
            this.onNavigated = () => this.$nextTick(() => {
                this.observe()
                this.update()
            })
            document.addEventListener('livewire:navigated', this.onNavigated)

            this.$nextTick(() => {
                this.observe()
                this.update()
                requestAnimationFrame(() => {
                    this.observe()
                    this.update()
                })
            })
        },
        destroy() {
            this.ro?.disconnect()
            if (this.onNavigated) {
                document.removeEventListener('livewire:navigated', this.onNavigated)
            }
        },
    }"
    x-init="init()"
    x-effect="$dispatch('resource-actions-toggled', { open })"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    @resize.window.debounce.50ms="update()"
    :class="{ 'is-collapsed': collapsed }"
>
    <button
        type="button"
        class="button resource-heading-overflow-trigger"
        x-show="collapsed"
        x-cloak
        @click="open = !open"
        :aria-expanded="open"
        aria-haspopup="menu"
    >
        <x-reicon name="play-circle" class="size-3.5 opacity-70" />
        {{ $label }}
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </button>

    <div
        x-ref="items"
        class="resource-heading-overflow-items"
        x-show="!collapsed || open"
        :class="collapsed && 'listbox-panel top-full! right-0! left-auto! mt-1! min-w-52!'"
        :role="collapsed ? 'menu' : null"
        :aria-hidden="collapsed && !open"
    >
        {{ $slot }}
    </div>
</div>
