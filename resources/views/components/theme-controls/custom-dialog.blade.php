@props(['description'])

{{-- Custom theme dialog: presets, a preview, four colors, a share code, and the iTerm2 import.
     Needs the Alpine state of window.customThemeEditor(); its saveCustomTheme() applies the draft. --}}
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
                            {{ $description }}
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
                            <div class="grid grid-cols-3 gap-2 sm:gap-3">
                                <template x-for="preset in presets" :key="preset.name">
                                    <button type="button" @click="setDraft(preset.theme && { ...preset.theme })"
                                        :aria-pressed="isDraftPreset(preset)"
                                        class="group flex flex-col gap-1.5 rounded-lg p-1 text-left outline-0 focus-visible:ring-1 focus-visible:ring-accent">
                                        <x-theme-controls.swatch theme="(preset.theme ?? preset.swatch)"
                                            class="h-14 w-full transition-shadow group-hover:shadow-md"
                                            ::class="isDraftPreset(preset) && 'ring-2 ring-coollabs ring-offset-2 ring-offset-white dark:ring-brand-light dark:ring-offset-panel'" />
                                        <span class="truncate text-xs text-neutral-600 dark:text-fg-dim" x-text="preset.name" :title="preset.name"></span>
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
                                            :style="`background: ${accentFill(draftColors.accent)}; color: ${accentTextOn(draftColors.accent)}`">3</span>
                                    </div>
                                </div>
                                <div class="flex min-w-0 flex-1 flex-col gap-2 p-3"
                                    :style="`background: ${draftColors.background}; color: ${textOn(draftColors.background)}`">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold">Projects</span>
                                        <span class="rounded-md px-2 py-1 text-[11px] font-medium"
                                            :style="`background: ${accentFill(draftColors.accent)}; color: ${accentTextOn(draftColors.accent)}`">+ New</span>
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
                                    <button type="button" @click="$refs.itermFile.click()" class="button shrink-0 normal-case">
                                        Import iTerm2
                                    </button>
                                    <input x-ref="itermFile" type="file" accept=".itermcolors,.xml,.plist" class="hidden"
                                        aria-label="iTerm2 color file" @change="importItermFile($event.target)" />
                                </div>
                                <p class="mt-1.5 text-xs"
                                    :class="shareCodeInvalid ? 'text-error' : 'text-neutral-500 dark:text-fg-dim'"
                                    x-text="shareCodeInvalid
                                        ? 'Enter four hex colors, separated by commas, or an iTerm2 color file.'
                                        : 'Paste a code that someone shared or an iTerm2 .itermcolors file, or copy your code.'"></p>
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
