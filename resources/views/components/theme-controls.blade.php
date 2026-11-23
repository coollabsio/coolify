{{-- Full card grid for the Appearance settings page. --}}
<div x-data="themeControls()" class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
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
                ['value' => 'custom', 'label' => 'Custom', 'description' => 'Pick a preset or your own colors.', 'preview' => ''],
            ] as $option)
                @if ($option['value'] === 'custom')
                    <div role="button" tabindex="0" @click="openCustomDialog()" @keydown.enter.prevent="openCustomDialog()"
                        aria-haspopup="dialog" :aria-expanded="dialogOpen"
                        class="group block w-full overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                        :class="theme === 'custom'
                            ? 'border-coollabs! bg-coollabs/[0.06]! ring-2 ring-coollabs/25 dark:border-brand-light! dark:bg-brand-light/[0.08]! dark:ring-brand-light/25'
                            : ''">
                        <div class="flex h-20 items-center justify-center border-b border-neutral-200 bg-neutral-100 dark:border-white/[0.07] dark:bg-black/20">
                            <x-theme-controls.swatch theme="customTheme" class="h-12 w-24" />
                        </div>
                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                                <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-brand-light"
                                    x-show="theme === 'custom'" x-cloak />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                {{ $option['description'] }}
                            </p>
                        </div>
                    </div>
                @else
                    <div role="button" tabindex="0"
                        @click="setTheme('{{ $option['value'] }}')"
                        @keydown.enter.prevent="setTheme('{{ $option['value'] }}')"
                        class="group relative overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                        :class="theme === '{{ $option['value'] }}'
                            ? 'border-coollabs! bg-coollabs/[0.06]! ring-2 ring-coollabs/25 dark:border-brand-light! dark:bg-brand-light/[0.08]! dark:ring-brand-light/25'
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
                                <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-brand-light"
                                    x-show="theme === '{{ $option['value'] }}'" x-cloak />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                {{ $option['description'] }}
                            </p>
                        </div>
                    </div>
                @endif
            @endforeach
            {{-- Shown when an instance admin set a default theme (Settings > Advanced). --}}
            <template x-if="instanceTheme">
                <div class="flex flex-wrap items-center gap-3 rounded-[10px] border border-neutral-200 px-3 py-2.5 sm:col-span-2 lg:col-span-4 dark:border-white/[0.07]">
                    <x-theme-controls.swatch theme="instanceTheme" class="h-8 w-14 shrink-0" />
                    <p class="min-w-0 flex-1 text-xs leading-5 text-neutral-600 dark:text-fg-dim"
                        x-text="usesInstanceTheme
                            ? 'You use the default theme of this instance. Pick another theme to use your own.'
                            : 'This instance has a default theme. Your own choice replaces it in this browser.'"></p>
                    <button type="button" x-show="!usesInstanceTheme" @click="useInstanceTheme()" class="button shrink-0">
                        Use instance default
                    </button>
                </div>
            </template>
        </div>
    </section>

    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>UI scale</h2>
                <p>Choose the size of text and controls. Phones always use 100%.</p>
            </div>
        </div>
        <div class="application-settings-section-body grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['value' => '80', 'label' => '80%', 'description' => 'Show more content on the screen.', 'size' => 'text-base'],
                ['value' => '90', 'label' => '90%', 'description' => 'Smaller text and controls.', 'size' => 'text-lg'],
                ['value' => '100', 'label' => '100%', 'description' => 'The default size.', 'size' => 'text-xl'],
            ] as $option)
                <button type="button" @click="setUiScale('{{ $option['value'] }}')"
                    class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                    :class="uiScale === '{{ $option['value'] }}'
                        ? 'border-coollabs! bg-coollabs/[0.06]! ring-2 ring-coollabs/25 dark:border-brand-light! dark:bg-brand-light/[0.08]! dark:ring-brand-light/25'
                        : ''">
                    <div class="flex h-20 items-center justify-center border-b border-neutral-200 bg-neutral-50 px-4 dark:border-white/[0.07] dark:bg-black/15">
                        <span class="{{ $option['size'] }} font-semibold text-black dark:text-fg">Aa</span>
                    </div>
                    <div class="p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                            <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-brand-light"
                                x-show="uiScale === '{{ $option['value'] }}'" x-cloak />
                        </div>
                        <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                    </div>
                </button>
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
                    class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                    :class="pageWidth === '{{ $option['value'] }}'
                        ? 'border-coollabs! bg-coollabs/[0.06]! ring-2 ring-coollabs/25 dark:border-brand-light! dark:bg-brand-light/[0.08]! dark:ring-brand-light/25'
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
                            <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-brand-light"
                                x-show="pageWidth === '{{ $option['value'] }}'" x-cloak />
                        </div>
                        <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                    </div>
                </button>
            @endforeach
        </div>
    </section>

    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>Navigation style</h2>
                <p>Choose how the top bar and sidebars sit on the page.</p>
            </div>
        </div>
        <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['value' => 'default', 'label' => 'Default', 'description' => 'Top bar and sidebars run edge to edge.'],
                ['value' => 'floating', 'label' => 'Floating', 'description' => 'Framed panels float on a dotted backdrop, like the canvas.'],
            ] as $option)
                <button type="button" @click="setNavStyle('{{ $option['value'] }}')"
                    class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                    :class="navStyle === '{{ $option['value'] }}'
                        ? 'border-coollabs! bg-coollabs/[0.06]! ring-2 ring-coollabs/25 dark:border-brand-light! dark:bg-brand-light/[0.08]! dark:ring-brand-light/25'
                        : ''">
                    @php($floating = $option['value'] === 'floating')
                    <div @class([
                        'flex h-20 border-b border-neutral-200 bg-neutral-50 dark:border-white/[0.07] dark:bg-black/15',
                        'gap-1.5 p-1.5' => $floating,
                    ])>
                        <div @class([
                            'flex flex-col items-center gap-1.5 bg-white px-2 py-2 dark:bg-white/[0.04]',
                            'rounded-lg border border-neutral-200 dark:border-white/[0.08]' => $floating,
                            'border-r border-neutral-200 dark:border-white/[0.07]' => ! $floating,
                        ])>
                            @foreach (['servers', 'projects'] as $icon)
                                <span class="flex size-6 items-center justify-center rounded-md border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon :name="$icon" class="size-3.5" />
                                </span>
                            @endforeach
                        </div>
                        <div @class([
                            'h-5 flex-1 bg-white dark:bg-white/[0.04]',
                            'rounded-lg border border-neutral-200 dark:border-white/[0.08]' => $floating,
                            'border-b border-neutral-200 dark:border-white/[0.07]' => ! $floating,
                        ])></div>
                    </div>
                    <div class="p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                            <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-brand-light"
                                x-show="navStyle === '{{ $option['value'] }}'" x-cloak />
                        </div>
                        <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                    </div>
                </button>
            @endforeach
        </div>
    </section>

    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>Style</h2>
                <p>Choose how buttons and sidebar items look.</p>
            </div>
        </div>
        <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['value' => '3d', 'label' => '3D', 'description' => 'Buttons and sidebar icons sit on a raised edge that lifts on hover.'],
                ['value' => 'flat', 'label' => 'Flat', 'description' => 'No raised edges. Sidebar items are plain rows with a highlight for the current page.'],
            ] as $option)
                <button type="button" @click="setNavItems('{{ $option['value'] }}')"
                    class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                    :class="navItems === '{{ $option['value'] }}'
                        ? 'border-coollabs! bg-coollabs/[0.06]! ring-2 ring-coollabs/25 dark:border-brand-light! dark:bg-brand-light/[0.08]! dark:ring-brand-light/25'
                        : ''">
                    @php($tiles = $option['value'] === '3d')
                    <div class="flex h-20 flex-col justify-center gap-1.5 border-b border-neutral-200 bg-neutral-50 px-4 dark:border-white/[0.07] dark:bg-black/15">
                        @foreach (['servers', 'projects'] as $icon)
                            <div @class([
                                'flex items-center gap-2 rounded-md text-neutral-500 dark:text-fg-dim',
                                'px-1.5 py-1' => ! $tiles,
                                'bg-neutral-200/70 dark:bg-white/[0.08]' => ! $tiles && $loop->first,
                            ])>
                                <span @class([
                                    'flex items-center justify-center',
                                    'size-6 rounded-md border border-neutral-200 bg-white shadow-[0_2px_0_rgb(0_0_0/0.22)] dark:border-white/[0.09] dark:bg-white/[0.04] dark:shadow-[0_2px_0_rgb(255_255_255/0.08)]' => $tiles,
                                ])>
                                    <x-reicon :name="$icon" class="size-3.5" />
                                </span>
                                <span class="h-1.5 w-16 rounded-full bg-neutral-200 dark:bg-white/10"></span>
                            </div>
                        @endforeach
                    </div>
                    <div class="p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                            <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-brand-light"
                                x-show="navItems === '{{ $option['value'] }}'" x-cloak />
                        </div>
                        <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                    </div>
                </button>
            @endforeach
        </div>
    </section>

    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>Chart times</h2>
                <p>Choose which times chart tooltips show.</p>
            </div>
        </div>
        <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['value' => 'local', 'label' => 'Your time', 'description' => 'Show the time in the time zone of this browser.', 'lines' => ['Oct 24, 12:30:33']],
                ['value' => 'utc', 'label' => 'Your time and UTC', 'description' => 'Also show the UTC time. Use it to compare with server logs.', 'lines' => ['Your time: Oct 24, 12:30:33 GMT+2', 'UTC: Oct 24, 10:30:33 UTC']],
            ] as $option)
                <button type="button" @click="setChartTimes('{{ $option['value'] }}')"
                    class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                    :class="chartTimes === '{{ $option['value'] }}'
                        ? 'border-coollabs! bg-coollabs/[0.06]! ring-2 ring-coollabs/25 dark:border-brand-light! dark:bg-brand-light/[0.08]! dark:ring-brand-light/25'
                        : ''">
                    <div class="flex h-20 items-center justify-center border-b border-neutral-200 bg-neutral-50 px-4 dark:border-white/[0.07] dark:bg-black/15">
                        <div class="rounded-md border border-neutral-300 bg-white px-2.5 py-1.5 text-[11px] leading-4 dark:border-white/15 dark:bg-[#181818]">
                            <div class="text-black dark:text-fg">CPU: <span class="font-semibold">4.3%</span></div>
                            @foreach ($option['lines'] as $line)
                                <div class="text-neutral-500 dark:text-fg-dim">{{ $line }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                            <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-brand-light"
                                x-show="chartTimes === '{{ $option['value'] }}'" x-cloak />
                        </div>
                        <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                    </div>
                </button>
            @endforeach
        </div>
    </section>

    <x-theme-controls.custom-dialog
        description="Colors of Coolify in this browser. Text stays readable on every color." />
</div>
