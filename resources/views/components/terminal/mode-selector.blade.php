@php
    // Shell (docker exec) vs Console (docker attach). Shown only when the container is
    // interactive (keeps stdin open). Alpine state lives on the parent terminal section:
    // attachAvailable, terminalMode, modeOpen, setTerminalMode().
    $modes = [
        ['key' => 'shell', 'name' => 'Shell', 'description' => 'Interactive shell (docker exec)'],
        ['key' => 'attach', 'name' => 'Console', 'description' => 'Attach to the app process (docker attach)'],
    ];
    $modeNames = collect($modes)->pluck('name', 'key');
@endphp

<div x-cloak x-show="attachAvailable" class="relative shrink-0" @click.outside="modeOpen = false"
    @keydown.escape.window="modeOpen = false">
    <button type="button"
        class="terminal-theme-trigger flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-white/70 transition-colors hover:bg-white/[0.08] hover:text-white"
        @click="modeOpen = !modeOpen" aria-label="Choose terminal mode" aria-haspopup="menu" :aria-expanded="modeOpen">
        <svg class="size-3.5 text-white/45" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="m5 8 3 3-3 3M12 16h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
                stroke-linejoin="round" />
        </svg>
        <span x-text="@js($modeNames)[terminalMode]"></span>
        <svg class="size-2.5 text-white/35" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="m3.5 4.75 2.5 2.5 2.5-2.5" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"
                stroke-linejoin="round" />
        </svg>
    </button>

    <div x-cloak x-show="modeOpen" x-transition.origin.top.right role="menu"
        class="console-theme-selector absolute top-11 right-0 z-50 w-64 overflow-y-auto rounded-lg border border-neutral-200 bg-white p-1 shadow-[0_18px_50px_rgba(0,0,0,0.18)] dark:border-white/[0.1] dark:bg-[#111113] dark:shadow-[0_18px_50px_rgba(0,0,0,0.55)]">
        @foreach ($modes as $mode)
            <button type="button" role="menuitemradio" :aria-checked="terminalMode === '{{ $mode['key'] }}'"
                class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-neutral-950 dark:text-white/65 dark:hover:bg-white/[0.07] dark:hover:text-white"
                @click="setTerminalMode('{{ $mode['key'] }}')">
                <span class="min-w-0 flex-1">
                    <span class="block text-[12px] font-medium">{{ $mode['name'] }}</span>
                    <span
                        class="mt-0.5 block text-[10px] text-neutral-400 dark:text-white/35">{{ $mode['description'] }}</span>
                </span>
                <svg x-show="terminalMode === '{{ $mode['key'] }}'" class="mt-0.5 size-3 shrink-0 text-[#fcd452]"
                    viewBox="0 0 12 12" fill="none" aria-hidden="true">
                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            </button>
        @endforeach
    </div>
</div>
