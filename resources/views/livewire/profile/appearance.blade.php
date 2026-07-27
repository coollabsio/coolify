<div>
    <x-slot:title>Appearance | Coolify</x-slot>
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
    }" class="mt-8 flex w-full max-w-[1180px] flex-col gap-6 lg:mt-3">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Color theme</h2>
                    <p>Choose the color scheme used in this browser.</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-3">
                @foreach ([
                    ['value' => 'light', 'label' => 'Light', 'description' => 'Bright surfaces and dark text.', 'preview' => 'bg-white'],
                    ['value' => 'system', 'label' => 'System', 'description' => 'Follow your operating system.', 'preview' => 'bg-gradient-to-r from-white to-[#181818]'],
                    ['value' => 'dark', 'label' => 'Dark', 'description' => 'Dark surfaces and soft contrast.', 'preview' => 'bg-[#181818]'],
                ] as $option)
                    <button type="button" @click="setTheme('{{ $option['value'] }}')"
                        class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.025] dark:hover:border-white/[0.12]"
                        :class="theme === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div class="h-20 {{ $option['preview'] }} border-b border-neutral-200 dark:border-white/[0.07]">
                            <div class="flex h-full items-center justify-center">
                                <div
                                    class="h-8 w-20 rounded-md border border-black/10 bg-neutral-100/80 shadow-sm dark:border-white/10 dark:bg-black/20">
                                </div>
                            </div>
                        </div>
                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-black dark:text-fg">
                                    {{ $option['label'] }}
                                </span>
                                <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                    x-show="theme === '{{ $option['value'] }}'" x-cloak />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                {{ $option['description'] }}
                            </p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Page width</h2>
                    <p>Control how much horizontal space dashboard pages can use.</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['value' => 'center', 'label' => 'Centered', 'description' => 'Keep content in a focused reading column.'],
                    ['value' => 'full', 'label' => 'Full width', 'description' => 'Use the available dashboard canvas.'],
                ] as $option)
                    <button type="button" @click="setWidth('{{ $option['value'] }}')"
                        class="flex items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 text-left transition-[border-color,background-color] hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-white/[0.025] dark:hover:bg-white/[0.045]"
                        :class="pageWidth === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-reicon name="{{ $option['value'] === 'center' ? 'unordered-list' : 'grid' }}"
                                class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</div>
                            <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                        </div>
                        <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                            x-show="pageWidth === '{{ $option['value'] }}'" x-cloak />
                    </button>
                @endforeach
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Interface density</h2>
                    <p>Adjust the dashboard scale without changing browser zoom.</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['value' => '100', 'label' => 'Comfortable', 'description' => 'Standard spacing and type at 100%.'],
                    ['value' => '90', 'label' => 'Compact', 'description' => 'Fit more information at 90%.'],
                ] as $option)
                    <button type="button" @click="setZoom('{{ $option['value'] }}')"
                        class="flex items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 text-left transition-[border-color,background-color] hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-white/[0.025] dark:hover:bg-white/[0.045]"
                        :class="zoom === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 font-mono text-xs font-semibold text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                            {{ $option['value'] }}%
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</div>
                            <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                        </div>
                        <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                            x-show="zoom === '{{ $option['value'] }}'" x-cloak />
                    </button>
                @endforeach
            </div>
        </section>
    </div>
</div>
