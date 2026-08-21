@props(['renderers', 'rendererShortNames'])

<div class="relative shrink-0" @click.outside="rendererOpen = false">
    <button type="button"
        class="terminal-theme-trigger flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-white/70 transition-colors hover:bg-white/[0.08] hover:text-white"
        @click="rendererOpen = !rendererOpen" aria-label="Choose terminal renderer" :aria-expanded="rendererOpen">
        <svg class="size-3.5 text-white/45" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 5h16v14H4zM8 10l2.5 2-2.5 2M12.5 14h3.5" stroke="currentColor" stroke-width="1.6"
                stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span x-text="@js($rendererShortNames)[consoleRenderer]"></span>
        <svg class="size-2.5 text-white/35" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="m3.5 4.75 2.5 2.5 2.5-2.5" stroke="currentColor" stroke-width="1.25"
                stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>

    <div x-cloak x-show="rendererOpen" x-transition.origin.top.right
        class="console-theme-selector absolute top-11 right-0 z-50 w-60 overflow-y-auto rounded-lg border border-neutral-200 bg-white p-1 shadow-[0_18px_50px_rgba(0,0,0,0.18)] dark:border-white/[0.1] dark:bg-[#111113] dark:shadow-[0_18px_50px_rgba(0,0,0,0.55)]">
        @foreach ($renderers as $renderer)
            <button type="button"
                class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-neutral-950 dark:text-white/65 dark:hover:bg-white/[0.07] dark:hover:text-white"
                @click="setRenderer('{{ $renderer['key'] }}')">
                <span class="min-w-0 flex-1">
                    <span class="block text-[12px] font-medium">{{ $renderer['name'] }}</span>
                    <span class="mt-0.5 block text-[10px] text-neutral-400 dark:text-white/35">{{ $renderer['description'] }}</span>
                </span>
                <svg x-show="consoleRenderer === '{{ $renderer['key'] }}'" class="mt-0.5 size-3 shrink-0 text-[#fcd452]"
                    viewBox="0 0 12 12" fill="none" aria-hidden="true">
                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        @endforeach
    </div>
</div>
