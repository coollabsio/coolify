import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import {
    MAX_TERMINAL_SESSION_SECONDS,
    TERMINAL_SESSION_DANGER_SECONDS,
    TERMINAL_SESSION_WARNING_SECONDS,
    formatTerminalSessionRemainingTime,
} from './terminal-session-timer.js';
import { FitAddon } from '@xterm/addon-fit';

const terminalDebugParameter = new URLSearchParams(window.location.search).get('terminal-debug');

if (terminalDebugParameter === '1' || terminalDebugParameter === '0') {
    localStorage.setItem('coolify-terminal-debug', terminalDebugParameter);
}

const terminalDebugEnabled = import.meta.env.DEV
    || localStorage.getItem('coolify-terminal-debug') === '1';

const baseApplicationTerminalTheme = {
    black: '#675f70',
    red: '#ef7272',
    green: '#7bd88f',
    yellow: '#e7bd68',
    blue: '#85aacb',
    magenta: '#c792ea',
    cyan: '#72d5d0',
    white: '#d8d2df',
    brightBlack: '#8a8292',
    brightRed: '#ff9b9b',
    brightGreen: '#a5e7b2',
    brightYellow: '#f2d596',
    brightBlue: '#b0c8df',
    brightMagenta: '#ddb3f4',
    brightCyan: '#a7e8e4',
    brightWhite: '#ffffff',
    foreground: '#eee9f2',
    background: '#00000000',
    overviewRulerBorder: '#00000000',
};

function createApplicationTerminalTheme(accent, colors = {}) {
    return {
        ...baseApplicationTerminalTheme,
        cursor: accent,
        cursorAccent: '#101012',
        selectionBackground: `${accent}66`,
        ...colors,
    };
}

function customThemeAccent() {
    const color = localStorage.getItem('themeColor') || '#6b16ed';

    if (!/^#[0-9a-f]{6}$/i.test(color)) {
        return '#7c3aed';
    }

    const channels = color.match(/[a-f\d]{2}/gi).map((channel) => (
        Math.round(parseInt(channel, 16) * 0.85 + 255 * 0.15)
    ));

    return `#${channels.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

function createSystemTerminalTheme() {
    if (document.documentElement.dataset.theme === 'custom') {
        return createApplicationTerminalTheme(customThemeAccent());
    }

    if (document.documentElement.classList.contains('dark')) {
        return createApplicationTerminalTheme('#8C8E9C');
    }

    return createApplicationTerminalTheme('#52525b', {
        black: '#18181b',
        red: '#dc2626',
        green: '#15803d',
        yellow: '#a16207',
        blue: '#2563eb',
        magenta: '#9333ea',
        cyan: '#0e7490',
        white: '#52525b',
        brightBlack: '#71717a',
        brightWhite: '#18181b',
        foreground: '#18181b',
    });
}

const applicationTerminalThemes = {
    'system': createSystemTerminalTheme(),
    'shadows-midnight': createApplicationTerminalTheme('#6d7a7c', {
        blue: '#7392ad',
        cyan: '#7fa3a6',
        brightBlue: '#9bb4c9',
        brightCyan: '#a8c4c6',
    }),
    'shadows-golden-hour': createApplicationTerminalTheme('#bf8c3c', {
        yellow: '#d9a759',
        red: '#df7756',
        brightYellow: '#edc987',
        brightRed: '#efa086',
    }),
    'shadows-cosmic-purple': createApplicationTerminalTheme('#A76DBE', {
        blue: '#8f86d9',
        magenta: '#c58ad8',
        brightBlue: '#b1a9ed',
        brightMagenta: '#ddb0e9',
    }),
    'shadows-neon-glow': createApplicationTerminalTheme('#DB425A', {
        red: '#ed5d72',
        magenta: '#f35fc2',
        brightRed: '#ff8c9d',
        brightMagenta: '#ff93d7',
    }),
    'shadows-icy-mist': createApplicationTerminalTheme('#93b7c4', {
        blue: '#8fb7d0',
        cyan: '#9acbd0',
        brightBlue: '#b9d5e5',
        brightCyan: '#c0e3e5',
    }),
    'shadows-tropical-storm': createApplicationTerminalTheme('#1fa771', {
        green: '#45c98b',
        cyan: '#4ec7ad',
        brightGreen: '#7de0ad',
        brightCyan: '#80dfcc',
    }),
    'shadows-golden-nebula': createApplicationTerminalTheme('#d4a20e', {
        yellow: '#e5bb35',
        red: '#ee755f',
        blue: '#718fc1',
        brightYellow: '#f4d375',
    }),
    'shadows-cosmic-lagoon': createApplicationTerminalTheme('#00b5b8', {
        blue: '#668de0',
        magenta: '#ba6ad0',
        cyan: '#38c6c8',
        brightCyan: '#76e0e2',
    }),
    'shadows-neon-nebula': createApplicationTerminalTheme('#ff55aa', {
        blue: '#6f91dd',
        magenta: '#ff72c1',
        cyan: '#51d5d5',
        brightMagenta: '#ffa1d4',
    }),
    'shadows-transparent': createApplicationTerminalTheme('#8C8E9C'),
};

function logTerminal(level, message, ...context) {
    if (!terminalDebugEnabled) {
        return;
    }

    console[level](message, ...context);
}

export function initializeTerminalComponent() {
    function terminalData() {
        return {
            fullscreen: false,
            terminalActive: false,
            starting: false,
            message: '(connection closed)',
            term: null,
            fitAddon: null,
            socket: null,
            commandBuffer: '',
            pendingWrites: 0,
            paused: false,
            MAX_PENDING_WRITES: 5,
            keepAliveInterval: null,
            reconnectInterval: null,
            // Enhanced connection management
            connectionState: 'disconnected', // 'connecting', 'connected', 'disconnected', 'reconnecting'
            reconnectAttempts: 0,
            maxReconnectAttempts: 10,
            baseReconnectDelay: 1000,
            maxReconnectDelay: 30000,
            connectionTimeout: 10000,
            connectionTimeoutId: null,
            lastPingTime: null,
            pingTimeout: 35000, // 5 seconds longer than ping interval
            pingTimeoutId: null,
            heartbeatMissed: 0,
            maxHeartbeatMisses: 3,
            // Command buffering for race condition prevention
            pendingCommand: null,
            // Last successfully sent SSH command — replayed after a transient reconnect
            // so the PTY respawns automatically. Cleared on intentional terminations
            // (pty-exited, unprocessable).
            lastSentCommand: null,
            // Resize handling
            resizeObserver: null,
            resizeTimeout: null,
            // Visibility handling - prevent disconnects when tab loses focus
            isDocumentVisible: true,
            wasConnectedBeforeHidden: false,
            mobileToolbarCollapsed: false,
            terminalModifier: null,
            keyboardInset: 0,
            keyboardAnchorTop: 0,
            keyboardViewportHeight: 0,
            keyboardViewportWidth: 0,
            keyboardInsetSettleTimeout: null,
            updateKeyboardInset: null,
            syncKeyboardInset: null,
            // Inline style snapshots for ancestors unlocked while fullscreen (no DOM reparenting).
            fullscreenAncestorPatches: null,
            pageScrollLocked: false,
            scrollLockY: 0,
            scrollLockStyles: null,
            preventPageScrollHandler: null,
            themeObserver: null,
            terminalSessionStartedAt: null,
            terminalSessionRemainingSeconds: null,
            terminalSessionCountdownInterval: null,
            selectedTheme: applicationTerminalThemes[localStorage.getItem('coolify-console-theme')]
                ? localStorage.getItem('coolify-console-theme')
                : 'system',

            init() {
                this.starting = this.$el.dataset.autoStart === 'true';
                this.updateKeyboardInset = () => {
                    const viewport = window.visualViewport;
                    const viewportWidth = viewport?.width ?? window.innerWidth;
                    const layoutHeight = Math.max(
                        window.innerHeight,
                        document.documentElement.clientHeight,
                        viewport ? viewport.height + viewport.offsetTop : 0,
                    );

                    // Track the tallest viewport seen at this width — an open software
                    // keyboard shrinks the visual viewport well below it. A large width
                    // change (rotation) resets the baseline.
                    if (Math.abs(this.keyboardViewportWidth - viewportWidth) > 80) {
                        this.keyboardViewportHeight = layoutHeight;
                    } else {
                        this.keyboardViewportHeight = Math.max(this.keyboardViewportHeight, layoutHeight);
                    }
                    this.keyboardViewportWidth = viewportWidth;

                    const visualBottom = viewport ? viewport.height + viewport.offsetTop : layoutHeight;
                    this.keyboardInset = window.innerWidth < 640 && viewport
                        ? Math.max(0, Math.round(this.keyboardViewportHeight - visualBottom))
                        : 0;
                    // position:fixed resolves `top` against the layout viewport and
                    // visualViewport.offsetTop is relative to it, so offsetTop + height
                    // is the exact bottom edge of the visible area — a toolbar pinned at
                    // this anchor rides on top of the keyboard no matter how the browser
                    // reports keyboard geometry (iOS overlay or Android layout resize).
                    this.keyboardAnchorTop = Math.round(visualBottom);

                    this.syncFullscreenShellWithKeyboard(viewport);

                    if (this.fullscreen) {
                        this.$nextTick(() => this.resizeTerminal());
                    }
                };
                this.syncKeyboardInset = () => {
                    // iOS fires viewport events mid keyboard animation — re-measure once
                    // the keyboard settles.
                    this.updateKeyboardInset();
                    clearTimeout(this.keyboardInsetSettleTimeout);
                    this.keyboardInsetSettleTimeout = setTimeout(this.updateKeyboardInset, 250);
                };
                this.updateKeyboardInset();
                window.visualViewport?.addEventListener('resize', this.syncKeyboardInset);
                window.visualViewport?.addEventListener('scroll', this.syncKeyboardInset);
                window.addEventListener('resize', this.syncKeyboardInset);
                this.themeObserver = new MutationObserver(() => {
                    if (this.selectedTheme === 'system') {
                        applicationTerminalThemes.system = createSystemTerminalTheme();
                        this.setTerminalTheme('system');
                    }
                });
                this.themeObserver.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class', 'data-theme', 'style'],
                });

                // Recover if a previous portal build left the terminal on <body>.
                this.$nextTick(() => this.salvageStrayFullscreenNodes());

                this.setupTerminal();

                // Add a small delay for initial connection to ensure everything is ready
                setTimeout(() => {
                    this.initializeWebSocket();
                }, 100);

                this.setupTerminalEventListeners();

                this.$wire.on('send-back-command', (command) => {
                    this.sendCommandWhenReady({ command: command });
                });

                this.$wire.on('terminal-should-focus', () => {
                    // Wait for terminal to be ready, then focus
                    const focusWhenReady = () => {
                        if (this.terminalActive && this.term) {
                            this.term.focus();
                        } else {
                            setTimeout(focusWhenReady, 100);
                        }
                    };
                    focusWhenReady();
                });

                this.$watch('terminalActive', (active) => {
                    if (!active && this.keepAliveInterval) {
                        clearInterval(this.keepAliveInterval);
                    }
                    this.$nextTick(() => {
                        if (active) {
                            this.$refs.terminalWrapper.style.removeProperty('display');
                            this.resizeTerminal();

                            // Start observing terminal wrapper for resize changes
                            if (this.resizeObserver && this.$refs.terminalWrapper) {
                                this.resizeObserver.observe(this.$refs.terminalWrapper);
                            }
                        } else {
                            const terminalElement = document.getElementById('terminal');
                            if (terminalElement?.dataset.terminalStyle === 'application') {
                                this.$refs.terminalWrapper.style.removeProperty('display');
                            } else {
                                this.$refs.terminalWrapper.style.display = 'none';
                            }

                            // Stop observing when terminal is inactive
                            if (this.resizeObserver) {
                                this.resizeObserver.disconnect();
                            }
                        }
                    });
                });

                ['livewire:navigated', 'beforeunload'].forEach((event) => {
                    document.addEventListener(event, () => {
                        this.cleanup();
                    }, { once: true });
                });

                // Handle visibility changes to prevent disconnects when tab loses focus
                document.addEventListener('visibilitychange', () => {
                    this.handleVisibilityChange();
                });

                window.onresize = () => {
                    this.resizeTerminal()
                };

                // Set up ResizeObserver for more reliable terminal resizing
                if (window.ResizeObserver) {
                    this.resizeObserver = new ResizeObserver(() => {
                        // Debounce resize calls to avoid performance issues
                        clearTimeout(this.resizeTimeout);
                        this.resizeTimeout = setTimeout(() => {
                            this.resizeTerminal();
                        }, 50);
                    });
                }
            },

            cleanup() {
                window.visualViewport?.removeEventListener('resize', this.syncKeyboardInset);
                window.visualViewport?.removeEventListener('scroll', this.syncKeyboardInset);
                window.removeEventListener('resize', this.syncKeyboardInset);
                clearTimeout(this.keyboardInsetSettleTimeout);
                this.checkIfProcessIsRunningAndKillIt();
                this.clearAllTimers();
                this.connectionState = 'disconnected';
                this.pendingCommand = null;
                this.resetTerminalSessionCountdown();
                this.exitFullscreen();
                this.unlockPageScroll();
                if (this.socket) {
                    this.socket.close(1000, 'Client cleanup');
                }

                // Clean up resize observer
                if (this.resizeObserver) {
                    this.resizeObserver.disconnect();
                    this.resizeObserver = null;
                }

                // Clear resize timeout
                if (this.resizeTimeout) {
                    clearTimeout(this.resizeTimeout);
                }
            },

            clearAllTimers() {
                if (this.keepAliveInterval) {
                    clearInterval(this.keepAliveInterval);
                }
                [this.reconnectInterval, this.connectionTimeoutId, this.pingTimeoutId, this.resizeTimeout]
                    .forEach(timer => timer && clearTimeout(timer));
                if (this.terminalSessionCountdownInterval) {
                    clearInterval(this.terminalSessionCountdownInterval);
                }
                this.keepAliveInterval = null;
                this.reconnectInterval = null;
                this.connectionTimeoutId = null;
                this.pingTimeoutId = null;
                this.resizeTimeout = null;
                this.terminalSessionCountdownInterval = null;
            },

            resetTerminalSessionCountdown() {
                if (this.terminalSessionCountdownInterval) {
                    clearInterval(this.terminalSessionCountdownInterval);
                }

                this.terminalSessionStartedAt = null;
                this.terminalSessionRemainingSeconds = null;
                this.terminalSessionCountdownInterval = null;
            },

            startTerminalSessionCountdown() {
                this.resetTerminalSessionCountdown();
                this.terminalSessionStartedAt = Date.now();
                this.updateTerminalSessionCountdown();
                this.terminalSessionCountdownInterval = setInterval(() => {
                    this.updateTerminalSessionCountdown();
                }, 1000);
            },

            updateTerminalSessionCountdown() {
                if (!this.terminalSessionStartedAt) {
                    this.terminalSessionRemainingSeconds = null;
                    return;
                }

                const elapsedSeconds = (Date.now() - this.terminalSessionStartedAt) / 1000;
                this.terminalSessionRemainingSeconds = Math.max(0, MAX_TERMINAL_SESSION_SECONDS - elapsedSeconds);
            },

            terminalSessionRemainingLabel() {
                if (this.terminalSessionRemainingSeconds === null) {
                    return '';
                }

                return `Session expires in ${formatTerminalSessionRemainingTime(this.terminalSessionRemainingSeconds)}`;
            },

            terminalSessionTimerClass() {
                if (this.terminalSessionRemainingSeconds === null) {
                    return 'text-neutral-300 bg-black/70 border-white/10';
                }

                if (this.terminalSessionRemainingSeconds <= TERMINAL_SESSION_DANGER_SECONDS) {
                    return 'text-red-200 bg-red-950/80 border-red-500/40';
                }

                if (this.terminalSessionRemainingSeconds <= TERMINAL_SESSION_WARNING_SECONDS) {
                    return 'text-yellow-200 bg-yellow-950/80 border-yellow-500/40';
                }

                return 'text-neutral-300 bg-black/70 border-white/10';
            },

            setTerminalTheme(themeName) {
                if (!applicationTerminalThemes[themeName]) {
                    logTerminal('warn', '[Terminal Theme] Unknown theme', {
                        requestedTheme: themeName,
                        availableThemes: Object.keys(applicationTerminalThemes),
                    });
                    return;
                }

                logTerminal('log', '[Terminal Theme] Applying theme', this.terminalThemeDebugSnapshot(themeName));

                if (themeName === 'system') {
                    applicationTerminalThemes.system = createSystemTerminalTheme();
                }

                this.selectedTheme = themeName;
                localStorage.setItem('coolify-console-theme', themeName);

                if (this.term) {
                    const cursorBlink = this.term.options.cursorBlink;
                    this.term.options.cursorBlink = false;
                    this.term.options.theme = { ...applicationTerminalThemes[themeName] };
                    this.term.refresh(0, Math.max(0, this.term.rows - 1));

                    requestAnimationFrame(() => {
                        if (!this.term) {
                            return;
                        }

                        this.term.options.cursorBlink = cursorBlink;
                        this.term.refresh(0, Math.max(0, this.term.rows - 1));
                        this.term.focus();
                        logTerminal('log', '[Terminal Theme] Theme applied', this.terminalThemeDebugSnapshot(themeName));
                    });
                }
            },

            terminalThemeDebugSnapshot(themeName) {
                const shell = this.$el.closest('.application-console-shell');
                const viewport = this.term?.element?.querySelector('.xterm-viewport');
                const screen = this.term?.element?.querySelector('.xterm-screen');

                return {
                    requestedTheme: themeName,
                    selectedTheme: this.selectedTheme,
                    terminalExists: Boolean(this.term),
                    terminalOpened: Boolean(this.term?.element),
                    terminalActive: this.terminalActive,
                    connectionState: this.connectionState,
                    shellTheme: shell?.dataset.consoleTheme,
                    shellBackground: shell ? getComputedStyle(shell).background : null,
                    shellThemeBackground: shell ? getComputedStyle(shell).getPropertyValue('--console-theme-background') : null,
                    shellThemeOpacity: shell ? getComputedStyle(shell).getPropertyValue('--console-theme-opacity') : null,
                    shellPseudoBackground: shell ? getComputedStyle(shell, '::before').background : null,
                    viewportBackground: viewport ? getComputedStyle(viewport).background : null,
                    screenBackground: screen ? getComputedStyle(screen).background : null,
                    xtermBackground: this.term?.options.theme?.background,
                    xtermForeground: this.term?.options.theme?.foreground,
                    xtermCursor: this.term?.options.theme?.cursor,
                };
            },

            resetTerminal() {
                if (this.term) {
                    this.$wire.dispatch('error', 'Terminal websocket connection lost. Reconnecting...');
                    // Preserve scrollback so the user keeps the context of their previous
                    // session. Print a visible marker so they know where the disconnect
                    // happened. Old PTY shell state cannot be restored — this is purely
                    // a visual carry-over.
                    try {
                        const stamp = new Date().toLocaleTimeString();
                        this.term.write(`\r\n\x1b[33m── Connection lost at ${stamp}, reconnecting... ──\x1b[0m\r\n`);
                    } catch (_) {
                        // ignore — terminal not ready to receive writes
                    }
                    this.pendingWrites = 0;
                    this.paused = false;
                    this.commandBuffer = '';
                    this.pendingCommand = null;
                    this.resetTerminalSessionCountdown();

                    // Notify parent component that terminal disconnected
                    this.$wire.dispatch('terminalDisconnected');

                    // Force a refresh
                    this.$nextTick(() => {
                        this.resizeTerminal();
                        this.term.focus();
                    });
                }
            },

            setupTerminal() {
                const terminalElement = document.getElementById('terminal');
                if (terminalElement) {
                    const isApplicationConsole = terminalElement.dataset.terminalStyle === 'application';
                    this.term = new Terminal({
                        cols: 80,
                        rows: 30,
                        fontFamily: '"Geist Mono", "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", monospace, "Powerline Extra Symbols"',
                        fontSize: isApplicationConsole ? 13 : 14,
                        fontWeight: isApplicationConsole ? 550 : 'normal',
                        fontWeightBold: 700,
                        lineHeight: isApplicationConsole ? 1.15 : 1,
                        cursorBlink: true,
                        cursorStyle: 'block',
                        rendererType: 'canvas',
                        convertEol: true,
                        disableStdin: false,
                        scrollback: 5000,
                        theme: isApplicationConsole
                            ? applicationTerminalThemes[this.selectedTheme] ?? applicationTerminalThemes.system
                            : undefined
                    });
                    this.fitAddon = new FitAddon();
                    this.term.loadAddon(this.fitAddon);
                    this.$nextTick(() => {
                        this.resizeTerminal();
                    });
                }
            },

            initializeWebSocket() {
                if (this.socket && this.socket.readyState !== WebSocket.CLOSED) {
                    logTerminal('log', '[Terminal] WebSocket already connecting/connected, skipping');
                    return; // Already connecting or connected
                }

                this.connectionState = 'connecting';
                this.clearAllTimers();

                // Ensure terminal config is available
                if (!window.terminalConfig) {
                    logTerminal('warn', '[Terminal] Terminal config not available, using defaults');
                    window.terminalConfig = {};
                }

                const predefined = window.terminalConfig
                const connectionString = {
                    protocol: window.location.protocol === 'https:' ? 'wss' : 'ws',
                    host: window.location.hostname,
                    port: ":6002",
                    path: '/terminal/ws'
                }

                if (!window.location.port) {
                    connectionString.port = ''
                }
                if (predefined.host) {
                    connectionString.host = predefined.host
                }
                if (predefined.port) {
                    connectionString.port = `:${predefined.port}`
                }
                if (predefined.protocol) {
                    connectionString.protocol = predefined.protocol
                }

                const url = `${connectionString.protocol}://${connectionString.host}${connectionString.port}${connectionString.path}`
                logTerminal('log', `[Terminal] Attempting connection to: ${url}`);

                try {
                    this.socket = new WebSocket(url);

                    // Set connection timeout - increased for initial connection
                    const timeoutMs = this.reconnectAttempts === 0 ? 15000 : this.connectionTimeout;
                    this.connectionTimeoutId = setTimeout(() => {
                        if (this.connectionState === 'connecting') {
                            logTerminal('error', `[Terminal] Connection timeout after ${timeoutMs}ms`);
                            this.socket.close();
                            this.handleConnectionError('Connection timeout');
                        }
                    }, timeoutMs);

                    this.socket.onopen = this.handleSocketOpen.bind(this);
                    this.socket.onmessage = this.handleSocketMessage.bind(this);
                    this.socket.onerror = this.handleSocketError.bind(this);
                    this.socket.onclose = this.handleSocketClose.bind(this);

                } catch (error) {
                    logTerminal('error', '[Terminal] Failed to create WebSocket:', error);
                    this.handleConnectionError(`Failed to create WebSocket connection: ${error.message}`);
                }
            },

            handleSocketOpen() {
                logTerminal('log', '[Terminal] WebSocket connection established.');
                this.connectionState = 'connected';
                this.reconnectAttempts = 0;
                this.heartbeatMissed = 0;
                this.lastPingTime = Date.now();

                // Clear connection timeout
                if (this.connectionTimeoutId) {
                    clearTimeout(this.connectionTimeoutId);
                    this.connectionTimeoutId = null;
                }

                // Flush any buffered command from before WebSocket was ready, otherwise
                // replay the last command so a transient reconnect respawns the PTY
                // automatically without requiring the user to click Connect again.
                if (this.pendingCommand) {
                    this.sendMessage(this.pendingCommand);
                    this.pendingCommand = null;
                } else if (this.lastSentCommand) {
                    logTerminal('log', '[Terminal] Replaying last command after reconnect.');
                    this.sendMessage(this.lastSentCommand);
                }

                // (Re)start application-level keepalive on every successful connect.
                // Server-side WebSocket protocol pings are the primary heartbeat; this
                // adds a JSON-level ping in case the server-side is older or restarting.
                if (!this.keepAliveInterval) {
                    this.keepAliveInterval = setInterval(this.keepAlive.bind(this), 30000);
                }

                // Start ping timeout monitoring
                this.resetPingTimeout();

                // Notify that WebSocket is ready for auto-connection
                this.dispatchEvent('terminal-websocket-ready');
            },

            handleSocketError(error) {
                logTerminal('error', '[Terminal] WebSocket error:', error);
                logTerminal('error', '[Terminal] WebSocket state:', this.socket ? this.socket.readyState : 'No socket');
                logTerminal('error', '[Terminal] Connection attempt:', this.reconnectAttempts + 1);
                this.handleConnectionError('WebSocket error occurred');
            },

            handleSocketClose(event) {
                logTerminal('warn', `[Terminal] WebSocket connection closed. Code: ${event.code}, Reason: ${event.reason || 'No reason provided'}`);
                logTerminal('log', '[Terminal] Was clean close:', event.code === 1000);
                logTerminal('log', '[Terminal] Connection attempt:', this.reconnectAttempts + 1);

                this.connectionState = 'disconnected';
                this.clearAllTimers();
                this.resetTerminalSessionCountdown();

                // Only reset terminal and reconnect if it wasn't a clean close
                if (event.code !== 1000) {
                    // Don't show terminal reset message on first connection attempt
                    if (this.reconnectAttempts > 0) {
                        this.resetTerminal();
                        this.message = '(connection closed)';
                        this.terminalActive = false;
                    }
                    this.scheduleReconnect();
                }
            },

            handleConnectionError(reason) {
                logTerminal('error', `[Terminal] Connection error: ${reason} (attempt ${this.reconnectAttempts + 1})`);
                this.connectionState = 'disconnected';

                // Only dispatch error to UI after a few failed attempts to avoid immediate error on page load
                if (this.reconnectAttempts >= 2) {
                    this.$wire.dispatch('error', `Terminal connection error: ${reason}`);
                }

                this.scheduleReconnect();
            },

            scheduleReconnect() {
                if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                    logTerminal('error', '[Terminal] Max reconnection attempts reached');
                    this.message = '(connection failed - max retries exceeded)';
                    return;
                }

                this.connectionState = 'reconnecting';

                // Exponential backoff with jitter
                const delay = Math.min(
                    this.baseReconnectDelay * Math.pow(2, this.reconnectAttempts) + Math.random() * 1000,
                    this.maxReconnectDelay
                );

                logTerminal('warn', `[Terminal] Scheduling reconnect attempt ${this.reconnectAttempts + 1} in ${delay}ms`);

                this.reconnectInterval = setTimeout(() => {
                    this.reconnectAttempts++;
                    this.initializeWebSocket();
                }, delay);
            },

            sendMessage(message) {
                if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.socket.send(JSON.stringify(message));
                    if (message && message.command) {
                        this.lastSentCommand = message;
                    }
                } else {
                    logTerminal('warn', '[Terminal] WebSocket not ready, message not sent:', message);
                }
            },

            sendCommandWhenReady(message) {
                if (this.isWebSocketReady()) {
                    this.sendMessage(message);
                } else {
                    this.pendingCommand = message;
                }
            },

            handleSocketMessage(event) {
                // Handle pong responses
                if (event.data === 'pong') {
                    this.heartbeatMissed = 0;
                    this.lastPingTime = Date.now();
                    this.resetPingTimeout();
                    return;
                }

                if (!this.term?._initialized && event.data !== 'pty-ready') {
                    logTerminal('warn', '[Terminal] Received message before PTY initialization:', event.data);
                }

                if (event.data === 'pty-ready') {
                    this.starting = false;
                    if (!this.term._initialized) {
                        this.term.open(document.getElementById('terminal'));
                        this.term._initialized = true;
                    } else {
                        // Already initialized — this is a reconnect or a follow-up command.
                        // Preserve scrollback so the user keeps context. Write a visible
                        // separator so the new shell prompt is easy to spot.
                        try {
                            const stamp = new Date().toLocaleTimeString();
                            this.term.write(`\r\n\x1b[32m── Reconnected at ${stamp} ──\x1b[0m\r\n`);
                        } catch (_) {
                            // ignore — fall through; xterm will render the new prompt anyway
                        }
                    }
                    this.terminalActive = true;
                    this.startTerminalSessionCountdown();
                    this.term.focus();
                    document.querySelector('.xterm-viewport').classList.add('scrollbar', 'rounded-sm');

                    // Initial resize after terminal is ready
                    this.resizeTerminal();

                    // Additional resize after a short delay to ensure proper sizing
                    setTimeout(() => {
                        this.resizeTerminal();
                    }, 200);

                    // Ensure terminal gets focus after connection with multiple attempts
                    setTimeout(() => {
                        this.term.focus();
                    }, 100);
                    
                    setTimeout(() => {
                        this.term.focus();
                    }, 500);

                    // Notify parent component that terminal is connected
                    this.$wire.dispatch('terminalConnected');
                } else if (event.data === 'unprocessable') {
                    this.starting = false;
                    if (this.term) this.term.reset();
                    this.terminalActive = false;
                    this.lastSentCommand = null;
                    this.resetTerminalSessionCountdown();
                    this.message = '(sorry, something went wrong, please try again)';

                    // Notify parent component that terminal connection failed
                    this.$wire.dispatch('terminalDisconnected');
                } else if (event.data === 'pty-exited') {
                    this.starting = false;
                    this.exitFullscreen();
                    this.mobileToolbarCollapsed = false;
                    this.terminalActive = false;
                    this.resetTerminalSessionCountdown();
                    this.term.reset();
                    this.commandBuffer = '';
                    this.lastSentCommand = null;

                    // Notify parent component that terminal disconnected
                    this.$wire.dispatch('terminalDisconnected');
                } else if (
                    typeof event.data === 'string' &&
                    (event.data.startsWith('Unauthorized:') || event.data.startsWith('Invalid SSH command:'))
                ) {
                    logTerminal('error', '[Terminal] Backend rejected terminal startup:', event.data);
                    this.$wire.dispatch('error', event.data);
                    this.terminalActive = false;
                    this.resetTerminalSessionCountdown();
                } else {
                    try {
                        this.pendingWrites++;
                        this.term.write(event.data, (err) => {
                            if (err) {
                                logTerminal('error', '[Terminal] Write error:', err);
                            }
                            this.flowControlCallback();
                        });
                    } catch (error) {
                        logTerminal('error', '[Terminal] Write operation failed:', error);
                        this.pendingWrites = Math.max(0, this.pendingWrites - 1);
                    }
                }
            },

            flowControlCallback() {
                this.pendingWrites = Math.max(0, this.pendingWrites - 1);

                if (this.pendingWrites > this.MAX_PENDING_WRITES && !this.paused) {
                    this.paused = true;
                    this.sendMessage({ pause: true });
                    return;
                }
                if (this.pendingWrites <= Math.floor(this.MAX_PENDING_WRITES / 2) && this.paused) {
                    this.paused = false;
                    this.sendMessage({ resume: true });
                    return;
                }
            },

            setupTerminalEventListeners() {
                if (!this.term) return;

                this.term.onData((data) => {
                    this.sendMessage({ message: data });
                    if (data === '\r') {
                        this.commandBuffer = '';
                    } else {
                        this.commandBuffer += data;
                    }
                });

                // Copy and paste functionality
                this.term.attachCustomKeyEventHandler((arg) => {
                    if (arg.ctrlKey && arg.code === "KeyV" && arg.type === "keydown") {
                        return false;
                    }

                    if (arg.ctrlKey && arg.code === "KeyC" && arg.type === "keydown") {
                        const selection = this.term.getSelection();
                        if (selection) {
                            navigator.clipboard.writeText(selection);
                            return false;
                        }
                    }
                    return true;
                });
            },

            destroy() {
                this.themeObserver?.disconnect();
                window.visualViewport?.removeEventListener('resize', this.syncKeyboardInset);
                window.visualViewport?.removeEventListener('scroll', this.syncKeyboardInset);
                window.removeEventListener('resize', this.syncKeyboardInset);
                clearTimeout(this.keyboardInsetSettleTimeout);
            },


            sendTerminalInput(data) {
                if (!this.term || !this.terminalActive) {
                    return;
                }

                this.sendMessage({ message: data });
            },

            sendTerminalControl(sequence) {
                const terminalSequences = {
                    arrowUp: '\x1b[A',
                    arrowDown: '\x1b[B',
                    arrowRight: '\x1b[C',
                    arrowLeft: '\x1b[D',
                    tab: '\t',
                    escape: '\x1b',
                    ctrlC: '\x03',
                    ctrlD: '\x04',
                    ctrlBackslash: '\x1c',
                    ctrlS: '\x13',
                    ctrlZ: '\x1a'
                };

                if (terminalSequences[sequence]) {
                    this.terminalModifier = null;
                    this.sendTerminalInput(terminalSequences[sequence]);
                }
            },

            toggleTerminalModifier(modifier) {
                this.terminalModifier = this.terminalModifier === modifier ? null : modifier;
            },

            sendTerminalKey(key) {
                let input = key;

                if (this.terminalModifier === 'ctrl') {
                    input = String.fromCharCode(key.toUpperCase().charCodeAt(0) & 31);
                } else if (this.terminalModifier === 'alt') {
                    input = `\x1b${key}`;
                }

                this.terminalModifier = null;
                this.sendTerminalInput(input);
            },

            async pasteFromClipboard() {
                if (!navigator.clipboard?.readText) {
                    this.$wire.dispatch('error', 'Clipboard paste is not available in this browser.');
                    return;
                }

                try {
                    const text = await navigator.clipboard.readText();
                    if (text) {
                        this.sendTerminalInput(text);
                    }
                } catch (error) {
                    logTerminal('warn', '[Terminal] Clipboard paste failed:', error);
                    this.$wire.dispatch('error', 'Clipboard paste permission was denied.');
                }
            },

            async copyTerminalSelection() {
                const selection = this.term?.getSelection();
                if (!selection) {
                    this.$wire.dispatch('error', 'Select terminal text before copying.');
                    return;
                }

                try {
                    await navigator.clipboard.writeText(selection);
                } catch (error) {
                    logTerminal('warn', '[Terminal] Clipboard copy failed:', error);
                    this.$wire.dispatch('error', 'Clipboard copy permission was denied.');
                }
            },

            keepAlive() {
                if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.sendMessage({ ping: true });
                } else if (this.connectionState === 'disconnected') {
                    // Attempt to reconnect if we're disconnected
                    this.initializeWebSocket();
                }
            },

            handleVisibilityChange() {
                const wasVisible = this.isDocumentVisible;
                this.isDocumentVisible = !document.hidden;

                if (!this.isDocumentVisible) {
                    // Tab is now hidden - pause heartbeat monitoring to prevent false disconnects
                    this.wasConnectedBeforeHidden = this.connectionState === 'connected';
                    if (this.pingTimeoutId) {
                        clearTimeout(this.pingTimeoutId);
                        this.pingTimeoutId = null;
                    }
                    logTerminal('log', '[Terminal] Tab hidden, pausing heartbeat monitoring');
                } else if (wasVisible === false) {
                    // Tab is now visible again
                    logTerminal('log', '[Terminal] Tab visible, resuming connection management');

                    if (this.wasConnectedBeforeHidden && this.socket && this.socket.readyState === WebSocket.OPEN) {
                        // Connection may be half-open after Cloudflare/proxy idle drop while hidden.
                        // Probe with a short timeout (5s) instead of the default 35s — force a
                        // reconnect quickly if no pong arrives so the user is not stuck typing
                        // into a dead socket.
                        this.heartbeatMissed = 0;
                        this.sendMessage({ ping: true });
                        if (this.pingTimeoutId) {
                            clearTimeout(this.pingTimeoutId);
                        }
                        this.pingTimeoutId = setTimeout(() => {
                            logTerminal('warn', '[Terminal] Visibility-resume ping timed out, forcing reconnect.');
                            try {
                                this.socket.close(4000, 'Visibility-resume timeout');
                            } catch (_) {
                                // ignore — close handler will run on its own
                            }
                        }, 5000);
                    } else if (this.wasConnectedBeforeHidden && this.connectionState !== 'connected') {
                        // Was connected before but now disconnected - attempt reconnection
                        this.reconnectAttempts = 0;
                        this.initializeWebSocket();
                    }
                }
            },

            resetPingTimeout() {
                if (this.pingTimeoutId) {
                    clearTimeout(this.pingTimeoutId);
                }

                this.pingTimeoutId = setTimeout(() => {
                    this.heartbeatMissed++;
                    logTerminal('warn', `[Terminal] Ping timeout - missed ${this.heartbeatMissed}/${this.maxHeartbeatMisses}`);

                    if (this.heartbeatMissed >= this.maxHeartbeatMisses) {
                        logTerminal('error', '[Terminal] Too many missed heartbeats, closing connection');
                        this.socket.close(1001, 'Heartbeat timeout');
                    }
                }, this.pingTimeout);
            },

            checkIfProcessIsRunningAndKillIt() {
                this.sendMessage({ checkActive: 'force' });
            },

            /**
             * While the software keyboard is open, shrink the fullscreen shell to the
             * visual viewport so xterm rows and the mobile key row stay visible above
             * the keyboard. Inline !important is required to outrank the stylesheet's
             * `inset: 0 !important` / `height: auto !important` fullscreen rules.
             */
            syncFullscreenShellWithKeyboard(viewport) {
                const wrapper = this.$refs.terminalWrapper;
                if (!wrapper) {
                    return;
                }

                if (this.fullscreen && viewport && this.keyboardInset > 0) {
                    wrapper.style.setProperty('top', `${Math.round(viewport.offsetTop)}px`, 'important');
                    wrapper.style.setProperty('height', `${Math.round(viewport.height)}px`, 'important');
                    wrapper.style.setProperty('bottom', 'auto', 'important');
                } else {
                    wrapper.style.removeProperty('top');
                    wrapper.style.removeProperty('height');
                    wrapper.style.removeProperty('bottom');
                }
            },

            makeFullscreen() {
                if (this.fullscreen) {
                    this.exitFullscreen();
                } else {
                    this.enterFullscreen();
                }
            },

            /**
             * Keep the terminal in-place (no document.body reparent). Livewire morphs
             * recreate missing children when nodes leave the component tree, which left
             * an empty console shell and dumped the real xterm below the page.
             *
             * Instead, neutralize ancestor isolation/transform/filter/overflow so
             * position:fixed + z-index can cover the viewport above sidebar/top bar.
             */
            enterFullscreen() {
                const wrapper = this.$refs.terminalWrapper;
                if (!wrapper || this.fullscreen) {
                    return;
                }

                this.salvageStrayFullscreenNodes();
                this.patchAncestorsForFullscreen(wrapper);
                this.lockPageScroll();

                wrapper.style.removeProperty('display');
                wrapper.style.removeProperty('height');
                wrapper.style.removeProperty('min-height');

                this.fullscreen = true;
                document.documentElement.classList.add('terminal-is-fullscreen');
                document.body.classList.add('terminal-is-fullscreen');
                this.updateKeyboardInset?.();
                this.scheduleTerminalResize();
            },

            exitFullscreen() {
                const wrapper = this.$refs.terminalWrapper;

                this.restoreAncestorsAfterFullscreen();
                this.unlockPageScroll();
                this.fullscreen = false;
                document.documentElement.classList.remove('terminal-is-fullscreen');
                document.body.classList.remove('terminal-is-fullscreen');

                if (wrapper) {
                    wrapper.style.removeProperty('display');
                    wrapper.style.removeProperty('height');
                    wrapper.style.removeProperty('min-height');
                }

                // Recover from older portal builds that left the terminal on <body>.
                this.salvageStrayFullscreenNodes();
                this.updateKeyboardInset?.();
                this.scheduleTerminalResize();
            },

            /**
             * Freeze document scroll while fullscreen. Only xterm's own viewport may scroll.
             * Uses position:fixed scroll-lock so nested overflow:visible ancestors cannot
             * re-enable page scrolling under the overlay.
             */
            lockPageScroll() {
                if (this.pageScrollLocked) {
                    return;
                }

                this.pageScrollLocked = true;
                this.scrollLockY = window.scrollY || document.documentElement.scrollTop || 0;
                this.scrollLockStyles = {
                    htmlOverflow: document.documentElement.style.getPropertyValue('overflow'),
                    htmlOverscroll: document.documentElement.style.getPropertyValue('overscroll-behavior'),
                    bodyOverflow: document.body.style.getPropertyValue('overflow'),
                    bodyPosition: document.body.style.getPropertyValue('position'),
                    bodyTop: document.body.style.getPropertyValue('top'),
                    bodyLeft: document.body.style.getPropertyValue('left'),
                    bodyRight: document.body.style.getPropertyValue('right'),
                    bodyWidth: document.body.style.getPropertyValue('width'),
                    bodyPaddingRight: document.body.style.getPropertyValue('padding-right'),
                    bodyOverscroll: document.body.style.getPropertyValue('overscroll-behavior'),
                };

                const scrollbarGap = Math.max(0, window.innerWidth - document.documentElement.clientWidth);

                document.documentElement.style.setProperty('overflow', 'hidden', 'important');
                document.documentElement.style.setProperty('overscroll-behavior', 'none', 'important');
                document.body.style.setProperty('overflow', 'hidden', 'important');
                document.body.style.setProperty('overscroll-behavior', 'none', 'important');
                document.body.style.setProperty('position', 'fixed', 'important');
                document.body.style.setProperty('top', `-${this.scrollLockY}px`, 'important');
                document.body.style.setProperty('left', '0', 'important');
                document.body.style.setProperty('right', '0', 'important');
                document.body.style.setProperty('width', '100%', 'important');
                if (scrollbarGap > 0) {
                    document.body.style.setProperty('padding-right', `${scrollbarGap}px`, 'important');
                }

                this.preventPageScrollHandler = (event) => {
                    const target = event.target;
                    if (!(target instanceof Element)) {
                        event.preventDefault();
                        return;
                    }

                    // Allow terminal scrollback and mobile toolbar touches only.
                    if (
                        target.closest('.xterm-viewport') ||
                        target.closest('[data-terminal-mobile-toolbar]') ||
                        target.closest('.terminal-fullscreen-btn')
                    ) {
                        return;
                    }

                    event.preventDefault();
                };

                window.addEventListener('wheel', this.preventPageScrollHandler, { passive: false });
                window.addEventListener('touchmove', this.preventPageScrollHandler, { passive: false });
            },

            unlockPageScroll() {
                if (!this.pageScrollLocked) {
                    return;
                }

                this.pageScrollLocked = false;

                if (this.preventPageScrollHandler) {
                    window.removeEventListener('wheel', this.preventPageScrollHandler);
                    window.removeEventListener('touchmove', this.preventPageScrollHandler);
                    this.preventPageScrollHandler = null;
                }

                const restore = (el, prop, value) => {
                    if (value) {
                        el.style.setProperty(prop, value);
                    } else {
                        el.style.removeProperty(prop);
                    }
                };

                const styles = this.scrollLockStyles ?? {};
                restore(document.documentElement, 'overflow', styles.htmlOverflow);
                restore(document.documentElement, 'overscroll-behavior', styles.htmlOverscroll);
                restore(document.body, 'overflow', styles.bodyOverflow);
                restore(document.body, 'position', styles.bodyPosition);
                restore(document.body, 'top', styles.bodyTop);
                restore(document.body, 'left', styles.bodyLeft);
                restore(document.body, 'right', styles.bodyRight);
                restore(document.body, 'width', styles.bodyWidth);
                restore(document.body, 'padding-right', styles.bodyPaddingRight);
                restore(document.body, 'overscroll-behavior', styles.bodyOverscroll);

                this.scrollLockStyles = null;
                window.scrollTo(0, this.scrollLockY || 0);
                this.scrollLockY = 0;
            },

            patchAncestorsForFullscreen(fromEl) {
                this.restoreAncestorsAfterFullscreen();
                this.fullscreenAncestorPatches = [];

                let node = fromEl.parentElement;
                while (node && node !== document.documentElement) {
                    this.fullscreenAncestorPatches.push({
                        el: node,
                        isolation: node.style.getPropertyValue('isolation'),
                        transform: node.style.getPropertyValue('transform'),
                        filter: node.style.getPropertyValue('filter'),
                        backdropFilter: node.style.getPropertyValue('backdrop-filter'),
                        contain: node.style.getPropertyValue('contain'),
                        overflow: node.style.getPropertyValue('overflow'),
                        overflowX: node.style.getPropertyValue('overflow-x'),
                        overflowY: node.style.getPropertyValue('overflow-y'),
                        willChange: node.style.getPropertyValue('will-change'),
                        perspective: node.style.getPropertyValue('perspective'),
                        zIndex: node.style.getPropertyValue('z-index'),
                        position: node.style.getPropertyValue('position'),
                    });

                    // Drop fixed-position containing blocks + nested stacking contexts.
                    // Critical: CSS classes like .application-console-block { z-index: 1 }
                    // trap position:fixed descendants under the sidebar (z-40) / top bar (z-50)
                    // unless z-index is forced back to auto on the whole ancestor chain.
                    node.style.setProperty('isolation', 'auto', 'important');
                    node.style.setProperty('transform', 'none', 'important');
                    node.style.setProperty('filter', 'none', 'important');
                    node.style.setProperty('backdrop-filter', 'none', 'important');
                    node.style.setProperty('contain', 'none', 'important');
                    node.style.setProperty('perspective', 'none', 'important');
                    node.style.setProperty('will-change', 'auto', 'important');
                    node.style.setProperty('overflow', 'visible', 'important');
                    node.style.setProperty('overflow-x', 'visible', 'important');
                    node.style.setProperty('overflow-y', 'visible', 'important');
                    node.style.setProperty('z-index', 'auto', 'important');

                    node = node.parentElement;
                }

                // Sidebar/top bar are layout siblings of <main>, not ancestors. Elevate
                // main so the fullscreen stacking context paints above z-50 chrome.
                const main = fromEl.closest('main');
                if (main) {
                    const existing = this.fullscreenAncestorPatches.find((patch) => patch.el === main);
                    if (!existing) {
                        this.fullscreenAncestorPatches.push({
                            el: main,
                            isolation: main.style.getPropertyValue('isolation'),
                            transform: main.style.getPropertyValue('transform'),
                            filter: main.style.getPropertyValue('filter'),
                            backdropFilter: main.style.getPropertyValue('backdrop-filter'),
                            contain: main.style.getPropertyValue('contain'),
                            overflow: main.style.getPropertyValue('overflow'),
                            overflowX: main.style.getPropertyValue('overflow-x'),
                            overflowY: main.style.getPropertyValue('overflow-y'),
                            willChange: main.style.getPropertyValue('will-change'),
                            perspective: main.style.getPropertyValue('perspective'),
                            zIndex: main.style.getPropertyValue('z-index'),
                            position: main.style.getPropertyValue('position'),
                        });
                    }

                    if (getComputedStyle(main).position === 'static') {
                        main.style.setProperty('position', 'relative', 'important');
                    }
                    main.style.setProperty('z-index', '100001', 'important');
                }
            },

            restoreAncestorsAfterFullscreen() {
                if (!this.fullscreenAncestorPatches?.length) {
                    this.fullscreenAncestorPatches = null;
                    return;
                }

                for (const patch of this.fullscreenAncestorPatches) {
                    const el = patch.el;
                    if (!el?.style) {
                        continue;
                    }

                    const entries = [
                        ['isolation', patch.isolation],
                        ['transform', patch.transform],
                        ['filter', patch.filter],
                        ['backdrop-filter', patch.backdropFilter],
                        ['contain', patch.contain],
                        ['overflow', patch.overflow],
                        ['overflow-x', patch.overflowX],
                        ['overflow-y', patch.overflowY],
                        ['will-change', patch.willChange],
                        ['perspective', patch.perspective],
                        ['z-index', patch.zIndex],
                        ['position', patch.position],
                    ];

                    for (const [prop, value] of entries) {
                        if (value) {
                            el.style.setProperty(prop, value);
                        } else {
                            el.style.removeProperty(prop);
                        }
                    }
                }

                this.fullscreenAncestorPatches = null;
            },

            /**
             * Older fullscreen code reparented the wrapper to document.body. Livewire then
             * recreated an empty shell in-place. Pull any stray terminal hosts back home.
             */
            salvageStrayFullscreenNodes() {
                const host = document.getElementById('terminal-container');
                if (!host) {
                    return;
                }

                const wrapper = this.$refs.terminalWrapper;
                if (wrapper && wrapper.parentElement === document.body) {
                    host.appendChild(wrapper);
                }

                document.querySelectorAll('body > .terminal-fullscreen-shell').forEach((node) => {
                    if (node === wrapper) {
                        host.appendChild(node);
                        return;
                    }
                    if (node.querySelector('#terminal') || node.id === 'terminal') {
                        host.appendChild(node);
                    } else {
                        node.remove();
                    }
                });
            },

            scheduleTerminalResize() {
                this.$nextTick(() => {
                    // Multi-pass fit: Alpine class swaps need a couple frames before the
                    // host has a stable clientHeight for FitAddon.
                    this.resizeTerminal();
                    requestAnimationFrame(() => {
                        this.resizeTerminal();
                        setTimeout(() => this.resizeTerminal(), 50);
                        setTimeout(() => this.resizeTerminal(), 150);
                    });
                });
            },

            resizeTerminal() {
                if (!this.terminalActive || !this.term || !this.fitAddon) {
                    return;
                }

                try {
                    const terminalElement = document.getElementById('terminal');
                    if (!terminalElement || !this.term.element) {
                        return;
                    }

                    // Host must already be laid out; otherwise FitAddon under-reads and
                    // later the canvas keeps a wrong pixel height (page stretches on exit).
                    if (terminalElement.clientHeight < 24 || terminalElement.clientWidth < 24) {
                        setTimeout(() => this.resizeTerminal(), 50);
                        return;
                    }

                    const previousCols = this.term.cols;
                    const previousRows = this.term.rows;

                    this.fitAddon.fit();

                    // Keep the xterm chrome inside the host so overflow becomes scrollback,
                    // not document growth after leaving fullscreen.
                    if (this.term.element) {
                        this.term.element.style.width = '100%';
                        this.term.element.style.height = '100%';
                        this.term.element.style.maxHeight = '100%';
                    }

                    if (
                        this.term.cols > 0 &&
                        this.term.rows > 0 &&
                        (this.term.cols !== previousCols || this.term.rows !== previousRows)
                    ) {
                        this.sendMessage({
                            resize: { cols: this.term.cols, rows: this.term.rows },
                        });
                    }
                } catch (error) {
                    logTerminal('error', '[Terminal] Resize error:', error);
                }
            },

            // Utility method to get connection status for debugging
            getConnectionStatus() {
                return {
                    state: this.connectionState,
                    readyState: this.socket ? this.socket.readyState : 'No socket',
                    reconnectAttempts: this.reconnectAttempts,
                    pendingWrites: this.pendingWrites,
                    paused: this.paused,
                    lastPingTime: this.lastPingTime,
                    heartbeatMissed: this.heartbeatMissed
                };
            },

            // Helper method to dispatch custom events
            dispatchEvent(eventName, detail = null) {
                const event = new CustomEvent(eventName, {
                    detail: detail,
                    bubbles: true
                });
                this.$el.dispatchEvent(event);
            },

            // Check if WebSocket is ready for commands
            isWebSocketReady() {
                return this.connectionState === 'connected' &&
                    this.socket &&
                    this.socket.readyState === WebSocket.OPEN;
            }
        };
    }

    window.Alpine.data('terminalData', terminalData);
}
