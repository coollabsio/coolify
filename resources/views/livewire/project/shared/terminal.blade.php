@php
    $isApplicationConsole = $variant === 'application';
@endphp

<div id="terminal-container" x-data="terminalData()" data-auto-start="{{ $autoStart ? 'true' : 'false' }}"
    x-on:terminal-starting.window="starting = true; setTerminalTheme(localStorage.getItem('coolify-console-theme') ?? 'system')"
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
                    description="This container does not include Bash or sh. Install a supported shell to use the terminal."
                    icon-name="browser-terminal" />
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

    <div x-ref="terminalWrapper" wire:ignore
        :data-console-theme="fullscreen ? selectedTheme : null"
        :class="fullscreen
            ? 'terminal-fullscreen-shell fixed inset-0 z-[100000] m-0 flex w-screen max-w-none flex-col overflow-hidden rounded-none p-0'
            : @js($isApplicationConsole
                ? 'relative flex h-full min-h-0 w-full flex-col overflow-hidden bg-transparent'
                : 'relative flex w-full h-full max-h-[510px] flex-col py-4 mx-auto')">
        @if ($isApplicationConsole)
            <div x-show="!terminalActive" x-cloak
                class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center bg-transparent">
                <div class="terminal-loading-label flex items-center gap-2">
                    <svg x-show="starting || connectionState === 'connecting' || connectionState === 'reconnecting'"
                        class="size-3 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor"
                            stroke-width="3" />
                        <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor"
                            stroke-width="3" stroke-linecap="round" />
                    </svg>
                    <span
                        x-text="connectionState === 'reconnecting' ? `reconnecting… (attempt ${reconnectAttempts})` : (starting ? 'connecting…' : (connectionState === 'connecting' ? 'connecting…' : 'choose a container to start a session'))"></span>
                </div>
            </div>
        @else
            <div x-show="terminalActive" x-cloak class="mb-2 flex shrink-0 justify-start">
                <div class="inline-flex rounded-sm border px-2 py-1 text-xs font-medium"
                    :class="terminalSessionTimerClass()" x-text="terminalSessionRemainingLabel()">
                </div>
            </div>
        @endif

        <div id="terminal" wire:ignore data-terminal-style="{{ $isApplicationConsole ? 'application' : 'default' }}"
            :class="fullscreen
                ? 'terminal-host relative z-[1] min-h-0 flex-1 overflow-hidden px-1 py-[5px] bg-transparent'
                : @js($isApplicationConsole
                    ? 'terminal-host relative min-h-0 flex-1 overflow-hidden pt-[5px] pr-px pb-[5px] pl-1 bg-transparent'
                    : 'terminal-host h-[510px] max-h-[calc(100dvh-10rem)] overflow-hidden px-2 py-1 rounded-sm bg-black')">
        </div>

        <div x-show="terminalActive" x-cloak class="sm:hidden"
            :class="fullscreen ? 'relative z-[2] shrink-0 px-2 pb-2' : (keyboardInset > 0 ? 'fixed inset-x-0 z-[100002] px-2 pb-2' : 'relative z-[2] mt-2 shrink-0')"
            :style="!fullscreen && keyboardInset > 0 ? `top: ${keyboardAnchorTop}px; transform: translateY(-100%)` : ''"
            data-terminal-mobile-toolbar>
            <div class="terminal-key-row mx-auto flex max-w-3xl gap-1.5 overflow-x-auto whitespace-nowrap rounded-lg px-2 py-1.5 text-white [scrollbar-width:thin]">
                <button type="button" class="terminal-mobile-key" x-on:click="pasteFromClipboard()">paste</button>
                <button type="button" class="terminal-mobile-key" x-on:click="copyTerminalSelection()">copy</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('escape')">ESC</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('tab')">tab</button>
                <button type="button" class="terminal-mobile-key"
                    :class="terminalModifier === 'ctrl' ? 'border-white/35 bg-white/20 text-white' : ''"
                    x-on:click="toggleTerminalModifier('ctrl')">ctrl</button>
                <button type="button" class="terminal-mobile-key"
                    :class="terminalModifier === 'alt' ? 'border-white/35 bg-white/20 text-white' : ''"
                    x-on:click="toggleTerminalModifier('alt')">alt</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalKey('/')">/</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalKey('|')">|</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalKey('~')">~</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalKey('-')">-</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('ctrlC')">^C</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('ctrlD')">^D</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('ctrlBackslash')">^\</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('ctrlS')">^S</button>
                <button type="button" class="terminal-mobile-key" x-on:click="sendTerminalControl('ctrlZ')">^Z</button>
            </div>
        </div>

        {{-- Enter/exit use identical chrome so toggle does not jump size or gain/lose a box. --}}
        <button type="button" title="Exit fullscreen" x-cloak x-show="fullscreen"
            class="terminal-fullscreen-btn fixed top-3 right-3 z-[100001]"
            x-on:click="makeFullscreen">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M9 4v5H4M15 20v-5h5M20 9h-5V4M4 15h5v5" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <button type="button" title="Fullscreen" x-cloak x-show="!fullscreen && terminalActive"
            @class([
                'terminal-fullscreen-btn absolute z-20',
                'right-2 top-2 opacity-100 sm:opacity-0 sm:group-hover/terminal:opacity-100 sm:focus-visible:opacity-100' => $isApplicationConsole,
                'right-5 top-6' => !$isApplicationConsole,
            ])
            x-on:click="makeFullscreen">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
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
