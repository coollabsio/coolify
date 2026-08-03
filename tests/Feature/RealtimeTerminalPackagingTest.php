<?php

it('copies the realtime terminal utilities into the container image', function () {
    $dockerfile = file_get_contents(base_path('docker/coolify-realtime/Dockerfile'));

    expect($dockerfile)->toContain('COPY docker/coolify-realtime/terminal-utils.js /terminal/terminal-utils.js');
});

it('mounts the realtime terminal utilities in local development compose files', function (string $composeFile) {
    $composeContents = file_get_contents(base_path($composeFile));

    expect($composeContents)->toContain('./docker/coolify-realtime/terminal-utils.js:/terminal/terminal-utils.js');
})->with([
    'default dev compose' => 'docker-compose.dev.yml',
    'maxio dev compose' => 'docker-compose-maxio.dev.yml',
]);

it('keeps terminal browser logging restricted to Vite development mode', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('const terminalDebugEnabled = import.meta.env.DEV;')
        ->toContain("logTerminal('log', '[Terminal] WebSocket connection established.');")
        ->not->toContain("console.log('[Terminal] WebSocket connection established. Cool cool cool cool cool cool.');");
});

it('keeps realtime terminal server logging behind the explicit debug flag', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain('const debugOverride = String(process.env.TERMINAL_DEBUG')
        ->toContain("['1', 'true', 'yes', 'on'].includes(debugOverride)")
        ->toContain('if (!terminalDebugEnabled) {')
        ->not->toContain("console.log('Coolify realtime terminal server listening on port 6002. Let the hacking begin!');");
});

it('configures a server-initiated WebSocket heartbeat to survive proxy idle timeouts', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain('ws.isAlive = true;')
        ->toContain("ws.on('pong'")
        ->toContain('ws.ping();')
        ->toContain('ws.terminate();')
        ->toContain('HEARTBEAT_INTERVAL_MS');
});

it('removes the keepalive short-circuit that fired when the tab was hidden', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->not->toContain('// Skip keepalive when document is hidden to prevent unnecessary disconnects');
});

it('uses a fast probe timeout when the tab regains visibility', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain("'Visibility-resume timeout'");
});

it('does not hard close terminal sessions after 30 minutes on the server', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->not->toContain('IDLE_TIMEOUT_MS = 30 * 60 * 1000')
        ->not->toContain("ws.send('idle-timeout');")
        ->not->toContain("ws.close(1000, 'Idle timeout');");
});

it('does not close the client terminal from an idle-timeout sentinel', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->not->toContain("event.data === 'idle-timeout'")
        ->not->toContain('Terminal closed after 30 minutes of inactivity.');
});

it('keeps Livewire alive in background tabs while a terminal is connected', function () {
    $terminalComponent = file_get_contents(base_path('app/Livewire/Project/Shared/Terminal.php'));
    $terminalView = file_get_contents(base_path('resources/views/livewire/project/shared/terminal.blade.php'));

    expect($terminalComponent)
        ->toContain('public bool $isTerminalConnected = false;')
        ->toContain("#[On('terminalConnected')]")
        ->toContain('public function markTerminalConnected(): void')
        ->toContain('public function keepTerminalPageAlive(): void')
        ->and($terminalView)
        ->toContain('@if ($isTerminalConnected)')
        ->toContain('wire:poll.keep-alive.30s="keepTerminalPageAlive"');
});

it('exits fullscreen when the terminal process exits', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain("event.data === 'pty-exited'")
        ->toContain('this.exitFullscreen();')
        ->toContain('this.mobileToolbarCollapsed = false;
                    this.terminalActive = false;');
});

it('replays the last command on reconnect so the PTY respawns automatically', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('lastSentCommand')
        ->toContain('Replaying last command after reconnect.')
        ->toContain('this.lastSentCommand = null;');
});

it('buffers messages received before the realtime server finishes auth so the replay is not lost', function () {
    $terminalServer = file_get_contents(base_path('docker/coolify-realtime/terminal-server.js'));

    expect($terminalServer)
        ->toContain('authReady: false')
        ->toContain('pendingMessages: []')
        ->toContain('userSession.pendingMessages.push(message)')
        ->toContain('userSession.authReady = true');
});

it('preserves terminal scrollback across transient reconnects', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('── Connection lost at')
        ->toContain('── Reconnected at')
        // resetTerminal must NOT call term.reset()/term.clear() any more — those wipe scrollback.
        ->not->toContain("this.term.reset();\n                    this.term.clear();");
});

it('renders a compact mobile terminal toolbar with shell control keys', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($terminalView)
        ->toContain('Terminal keys')
        ->toContain('sm:hidden')
        ->toContain("sendTerminalControl('arrowUp')")
        ->toContain("sendTerminalControl('arrowDown')")
        ->toContain("sendTerminalControl('arrowLeft')")
        ->toContain("sendTerminalControl('arrowRight')")
        ->toContain("sendTerminalControl('tab')")
        ->toContain("sendTerminalControl('escape')")
        ->not->toContain("sendTerminalControl('ctrlC')")
        ->not->toContain('pasteFromClipboard()')
        ->not->toContain('copyTerminalSelection()')
        ->toContain('mobileToolbarCollapsed')
        ->toContain("fullscreen ? 'absolute inset-x-0 bottom-0 z-[2] px-2 pb-2' : 'relative mt-2 shrink-0'")
        ->toContain('data-terminal-mobile-toolbar')
        ->and($appCss)
        ->toContain('.terminal-mobile-key');
});

it('sends terminal mobile toolbar controls through the websocket', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain('sendTerminalInput(data)')
        ->toContain('sendTerminalControl(sequence)')
        ->toContain("arrowUp: '\\x1b[A'")
        ->toContain("arrowDown: '\\x1b[B'")
        ->toContain("arrowRight: '\\x1b[C'")
        ->toContain("arrowLeft: '\\x1b[D'")
        ->toContain("tab: '\\t'")
        ->toContain("escape: '\\x1b'")
        ->toContain("ctrlC: '\\x03'")
        ->toContain('navigator.clipboard.readText()')
        ->toContain('navigator.clipboard.writeText(selection)');
});

it('uses terminal host dimensions when resizing so mobile controls do not cover terminal rows', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain("document.getElementById('terminal')")
        ->toContain('this.fitAddon.fit()')
        ->toContain('terminalElement.clientHeight')
        ->not->toContain('const wrapperHeight = this.$refs.terminalWrapper.clientHeight;');
});

it('uses simple fullscreen bottom margin based on mobile toolbar visibility', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalClient)
        ->not->toContain('updateFullscreenLayout()')
        ->not->toContain('terminalFullscreenHeight')
        ->not->toContain('window.visualViewport?.height')
        ->and($terminalView)
        ->toContain("mobileToolbarCollapsed\n                    ? 'terminal-host relative z-[1] min-h-0 flex-1 overflow-hidden px-1 py-[5px] bg-transparent max-sm:pb-14'\n                    : 'terminal-host relative z-[1] min-h-0 flex-1 overflow-hidden px-1 py-[5px] bg-transparent max-sm:pb-24'")
        ->toContain("fullscreen ? 'absolute inset-x-0 bottom-0 z-[2] px-2 pb-2'");
});

it('resizes after toggling the mobile terminal toolbar', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toContain('$nextTick(() => resizeTerminal())');
});

it('uses fixed viewport positioning for fullscreen terminal instead of inherited container size', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toContain('terminal-fullscreen-shell fixed inset-0')
        ->toContain('z-[100000]')
        ->toContain('h-[100dvh]')
        ->toContain('w-screen')
        ->toContain('max-w-none')
        ->toContain('overflow-hidden')
        ->toContain(':data-console-theme="fullscreen ? selectedTheme : null"');
});

it('constrains normal terminal height after leaving fullscreen', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toContain('h-[510px] max-h-[calc(100dvh-10rem)] overflow-hidden');
});

it('keeps enter and exit fullscreen controls the same size and chrome', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($terminalView)
        ->toContain('class="terminal-fullscreen-btn fixed top-3 right-3 z-[100001]"')
        ->toContain("'terminal-fullscreen-btn absolute z-20'")
        ->and($appCss)
        ->toContain('.terminal-fullscreen-btn')
        ->toContain('width: 1.75rem')
        ->toContain('height: 1.75rem');
});

it('does not apply backdrop-filter on the console block itself so fixed fullscreen can escape', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($appCss)
        ->toContain('.application-console-block::before')
        ->toContain('position:fixed descendants')
        ->toMatch('/\.application-console-block::before\s*\{[^}]*backdrop-filter/s')
        ->not->toMatch('/\.application-console-block\s*\{[^}]*\bbackdrop-filter\s*:/s');
});

it('keeps the terminal in the Livewire tree and unlocks ancestor stacking for fullscreen', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($terminalClient)
        ->toContain('enterFullscreen()')
        ->toContain('exitFullscreen()')
        ->toContain('patchAncestorsForFullscreen(wrapper)')
        ->toContain('restoreAncestorsAfterFullscreen()')
        ->toContain('salvageStrayFullscreenNodes()')
        ->toContain("node.style.setProperty('isolation', 'auto', 'important')")
        ->toContain("node.style.setProperty('transform', 'none', 'important')")
        ->toContain("node.style.setProperty('z-index', 'auto', 'important')")
        ->toContain("main.style.setProperty('z-index', '100001', 'important')")
        ->toContain("fromEl.closest('main')")
        ->toContain('scheduleTerminalResize()')
        ->not->toContain('document.body.appendChild(wrapper)')
        ->not->toContain('enterFullscreenPortal')
        ->and($terminalView)
        ->toContain('wire:ignore')
        ->toContain('terminal-fullscreen-shell fixed inset-0')
        ->and($appCss)
        ->toContain('body.terminal-is-fullscreen .terminal-fullscreen-shell')
        ->toContain('z-index: 100000 !important');
});

it('locks page scroll while the terminal is fullscreen and unlocks on exit', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($terminalClient)
        ->toContain('lockPageScroll()')
        ->toContain('unlockPageScroll()')
        ->toContain("document.body.style.setProperty('position', 'fixed', 'important')")
        ->toContain("window.addEventListener('wheel', this.preventPageScrollHandler")
        ->toContain("window.addEventListener('touchmove', this.preventPageScrollHandler")
        ->toContain("target.closest('.xterm-viewport')")
        ->toContain("document.documentElement.classList.add('terminal-is-fullscreen')")
        ->toContain("document.documentElement.classList.remove('terminal-is-fullscreen')")
        ->and($appCss)
        ->toContain('html.terminal-is-fullscreen')
        ->toContain('overscroll-behavior: none !important')
        ->toContain('touch-action: none');
});

it('applies the selected console theme to the fullscreen shell', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($terminalView)
        ->toContain('terminal-fullscreen-shell')
        ->toContain(':data-console-theme="fullscreen ? selectedTheme : null"')
        ->not->toContain('bg-[#141414]')
        ->and($appCss)
        ->toContain('.terminal-fullscreen-shell')
        ->toContain('.terminal-fullscreen-shell[data-console-theme="shadows-cosmic-purple"]')
        ->toContain('#terminal.terminal-host .xterm-viewport');
});

it('fits the terminal with FitAddon and keeps xterm within the host after resize', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain('this.fitAddon.fit()')
        ->toContain("this.term.element.style.maxHeight = '100%'")
        ->toContain('scrollback: 5000')
        ->not->toContain('Math.floor(height / charSize.height) - 1');
});
