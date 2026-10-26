@props(['variant' => 'full'])

@php($checkPath = 'm2.5 6.25 2.1 2.1 4.9-5')

@if ($variant === 'menu')
    {{-- Compact list for the profile dropdown. The parent controls visibility;
         all theme state/logic comes from the shared window.themeControls(). --}}
    <div x-data="themeControls()" class="grid gap-0.5">
        @foreach ([
            ['value' => 'light', 'label' => 'Light'],
            ['value' => 'system', 'label' => 'System'],
            ['value' => 'dark', 'label' => 'Dark'],
            ['value' => 'custom', 'label' => 'Custom'],
        ] as $option)
            @if ($option['value'] === 'custom')
                <div class="relative" @click.outside="pickerOpen = false">
                    <button type="button" @click="chooseCustom()" aria-haspopup="dialog" :aria-expanded="pickerOpen"
                        class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                        <span class="flex items-center gap-2">
                            <span class="size-3.5 rounded-full border border-white/20"
                                :style="`background: ${themeColor}`"></span>
                            Custom
                        </span>
                        <svg x-show="theme === 'custom'" class="size-3.5 text-black dark:text-fg"
                            viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <x-theme-controls.picker position="left-full top-0 ml-2" />
                </div>
            @else
                <button type="button" @click="setTheme('{{ $option['value'] }}')"
                    class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                    <span>{{ $option['label'] }}</span>
                    <svg x-show="theme === '{{ $option['value'] }}'"
                        class="size-3.5 text-black dark:text-fg" viewBox="0 0 12 12" fill="none"
                        aria-hidden="true">
                        <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            @endif
        @endforeach
        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>
        <div class="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
            Page width
        </div>
        @foreach ([
            ['value' => 'full', 'label' => 'Full width'],
            ['value' => 'centered', 'label' => 'Centered'],
        ] as $option)
            <button type="button" @click="setWidth('{{ $option['value'] }}')"
                class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                <span>{{ $option['label'] }}</span>
                <svg x-show="pageWidth === '{{ $option['value'] }}'"
                    class="size-3.5 text-black dark:text-fg" viewBox="0 0 12 12" fill="none"
                    aria-hidden="true">
                    <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        @endforeach
        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>
        <div class="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
            Navigation
        </div>
        @foreach ([
            ['value' => 'default', 'label' => 'Default'],
            ['value' => 'floating', 'label' => 'Floating'],
        ] as $option)
            <button type="button" @click="setNavStyle('{{ $option['value'] }}')"
                class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                <span>{{ $option['label'] }}</span>
                <svg x-show="navStyle === '{{ $option['value'] }}'"
                    class="size-3.5 text-black dark:text-fg" viewBox="0 0 12 12" fill="none"
                    aria-hidden="true">
                    <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        @endforeach
        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>
        <div class="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
            Navigation items
        </div>
        @foreach ([
            ['value' => '3d', 'label' => '3D'],
            ['value' => 'flat', 'label' => 'Flat'],
        ] as $option)
            <button type="button" @click="setNavItems('{{ $option['value'] }}')"
                class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                <span>{{ $option['label'] }}</span>
                <svg x-show="navItems === '{{ $option['value'] }}'"
                    class="size-3.5 text-black dark:text-fg" viewBox="0 0 12 12" fill="none"
                    aria-hidden="true">
                    <path d="{{ $checkPath }}" stroke="currentColor" stroke-width="1.4"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        @endforeach
    </div>
@else
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
                    ['value' => 'custom', 'label' => 'Custom', 'description' => 'Tint light or dark surfaces with any color.', 'preview' => ''],
                ] as $option)
                    @if ($option['value'] === 'custom')
                        {{-- Clicking the card opens the custom color picker popover (color + light/dark). --}}
                        <div class="relative" @click.outside="pickerOpen = false">
                            <div role="button" tabindex="0" @click="chooseCustom()" @keydown.enter.prevent="chooseCustom()"
                                aria-haspopup="dialog" :aria-expanded="pickerOpen"
                                class="group block w-full overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                                :class="theme === 'custom'
                                    ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                                    : ''">
                                <div class="h-20 border-b border-neutral-200 dark:border-white/[0.07]"
                                    :style="`background: color-mix(in oklab, ${themeColor} 28%, ${customMode === 'light' ? 'oklch(97% 0 0)' : 'oklch(17.35% 0.0020 286.18)'})`">
                                    <div class="flex h-full items-center justify-center">
                                        <div class="h-10 w-20 rounded-md border border-white/15 p-1 shadow-sm">
                                            <div class="h-full w-full rounded-sm" :style="`background: ${themeColor}`"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                                        <x-reicon name="check-circle" class="size-4 text-black dark:text-fg"
                                            x-show="theme === 'custom'" x-cloak />
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                        {{ $option['description'] }}
                                    </p>
                                </div>
                            </div>
                            <x-theme-controls.picker position="left-0 top-full mt-2" />
                        </div>
                    @else
                        <div role="button" tabindex="0"
                            @click="setTheme('{{ $option['value'] }}')"
                            @keydown.enter.prevent="setTheme('{{ $option['value'] }}')"
                            class="group relative overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
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
                                    <x-reicon name="check-circle" class="size-4 text-black dark:text-fg"
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
                                <x-reicon name="check-circle" class="size-4 text-black dark:text-fg"
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
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
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
                                <x-reicon name="check-circle" class="size-4 text-black dark:text-fg"
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
                    <h2>Navigation items</h2>
                    <p>Choose how the main sidebar shows its items.</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['value' => '3d', 'label' => '3D', 'description' => 'Each icon sits in a raised tile that lifts on hover.'],
                    ['value' => 'flat', 'label' => 'Flat', 'description' => 'Plain rows with a highlight for the current page.'],
                ] as $option)
                    <button type="button" @click="setNavItems('{{ $option['value'] }}')"
                        class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:border-white/[0.12]"
                        :class="navItems === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
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
                                <x-reicon name="check-circle" class="size-4 text-black dark:text-fg"
                                    x-show="navItems === '{{ $option['value'] }}'" x-cloak />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    </div>
@endif
