{{--
    Scrollable resource tab strip with Kumo-style overflow arrows.
    Left/right circular chevrons appear only when more tabs exist off-screen.
    On load / Livewire navigate, the active tab is scrolled into view so
    right-side items are not hidden after navigation on mobile.
--}}
@props([
    'listClass' => '',
])

<div
    {{ $attributes->class('resource-heading-tabs-scroller relative isolate min-w-0') }}
    x-data="{
        canStart: false,
        canEnd: false,
        ro: null,
        mo: null,
        onNavigated: null,
        update() {
            const el = this.$refs.list
            if (!el) {
                return
            }
            const max = Math.max(0, el.scrollWidth - el.clientWidth)
            const left = Math.min(Math.max(0, el.scrollLeft), max)
            this.canStart = left > 1
            this.canEnd = (max - left) > 1
        },
        scrollByDir(dir) {
            const el = this.$refs.list
            if (!el) {
                return
            }
            const amount = Math.max(80, Math.floor(el.clientWidth * 0.7))
            el.scrollBy({ left: dir === 'start' ? -amount : amount, behavior: 'smooth' })
        },
        findActiveTab() {
            const el = this.$refs.list
            if (!el) {
                return null
            }

            return Array.from(el.children).find((child) => {
                if (!(child instanceof HTMLElement)) {
                    return false
                }
                if (child.getAttribute('aria-current') === 'page') {
                    return true
                }
                if (child.hasAttribute('data-active') || child.dataset.active === 'true') {
                    return true
                }
                // Active resource tabs use coollabs/warning highlight classes.
                const cls = typeof child.className === 'string' ? child.className : ''
                return cls.includes('ring-coollabs')
                    || cls.includes('bg-coollabs')
                    || cls.includes('ring-warning')
                    || cls.includes('bg-warning')
            }) ?? null
        },
        scrollActiveIntoView() {
            const list = this.$refs.list
            const active = this.findActiveTab()
            if (!list || !active) {
                this.update()
                return
            }

            // Center the active tab so right-edge items are fully visible after navigate.
            const listWidth = list.clientWidth
            const maxScroll = Math.max(0, list.scrollWidth - listWidth)
            const activeCenter = active.offsetLeft + (active.offsetWidth / 2)
            const target = Math.min(maxScroll, Math.max(0, activeCenter - (listWidth / 2)))

            list.scrollTo({ left: target, behavior: 'auto' })
            this.update()
        },
        scheduleScrollActiveIntoView() {
            // Layout may not be final on first paint (fonts, Livewire morph, flex).
            this.$nextTick(() => {
                this.scrollActiveIntoView()
                requestAnimationFrame(() => {
                    this.scrollActiveIntoView()
                    requestAnimationFrame(() => this.scrollActiveIntoView())
                })
            })
        },
        init() {
            this.onNavigated = () => this.scheduleScrollActiveIntoView()
            document.addEventListener('livewire:navigated', this.onNavigated)

            this.$nextTick(() => {
                this.scheduleScrollActiveIntoView()
                const el = this.$refs.list
                if (!el || typeof ResizeObserver === 'undefined') {
                    return
                }
                this.ro = new ResizeObserver(() => {
                    this.update()
                })
                this.ro.observe(el)
                if (typeof MutationObserver !== 'undefined') {
                    this.mo = new MutationObserver(() => {
                        this.update()
                    })
                    this.mo.observe(el, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'aria-current', 'data-active'] })
                }
            })
        },
        destroy() {
            this.ro?.disconnect()
            this.mo?.disconnect()
            if (this.onNavigated) {
                document.removeEventListener('livewire:navigated', this.onNavigated)
            }
        },
    }"
    x-init="init()"
    @resize.window.debounce.50ms="update()"
>
    <div
        x-ref="list"
        @scroll.passive="update()"
        @class([
            'resource-heading-tabs flex w-full min-w-0 items-center gap-0.5 overflow-x-auto',
            $listClass,
        ])
        :data-overflow-start="canStart ? '' : null"
        :data-overflow-end="canEnd ? '' : null"
    >
        {{ $slot }}
    </div>

    <button
        type="button"
        class="resource-heading-tabs-control is-start"
        aria-label="Scroll tabs left"
        :aria-hidden="(!canStart).toString()"
        :tabindex="canStart ? 0 : -1"
        :class="canStart ? 'is-visible' : ''"
        @click="scrollByDir('start')"
    >
        <span class="resource-heading-tabs-control-icon" aria-hidden="true">
            <svg viewBox="0 0 16 16" fill="none" class="size-3.5">
                <path d="M9.25 4.25L5.75 8L9.25 11.75" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </button>

    <button
        type="button"
        class="resource-heading-tabs-control is-end"
        aria-label="Scroll tabs right"
        :aria-hidden="(!canEnd).toString()"
        :tabindex="canEnd ? 0 : -1"
        :class="canEnd ? 'is-visible' : ''"
        @click="scrollByDir('end')"
    >
        <span class="resource-heading-tabs-control-icon" aria-hidden="true">
            <svg viewBox="0 0 16 16" fill="none" class="size-3.5">
                <path d="M6.75 4.25L10.25 8L6.75 11.75" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </button>
</div>
