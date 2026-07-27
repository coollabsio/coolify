@php
    $isApplicationConsole = $variant === 'application';
@endphp

<div id="terminal-container" x-data="terminalData()"
    x-on:terminal-theme-change.window="setTerminalTheme($event.detail.theme)"
    @class([
        'group/terminal relative h-full min-h-0 bg-transparent' => $isApplicationConsole,
    ])>
    @if ($isTerminalConnected)
        <div class="hidden" aria-hidden="true" wire:poll.keep-alive.30s="keepTerminalPageAlive"></div>
    @endif

    @if (!$hasShell)
        @if ($isApplicationConsole)
            <div class="flex h-full min-h-[32rem] items-center justify-center">
                <x-empty size="lg" title="Shell unavailable"
                    description="This container does not include Bash or sh. Install a supported shell to use the console.">
                    <x-slot:icon>
                        <x-reicon name="terminal" class="size-9" />
                    </x-slot:icon>
                </x-empty>
            </div>
        @else
            <div class="flex pt-4 items-center justify-center w-full py-4 mx-auto">
                <div class="p-4 w-full rounded-sm border dark:bg-coolgray-100 dark:border-coolgray-300">
                    <div class="flex flex-col items-center justify-center space-y-4">
                        <svg class="w-12 h-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div class="text-center">
                            <h3 class="text-lg font-medium">Terminal Not Available</h3>
                            <p class="mt-2 text-sm text-neutral-300">No shell (bash/sh) is available in this container.
                                Please ensure either bash or sh is installed to use the terminal.</p>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    <div x-ref="terminalWrapper"
        :class="fullscreen
            ? 'fixed inset-0 z-[9999] m-0 h-[100dvh] w-screen max-w-none overflow-hidden rounded-none bg-[#141414] p-0'
            : @js($isApplicationConsole
                ? 'relative h-full min-h-0 w-full overflow-hidden bg-transparent'
                : 'relative w-full h-full py-4 mx-auto max-h-[510px]')">
        @if ($isApplicationConsole)
            <div x-show="!terminalActive" x-cloak
                class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center bg-black/35">
                <div class="flex items-center gap-2 text-[11px] text-[#6e6e74]">
                    <svg x-show="connectionState === 'connecting' || connectionState === 'reconnecting'"
                        class="size-3 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor"
                            stroke-width="3" />
                        <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor"
                            stroke-width="3" stroke-linecap="round" />
                    </svg>
                    <span
                        x-text="connectionState === 'connecting' ? 'connecting…' : (connectionState === 'reconnecting' ? `reconnecting… (attempt ${reconnectAttempts})` : 'choose a container to start a session')"></span>
                </div>
            </div>
            <div x-show="terminalActive" x-cloak
                class="pointer-events-none absolute right-3 bottom-2 z-20 font-mono text-[10px] text-white/25"
                x-text="terminalSessionRemainingLabel()">
            </div>
        @else
            <div x-show="terminalActive" x-cloak class="mb-2 flex justify-start">
                <div class="inline-flex rounded-sm border px-2 py-1 text-xs font-medium"
                    :class="terminalSessionTimerClass()" x-text="terminalSessionRemainingLabel()">
                </div>
            </div>
        @endif

        <div id="terminal" wire:ignore data-terminal-style="{{ $isApplicationConsole ? 'application' : 'default' }}"
            :class="fullscreen
                ? (mobileToolbarCollapsed
                    ? 'h-[calc(100dvh-3.5rem)] mb-14 px-1 py-[5px] bg-[#141414]'
                    : 'h-[calc(100dvh-6rem)] mb-[6rem] px-1 py-[5px] bg-[#141414]')
                : @js($isApplicationConsole
                    ? 'h-full min-h-0 overflow-hidden pt-[5px] pr-px pb-[5px] pl-1 bg-transparent'
                    : 'h-[510px] max-h-[calc(100dvh-10rem)] overflow-hidden px-2 py-1 rounded-sm bg-black')"
            x-show="terminalActive">
        </div>

        <div x-show="terminalActive" x-cloak
            :class="fullscreen ? 'absolute inset-x-0 bottom-0 z-[9999] px-2 pb-2' : 'relative mt-2'"
            class="sm:hidden" data-terminal-mobile-toolbar>
            <div
                class="mx-auto max-w-3xl rounded-lg border border-white/10 bg-black/90 p-1.5 text-white shadow-lg backdrop-blur">
                <div class="flex items-center justify-between gap-2">
                    <span class="px-2 text-[11px] font-medium uppercase tracking-wide text-neutral-400">Terminal keys</span>
                    <button type="button"
                        class="rounded px-2 py-1 text-xs text-neutral-300 hover:bg-white/10 hover:text-white"
                        x-on:click="mobileToolbarCollapsed = !mobileToolbarCollapsed; $nextTick(() => resizeTerminal())"
                        x-text="mobileToolbarCollapsed ? 'Show' : 'Hide'"
                        aria-label="Toggle mobile terminal toolbar"></button>
                </div>
                <div x-show="!mobileToolbarCollapsed" class="mt-1 grid grid-cols-6 gap-1">
                    <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('arrowUp')"
                        aria-label="Previous command">↑</button>
                    <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('arrowDown')"
                        aria-label="Next command">↓</button>
                    <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('arrowLeft')"
                        aria-label="Move cursor left">←</button>
                    <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('arrowRight')"
                        aria-label="Move cursor right">→</button>
                    <button type="button" class="terminal-mobile-key"
                        x-on:click="sendTerminalControl('tab')">Tab</button>
                    <button type="button" class="terminal-mobile-key"
                        x-on:click="sendTerminalControl('escape')">Esc</button>
                </div>
            </div>
        </div>

        <button type="button" title="Exit fullscreen" x-cloak x-show="fullscreen"
            class="fixed top-3 right-3 z-[10000] flex size-7 items-center justify-center rounded-md border border-white/[0.08] bg-black/60 text-fg-dim backdrop-blur transition-colors hover:bg-white/[0.08] hover:text-fg"
            x-on:click="makeFullscreen">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                <path d="M9 4v5H4M15 20v-5h5M20 9h-5V4M4 15h5v5" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <button type="button" title="Fullscreen" x-cloak x-show="!fullscreen && terminalActive"
            @class([
                'absolute z-20 flex size-7 items-center justify-center rounded-md transition-all',
                'right-2 top-2 text-fg-faint opacity-0 hover:bg-white/[0.07] hover:text-fg group-hover/terminal:opacity-100 focus-visible:opacity-100' => $isApplicationConsole,
                'right-5 top-6 text-white' => !$isApplicationConsole,
            ])
            x-on:click="makeFullscreen">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                <path d="M9 4H4v5M15 4h5v5M20 15v5h-5M4 15v5h5" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>

    @script
        <script>
            window.terminalConfig = {
                protocol: "{{ config('constants.terminal.protocol') }}",
                host: "{{ config('constants.terminal.host') }}",
                port: "{{ config('constants.terminal.port') }}"
            }
        </script>
    @endscript
</div>
