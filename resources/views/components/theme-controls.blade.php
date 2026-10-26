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

    {{-- Custom theme dialog: presets, a preview, four colors, and a share code. Save applies the draft. --}}
    <template x-teleport="body">
        <div x-show="dialogOpen" x-cloak @keydown.escape.window="dialogOpen = false"
            class="fixed inset-0 z-99 overflow-hidden">
            <div x-show="dialogOpen" x-transition.opacity.duration.200ms
                class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"></div>
            <div @click.self="dialogOpen = false"
                class="relative flex min-h-full items-start justify-center p-2 sm:items-center sm:p-4">
                <div x-show="dialogOpen" x-trap.inert.noscroll="dialogOpen" role="dialog" aria-modal="true"
                    aria-labelledby="custom-theme-title"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                    class="application-settings-form application-settings-section relative flex max-h-[calc(100dvh-1rem)] w-full max-w-5xl flex-col overflow-hidden sm:max-h-[calc(100dvh-2rem)]"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header class="shrink-0">
                        <div class="min-w-0 flex-1 py-0.5">
                            <h3 id="custom-theme-title" class="truncate">Custom theme</h3>
                            <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">
                                Colors of Coolify in this browser. Text stays readable on every color.
                            </p>
                        </div>
                        <button type="button" @click="dialogOpen = false"
                            class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 outline-0 transition-colors hover:bg-neutral-100 hover:text-black focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <x-reicon name="x" class="size-4" />
                            <span class="sr-only">Close</span>
                        </button>
                    </header>
                    <template x-if="dialogOpen">
                        <div class="application-settings-section-body grid min-h-0 flex-1 gap-6 overflow-y-auto lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                            <div>
                                <div class="mb-2 text-xs font-medium text-neutral-500 dark:text-fg-dim">Presets</div>
                                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    <template x-for="preset in presets" :key="preset.name">
                                        <button type="button" @click="setDraft(preset.theme && { ...preset.theme })"
                                            :aria-pressed="isDraftPreset(preset)"
                                            class="group flex flex-col gap-1.5 rounded-lg p-1 text-left outline-0 focus-visible:ring-1 focus-visible:ring-accent">
                                            <x-theme-controls.swatch theme="(preset.theme ?? preset.swatch)"
                                                class="h-14 w-full transition-shadow group-hover:shadow-md"
                                                ::class="isDraftPreset(preset) && 'ring-2 ring-coollabs ring-offset-2 ring-offset-white dark:ring-brand-light dark:ring-offset-panel'" />
                                            <span class="truncate text-xs text-neutral-600 dark:text-fg-dim" x-text="preset.name"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <div class="flex min-w-0 flex-col gap-5">
                                {{-- Preview of a page with the draft colors. --}}
                                <div class="flex h-48 overflow-hidden rounded-lg border border-neutral-200 text-xs dark:border-white/[0.08]"
                                    aria-hidden="true">
                                    <div class="flex w-2/5 flex-col gap-0.5 p-2.5"
                                        :style="`background: ${draftColors.sidebar}; color: ${textOn(draftColors.sidebar)}`">
                                        <div class="mb-2 px-2 text-sm font-semibold">Coolify</div>
                                        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 opacity-75">
                                            <x-reicon name="dashboard" class="size-3.5" /> Dashboard
                                        </div>
                                        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 font-medium"
                                            :style="`background: ${draftColors.selected}; color: ${textOn(draftColors.selected)}`">
                                            <x-reicon name="projects" class="size-3.5" /> Projects
                                        </div>
                                        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 opacity-75">
                                            <x-reicon name="servers" class="size-3.5" /> Servers
                                            <span class="ml-auto rounded-full px-1.5 text-[10px] font-semibold"
                                                :style="`background: ${draftColors.accent}; color: ${accentTextOn(draftColors.accent)}`">3</span>
                                        </div>
                                    </div>
                                    <div class="flex min-w-0 flex-1 flex-col gap-2 p-3"
                                        :style="`background: ${draftColors.background}; color: ${textOn(draftColors.background)}`">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-sm font-semibold">Projects</span>
                                            <span class="rounded-md px-2 py-1 text-[11px] font-medium"
                                                :style="`background: ${draftColors.accent}; color: ${accentTextOn(draftColors.accent)}`">+ New</span>
                                        </div>
                                        <div class="rounded-md border p-2.5"
                                            :style="`background: color-mix(in oklab, ${textOn(draftColors.background)} 4%, ${draftColors.background}); border-color: color-mix(in oklab, ${textOn(draftColors.background)} 14%, ${draftColors.background})`">
                                            <div class="font-medium">My first project</div>
                                            <div class="mt-0.5 opacity-60">production</div>
                                        </div>
                                        <div class="rounded-md border p-2.5"
                                            :style="`background: color-mix(in oklab, ${textOn(draftColors.background)} 4%, ${draftColors.background}); border-color: color-mix(in oklab, ${textOn(draftColors.background)} 14%, ${draftColors.background})`">
                                            <div class="font-medium">Website</div>
                                            <div class="mt-0.5 opacity-60">staging</div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-2 text-xs font-medium text-neutral-500 dark:text-fg-dim">Colors</div>
                                    <div class="flex flex-col gap-2.5">
                                        @foreach ([
                                            'sidebar' => ['Sidebar', 'The navigation on the left'],
                                            'selected' => ['Selected', 'The current page in the sidebar'],
                                            'accent' => ['Accent', 'Buttons, links, and highlights'],
                                            'background' => ['Background', 'Pages, cards, and panels'],
                                        ] as $key => [$label, $description])
                                            <div class="flex items-center gap-3">
                                                <label class="relative size-9 shrink-0 cursor-pointer overflow-hidden rounded-md border border-black/10 shadow-sm dark:border-white/15">
                                                    <span class="absolute inset-0" :style="`background: ${draftColors.{{ $key }}}`"></span>
                                                    {{-- autocomplete="off": Firefox session restore otherwise replays the value (#11813). --}}
                                                    <input type="color" autocomplete="off" :value="draftColors.{{ $key }}"
                                                        @input="setDraftColor('{{ $key }}', $event.target.value)"
                                                        aria-label="{{ $label }} color"
                                                        class="absolute inset-0 h-full w-full cursor-pointer opacity-0" />
                                                </label>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-medium text-black dark:text-fg">{{ $label }}</div>
                                                    <div class="truncate text-xs text-neutral-500 dark:text-fg-dim">{{ $description }}</div>
                                                </div>
                                                <input type="text" autocomplete="off" spellcheck="false" maxlength="7"
                                                    :value="draftColors.{{ $key }}"
                                                    @change="setDraftColor('{{ $key }}', $event.target.value); $event.target.value = draftColors.{{ $key }}"
                                                    aria-label="{{ $label }} hex color"
                                                    class="input w-28! shrink-0 font-mono text-sm" />
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <label for="custom-theme-share-code"
                                        class="mb-2 block text-xs font-medium text-neutral-500 dark:text-fg-dim">Share code</label>
                                    <div class="flex gap-2">
                                        <input id="custom-theme-share-code" x-ref="shareCode" type="text" autocomplete="off"
                                            spellcheck="false" :value="shareCode"
                                            @input="readShareCode($event.target.value)"
                                            @blur="$event.target.value = shareCode; shareCodeInvalid = false"
                                            :aria-invalid="shareCodeInvalid"
                                            class="input min-w-0 flex-1 font-mono text-sm"
                                            :class="shareCodeInvalid && 'border-error! ring-error!'" />
                                        <button type="button" @click="copyShareCode()" aria-label="Copy share code"
                                            class="button shrink-0 px-2.5">
                                            <x-reicon name="copy" x-show="!shareCodeCopied" class="size-4" />
                                            <x-reicon name="check" x-show="shareCodeCopied" x-cloak class="size-4 text-success" />
                                        </button>
                                    </div>
                                    <p class="mt-1.5 text-xs"
                                        :class="shareCodeInvalid ? 'text-error' : 'text-neutral-500 dark:text-fg-dim'"
                                        x-text="shareCodeInvalid
                                            ? 'Enter four hex colors, separated by commas.'
                                            : 'Paste a code that someone shared, or copy yours.'"></p>
                                </div>
                            </div>
                        </div>
                    </template>
                    <footer
                        class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-neutral-200 px-4 py-3 dark:border-white/[0.08]">
                        <x-forms.button @click="dialogOpen = false">Cancel</x-forms.button>
                        <x-forms.button isHighlighted @click="saveCustomTheme()">Save theme</x-forms.button>
                    </footer>
                </div>
            </div>
        </div>
    </template>
</div>
