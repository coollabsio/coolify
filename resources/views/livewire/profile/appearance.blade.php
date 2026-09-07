<div>
    <x-slot:title>Appearance | Coolify</x-slot>
    <div x-data="{
        theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'dark'),
        themeColor: localStorage.getItem('themeColor') || '#6b16ed',
        pageWidth: localStorage.getItem('pageWidth') || 'full',
        themeColorFrame: null,
        init() {
            localStorage.setItem('theme', this.theme);
            this.applyTheme();
        },
        setTheme(type) {
            this.theme = type;
            localStorage.setItem('theme', type);
            this.applyTheme();
        },
        setWidth(width) {
            this.pageWidth = width;
            localStorage.setItem('pageWidth', width);
            window.dispatchEvent(new CustomEvent('page-width-changed', { detail: width }));
        },
        previewThemeColor(color) {
            this.themeColor = color;

            if (this.theme !== 'custom') {
                this.theme = 'custom';
                document.documentElement.classList.add('dark');
                document.documentElement.dataset.theme = 'custom';
            }

            if (this.themeColorFrame) {
                return;
            }

            this.themeColorFrame = requestAnimationFrame(() => {
                document.documentElement.style.setProperty('--theme-base-color', this.themeColor);
                document.documentElement.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(this.themeColor));
                this.themeColorFrame = null;
            });
        },
        saveThemeColor(color) {
            this.previewThemeColor(color);
            localStorage.setItem('themeColor', color);
            localStorage.setItem('theme', 'custom');
        },
        applyTheme() {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const isDark = this.theme === 'dark' || this.theme === 'custom' || (this.theme === 'system' && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.dataset.theme = this.theme === 'custom' ? 'custom' : (isDark ? 'dark' : 'light');
            document.documentElement.style.setProperty('--theme-base-color', this.themeColor);
            document.documentElement.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(this.themeColor));
            document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
        },
    }" class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Color theme</h2>
                    <p>Choose the color scheme used in this browser.</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['value' => 'light', 'label' => 'Light', 'description' => 'Bright surfaces and dark text.', 'preview' => 'bg-white'],
                    ['value' => 'system', 'label' => 'System', 'description' => 'Follow your operating system.', 'preview' => 'bg-gradient-to-r from-white via-neutral-400 to-[#050505]'],
                    ['value' => 'dark', 'label' => 'Dark', 'description' => 'Dark surfaces and soft contrast.', 'preview' => 'bg-[#181818]'],
                    ['value' => 'custom', 'label' => 'Custom', 'description' => 'Choose any color for dark surfaces.', 'preview' => ''],
                ] as $option)
                    <div role="button" tabindex="0"
                        @if ($option['value'] !== 'custom')
                            @click="setTheme('{{ $option['value'] }}')"
                            @keydown.enter.prevent="setTheme('{{ $option['value'] }}')"
                        @endif
                        class="group relative overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.025] dark:hover:border-white/[0.12]"
                        :class="theme === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div class="h-20 {{ $option['preview'] }} border-b border-neutral-200 dark:border-white/[0.07]"
                            @if ($option['value'] === 'custom') :style="`background: color-mix(in oklab, ${themeColor} 28%, #101011)`" @endif>
                            <div class="flex h-full items-center justify-center">
                                @if ($option['value'] === 'custom')
                                    <div class="h-10 w-20 rounded-md border border-white/15 p-1 shadow-sm">
                                        <div class="h-full w-full rounded-sm" :style="`background: ${themeColor}`"></div>
                                    </div>
                                @else
                                    <div
                                    class="h-8 w-20 rounded-md border border-black/10 bg-neutral-100/80 shadow-sm dark:border-white/10 dark:bg-black/20">
                                    </div>
                                @endif
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
                        @if ($option['value'] === 'custom')
                            <input type="color" :value="themeColor" @input="previewThemeColor($event.target.value)"
                                @change="saveThemeColor($event.target.value)"
                                aria-label="Custom theme color"
                                class="absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0" />
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Page width</h2>
                    <p>Choose how content uses the available browser width.</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['value' => 'full', 'label' => 'Full width', 'description' => 'Use all available space for page content.'],
                    ['value' => 'centered', 'label' => 'Centered', 'description' => 'Keep content centered at a comfortable maximum width.'],
                ] as $option)
                    <button type="button" @click="setWidth('{{ $option['value'] }}')"
                        class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.025] dark:hover:border-white/[0.12]"
                        :class="pageWidth === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div class="flex h-20 items-center border-b border-neutral-200 bg-neutral-50 px-4 dark:border-white/[0.07] dark:bg-black/15">
                            <div class="flex h-11 w-full gap-1.5 rounded-md border border-neutral-300 bg-white p-1.5 dark:border-white/15 dark:bg-[#181818]">
                                <div class="w-3 shrink-0 rounded-sm bg-neutral-200 dark:bg-white/10"></div>
                                <div @class([
                                    'h-full rounded-sm bg-neutral-200 dark:bg-white/10',
                                    'w-full' => $option['value'] === 'full',
                                    'mx-auto w-2/3' => $option['value'] === 'centered',
                                ])></div>
                            </div>
                        </div>
                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                                <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                    x-show="pageWidth === '{{ $option['value'] }}'" x-cloak />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    </div>
</div>
