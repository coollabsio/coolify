<?php

it('renders the resource terminal shell while containers are discovered', function () {
    $terminalComponent = file_get_contents(app_path('Livewire/Project/Shared/ExecuteContainerCommand.php'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($terminalComponent)
        ->toContain('public bool $containersLoaded = false;')
        ->and($terminalView)
        ->toContain('wire:init="loadContainers"')
        ->not->toContain('@if (! $containersLoaded)')
        ->not->toContain('Loading terminal containers…')
        ->toContain(':auto-start="$type === \'server\' || ! $containersLoaded || $containers->count() === 1"')
        ->not->toContain('Loading targets…')
        ->not->toContain('<x-loading text="Loading containers" />');
});

it('marks stopped services as loaded before the terminal page first renders', function () {
    $terminalComponent = file_get_contents(app_path('Livewire/Project/Shared/ExecuteContainerCommand.php'));

    expect($terminalComponent)
        ->toMatch('/elseif \(data_get\(\$this->parameters, \'service_uuid\'\)\).*?if \(! \$this->resource->isRunning\(\)\) \{\s*\$this->containersLoaded = true;\s*\}/s');
});

it('provides opt-in diagnostics for connected terminal theme changes', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain('terminal-debug')
        ->toContain("'[Terminal Theme] Applying theme'")
        ->toContain("'[Terminal Theme] Theme applied'")
        ->toContain('requestedTheme: themeName')
        ->toContain('shellTheme: shell?.dataset.consoleTheme')
        ->toContain("getComputedStyle(shell, '::before').background");
});

it('starts a single discovered resource container without waiting for a missed browser event', function () {
    $terminalComponent = file_get_contents(app_path('Livewire/Project/Shared/ExecuteContainerCommand.php'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($terminalComponent)
        ->toMatch('/if \(\$this->containers->count\(\) === 1\) \{\s*\$this->selected_container = [^;]+;\s*\$this->connectToContainer\(\);/s')
        ->and($terminalView)
        ->not->toContain('x-on:containers-loaded.window');
});

it('integrates automatic terminal startup into the terminal console', function () {
    $terminalComponent = file_get_contents(app_path('Livewire/Project/Shared/Terminal.php'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $commandView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($terminalComponent)
        ->toContain('public bool $autoStart = false;')
        ->and($terminalView)
        ->toContain('data-auto-start="{{ $autoStart ? \'true\' : \'false\' }}"')
        ->toContain("starting ? 'connecting…'")
        ->and($commandView)
        ->toContain(':auto-start="$type === \'server\' || ! $containersLoaded || $containers->count() === 1"')
        ->not->toContain('terminalLoading')
        ->not->toContain('Starting terminal');
});

it('resynchronizes the themed canvas whenever a terminal enters a loading state', function () {
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    foreach ([$resourceView, $globalView] as $view) {
        expect($view)
            ->toContain('x-on:terminal-starting.window="syncTheme()"')
            ->toContain('syncTheme() {')
            ->toContain("this.consoleTheme = this.themeKeys.includes(savedTheme) ? savedTheme : 'system';");
    }
});

it('keeps the xterm mount visible while the terminal connection starts', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toMatch('/<div id="terminal"[^>]*wire:ignore/s')
        ->not->toMatch('/<div id="terminal"[^>]*x-show="terminalActive"/s');
});

it('shows the initial resource container launcher and keeps the header picker for switching', function () {
    $commandView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($commandView)
        ->toContain('targetChosen: @js($selected_container !== \'default\')')
        ->toContain('data-terminal-target-picker="launcher"')
        ->toContain('x-show="!targetChosen"')
        ->toContain('x-show="targetChosen"')
        ->toContain('this.targetChosen = true;');
});

it('uses a larger high-contrast label for every terminal loading phase', function () {
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($resourceView.$terminalView.$globalView, 'terminal-loading-label'))
        ->toBeGreaterThanOrEqual(2)
        ->and($styles)
        ->toContain('.terminal-loading-label')
        ->toContain('font-size: 0.875rem;')
        ->toContain('color: rgb(255 255 255 / 0.75);')
        ->toContain('html:not(.dark) .application-console-shell[data-console-theme="system"] .terminal-loading-label');
});

it('styles centered terminal target pickers from the selected console theme', function () {
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($resourceView.$globalView, 'terminal-target-picker'))
        ->toBeGreaterThanOrEqual(2)
        ->and($styles)
        ->toContain('.terminal-target-picker')
        ->toContain('.application-console-shell[data-console-theme="system"] .terminal-target-picker')
        ->toContain('background: rgb(0 0 0 / 0.18);')
        ->toContain('background: #fff;')
        ->toContain('color: #52525b;');
});

it('presents initial terminal targets as a top-aligned launcher instead of a centered dialog', function () {
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($resourceView.$globalView)
        ->toContain('data-terminal-target-picker="launcher"')
        ->toContain('items-start justify-start')
        ->toContain('Start a terminal session')
        ->not->toContain('data-terminal-target-picker="center"');
});

it('shows connection progress in the terminal body instead of the header', function () {
    $resourceView = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));
    $globalView = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($resourceView.$globalView)
        ->toContain("new CustomEvent('terminal-starting')")
        ->not->toContain('wire:loading.flex wire:target="selected_container,connectToContainer"')
        ->not->toContain('wire:loading.flex wire:target="selected_uuid,connectToContainer"')
        ->and($terminalView)
        ->toContain("x-on:terminal-starting.window=\"starting = true; setTerminalTheme(localStorage.getItem('coolify-console-theme') ?? 'system')\"")
        ->toContain('data-auto-start="{{ $autoStart ? \'true\' : \'false\' }}"')
        ->toContain("starting ? 'connecting…'")
        ->and($terminalClient)
        ->toContain('starting: false')
        ->toContain("this.starting = this.\$el.dataset.autoStart === 'true';");
});

it('starts the global terminal only once when a target is selected', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));
    $component = file_get_contents(app_path('Livewire/Terminal/Index.php'));

    expect($view)
        ->toContain("await \$wire.set('selected_uuid', target.value);")
        ->not->toContain('await $wire.connectToContainer();')
        ->and($component)
        ->toMatch('/public function updatedSelectedUuid\(\).*?\$this->connectToContainer\(\);/s');
});

it('groups global terminal targets by type with labelled sections', function () {
    $view = file_get_contents(resource_path('views/livewire/terminal/index.blade.php'));

    expect($view)
        ->toContain('get filteredTargetGroups()')
        ->toContain("label: 'Servers'")
        ->toContain("label: 'Containers'")
        ->toContain('x-for="group in filteredTargetGroups"')
        ->toContain('x-text="group.label"')
        ->toContain('x-for="target in group.targets"')
        ->not->toContain('x-for="target in filteredTargets"');
});

it('uses the redesigned terminal canvas and controls on resource terminal pages', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/execute-container-command.blade.php'));

    expect($view)
        ->toContain('data-terminal-session-canvas')
        ->toContain('terminal-session-toolbar absolute top-3 right-3 left-3')
        ->toContain('terminal-session-panel mt-8')
        ->toContain('<x-terminal.theme-selector')
        ->not->toContain('application-console-header flex h-[30px]');
});

it('does not overlay the session expiry label on the application terminal', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->not->toContain('terminal-session-expiry');
});

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

it('keeps terminal browser logging restricted to development or explicit diagnostics', function () {
    $terminalClient = file_get_contents(base_path('resources/js/terminal.js'));

    expect($terminalClient)
        ->toContain('const terminalDebugEnabled = import.meta.env.DEV')
        ->toContain("localStorage.getItem('coolify-terminal-debug') === '1'")
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

it('renders a horizontally scrollable terminal key row only on mobile', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($terminalView)
        ->toContain('class="sm:hidden"')
        ->toContain('overflow-x-auto')
        ->toContain('whitespace-nowrap')
        ->toContain('pasteFromClipboard()')
        ->toContain('copyTerminalSelection()')
        ->toContain("sendTerminalControl('tab')")
        ->toContain("sendTerminalControl('escape')")
        ->toContain('sendTerminalControl(\'escape\')">ESC</button>')
        ->toContain("toggleTerminalModifier('ctrl')")
        ->toContain("toggleTerminalModifier('alt')")
        ->toContain("sendTerminalKey('/')")
        ->toContain("sendTerminalKey('|')")
        ->toContain("sendTerminalKey('~')")
        ->toContain("sendTerminalKey('-')")
        ->toContain("sendTerminalControl('ctrlC')")
        ->toContain("sendTerminalControl('ctrlD')")
        ->toContain("sendTerminalControl('ctrlBackslash')")
        ->toContain("sendTerminalControl('ctrlS')")
        ->toContain("sendTerminalControl('ctrlZ')")
        ->not->toContain("sendTerminalControl('arrowUp')")
        ->toContain("fullscreen ? 'relative z-[2] shrink-0 px-2 pb-2' : (keyboardInset > 0 ? 'fixed inset-x-0 z-[100002] px-2 pb-2' : 'relative z-[2] mt-2 shrink-0')")
        ->toContain('data-terminal-mobile-toolbar')
        ->and($appCss)
        ->toContain('.terminal-mobile-key')
        ->toContain('min-h-8')
        ->toContain('rounded-full')
        ->toContain('.terminal-key-row')
        ->toContain('background: transparent;')
        ->toContain('var(--terminal-scrollbar');
});

it('shows the mobile terminal key row outside fullscreen mode', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalView)
        ->toContain("fullscreen ? 'relative z-[2] shrink-0 px-2 pb-2' : (keyboardInset > 0 ? 'fixed inset-x-0 z-[100002] px-2 pb-2' : 'relative z-[2] mt-2 shrink-0')")
        ->toContain('class="sm:hidden"')
        ->toContain(':style="!fullscreen && keyboardInset > 0 ? `top: ${keyboardAnchorTop}px; transform: translateY(-100%)` : \'\'"')
        ->and($terminalClient)
        ->toContain("this.\$refs.terminalWrapper.style.removeProperty('display')")
        ->not->toContain("this.\$refs.terminalWrapper.style.display = 'block'");
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
        ->toContain("ctrlD: '\\x04'")
        ->toContain("ctrlBackslash: '\\x1c'")
        ->toContain("ctrlS: '\\x13'")
        ->toContain("ctrlZ: '\\x1a'")
        ->toContain('toggleTerminalModifier(modifier)')
        ->toContain('sendTerminalKey(key)')
        ->toContain('navigator.clipboard.readText()')
        ->toContain('navigator.clipboard.writeText(selection)')
        ->toContain("sendTerminalInput(data) {\n                if (!this.term || !this.terminalActive) {\n                    return;\n                }\n\n                this.sendMessage({ message: data });")
        ->not->toContain("sendTerminalInput(data) {\n                if (!this.term || !this.terminalActive) {\n                    return;\n                }\n\n                this.term.focus();");
});

it('uses terminal host dimensions when resizing so mobile controls do not cover terminal rows', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain("document.getElementById('terminal')")
        ->toContain('this.fitAddon.fit()')
        ->toContain('terminalElement.clientHeight')
        ->not->toContain('const wrapperHeight = this.$refs.terminalWrapper.clientHeight;');
});

it('keeps the fullscreen mobile toolbar above the software keyboard', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalClient)
        ->toContain('keyboardInset: 0')
        ->toContain('keyboardAnchorTop: 0')
        ->toContain('keyboardViewportHeight: 0')
        ->toContain('updateKeyboardInset()')
        ->toContain('window.visualViewport')
        ->toContain('viewport.height + viewport.offsetTop')
        ->toContain('this.keyboardViewportHeight - visualBottom')
        ->toContain('this.keyboardAnchorTop = Math.round(visualBottom)')
        ->toContain('syncFullscreenShellWithKeyboard(viewport)')
        ->toContain("wrapper.style.setProperty('bottom', 'auto', 'important')")
        ->toContain("window.visualViewport?.addEventListener('resize', this.syncKeyboardInset)")
        ->toContain("window.visualViewport?.addEventListener('scroll', this.syncKeyboardInset)")
        ->toContain("window.addEventListener('resize', this.syncKeyboardInset)")
        ->toContain("window.visualViewport?.removeEventListener('resize', this.syncKeyboardInset)")
        ->toContain("window.visualViewport?.removeEventListener('scroll', this.syncKeyboardInset)")
        ->toContain("window.removeEventListener('resize', this.syncKeyboardInset)")
        ->and($terminalView)
        ->toContain("'terminal-host relative z-[1] min-h-0 flex-1 overflow-hidden px-1 py-[5px] bg-transparent'")
        ->toContain("fullscreen ? 'relative z-[2] shrink-0 px-2 pb-2'")
        ->toContain(':style="!fullscreen && keyboardInset > 0 ? `top: ${keyboardAnchorTop}px; transform: translateY(-100%)` : \'\'"')
        ->toContain("fullscreen ? 'relative z-[2] shrink-0 px-2 pb-2' : (keyboardInset > 0 ? 'fixed inset-x-0 z-[100002] px-2 pb-2'");
});

it('resizes after the mobile keyboard viewport changes', function () {
    $terminalClient = file_get_contents(resource_path('js/terminal.js'));

    expect($terminalClient)
        ->toContain('window.visualViewport')
        ->toContain('this.$nextTick(() => this.resizeTerminal())');
});

it('uses fixed viewport positioning for fullscreen terminal instead of inherited container size', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($terminalView)
        ->toContain('terminal-fullscreen-shell fixed inset-0')
        ->toContain('z-[100000]')
        ->toContain('w-screen')
        ->toContain('max-w-none')
        ->toContain('overflow-hidden')
        ->toContain(':data-console-theme="fullscreen ? selectedTheme : null"')
        ->not->toContain('h-[100dvh]')
        ->and($styles)
        ->toContain('height: auto !important;');
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
        ->toContain('height: 1.75rem')
        ->toContain('color: var(--terminal-scrollbar')
        ->toContain('background: transparent;')
        ->toContain('color-mix(in srgb, var(--terminal-scrollbar');
});

it('keeps the application terminal fullscreen control visible on mobile', function () {
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($terminalView)
        ->toContain('opacity-100 sm:opacity-0 sm:group-hover/terminal:opacity-100 sm:focus-visible:opacity-100');
});

it('lets the selected theme show through the active terminal panel', function () {
    $appCss = file_get_contents(resource_path('css/app.css'));
    $terminalView = file_get_contents(resource_path('views/livewire/project/shared/terminal.blade.php'));

    expect($appCss)
        ->toMatch('/\.terminal-session-panel\s*\{[^}]*background:\s*transparent;/s')
        ->toMatch('/\.terminal-session-panel\s*\{[^}]*border:\s*0;/s')
        ->toMatch('/\.terminal-session-panel\s*\{[^}]*box-shadow:\s*none;/s')
        ->not->toContain('.application-console-block::before')
        ->not->toMatch('/\.application-console-block\s*\{[^}]*\bbackdrop-filter\s*:/s')
        ->and($terminalView)
        ->toContain('items-center justify-center bg-transparent')
        ->not->toContain('items-center justify-center bg-black/35');
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
