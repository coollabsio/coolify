@props(['themes', 'themeNames', 'themeAccents'])

<div class="relative ml-auto shrink-0" @click.outside="themeOpen = false">
    <button type="button"
        class="terminal-theme-trigger flex h-8 items-center gap-2 rounded-md px-2.5 text-xs font-medium text-white/70 transition-colors hover:bg-white/[0.08] hover:text-white"
        @click="themeOpen = !themeOpen" aria-label="Choose terminal theme" :aria-expanded="themeOpen">
        <span class="size-2 rounded-full ring-1 ring-white/20"
            :style="{ backgroundColor: @js($themeAccents)[consoleTheme] }"></span>
        <span x-text="@js($themeNames)[consoleTheme]"></span>
        <svg class="size-2.5 text-white/35" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="m3.5 4.75 2.5 2.5 2.5-2.5" stroke="currentColor" stroke-width="1.25"
                stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>

    <div x-cloak x-show="themeOpen" x-transition.origin.top.right
        class="console-theme-selector absolute top-11 right-0 z-50 max-h-80 w-56 overflow-y-auto rounded-lg border border-neutral-200 bg-white p-1 shadow-[0_18px_50px_rgba(0,0,0,0.18)] dark:border-white/[0.1] dark:bg-[#111113] dark:shadow-[0_18px_50px_rgba(0,0,0,0.55)]">
        @foreach ($themes as $theme)
            <button type="button"
                class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-[11px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-neutral-950 dark:text-white/65 dark:hover:bg-white/[0.07] dark:hover:text-white"
                @click="setTheme('{{ $theme['key'] }}')">
                <span class="h-3 w-5 rounded-full border border-white/10"
                    style="background: {{ $theme['background'] }}"></span>
                <span class="flex-1">{{ $theme['name'] }}</span>
                <svg x-show="consoleTheme === '{{ $theme['key'] }}'" class="size-3 text-[#fcd452]"
                    viewBox="0 0 12 12" fill="none" aria-hidden="true">
                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        @endforeach
    </div>
</div>
