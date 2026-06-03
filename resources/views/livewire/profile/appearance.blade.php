<div>
    <x-slot:title>
        Appearance | Coolify
    </x-slot>
    <x-profile.navbar />

    <div x-data="{
        theme: localStorage.getItem('theme') || 'dark',
        pageWidth: localStorage.getItem('pageWidth') || 'full',
        zoom: localStorage.getItem('zoom') || '100',
        init() {
            localStorage.setItem('theme', this.theme);
            localStorage.setItem('pageWidth', this.pageWidth);
            localStorage.setItem('zoom', this.zoom);
            this.applyTheme();
        },
        setTheme(type) {
            this.theme = type;
            localStorage.setItem('theme', type);
            this.applyTheme();
        },
        applyTheme() {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const isDark = this.theme === 'dark' || (this.theme === 'system' && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
            document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
        },
        setWidth(width) {
            this.pageWidth = width;
            localStorage.setItem('pageWidth', width);
            window.location.reload();
        },
        setZoom(value) {
            this.zoom = value;
            localStorage.setItem('zoom', value);
            window.location.reload();
        },
    }" class="flex max-w-2xl flex-col">
        <section class="space-y-1.5">
            <h2>Appearance</h2>
            <div>Choose how Coolify looks in this browser.</div>
            <div class="flex flex-wrap gap-1.5">
                <button type="button" @click="setTheme('light')" aria-label="Use light theme"
                    class="flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-left text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning"
                    :class="theme === 'light' && 'border-coollabs text-coollabs dark:border-warning dark:text-warning'">
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <span>Light</span>
                </button>
                <button type="button" @click="setTheme('system')" aria-label="Use system theme"
                    class="flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-left text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning"
                    :class="theme === 'system' && 'border-coollabs text-coollabs dark:border-warning dark:text-warning'">
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span>System</span>
                </button>
                <button type="button" @click="setTheme('dark')" aria-label="Use dark theme"
                    class="flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-left text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning"
                    :class="theme === 'dark' && 'border-coollabs text-coollabs dark:border-warning dark:text-warning'">
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                    <span>Dark</span>
                </button>
            </div>
        </section>

        <section class="space-y-1.5">
            <h2>Width</h2>
            <div>Choose the maximum page width for this browser.</div>
            <div class="flex flex-wrap gap-1.5">
                <button type="button" @click="setWidth('center')" aria-label="Use centered width"
                    class="flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning"
                    :class="pageWidth === 'center' && 'border-coollabs text-coollabs dark:border-warning dark:text-warning'">
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7" />
                    </svg>
                    Center
                </button>
                <button type="button" @click="setWidth('full')" aria-label="Use full width"
                    class="flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning"
                    :class="pageWidth === 'full' && 'border-coollabs text-coollabs dark:border-warning dark:text-warning'">
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    Full
                </button>
            </div>
        </section>

        <section class="space-y-1.5">
            <h2>Zoom</h2>
            <div>Choose interface density for this browser.</div>
            <div class="flex flex-wrap gap-1.5">
                <button type="button" @click="setZoom('100')" aria-label="Use 100 percent zoom"
                    class="flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning"
                    :class="zoom === '100' && 'border-coollabs text-coollabs dark:border-warning dark:text-warning'">
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    100%
                </button>
                <button type="button" @click="setZoom('90')" aria-label="Use 90 percent zoom"
                    class="flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning"
                    :class="zoom === '90' && 'border-coollabs text-coollabs dark:border-warning dark:text-warning'">
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 10h4v4h-4v-4z" />
                    </svg>
                    90%
                </button>
            </div>
        </section>
    </div>
</div>
