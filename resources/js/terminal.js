import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import {
    MAX_TERMINAL_SESSION_SECONDS,
    TERMINAL_SESSION_DANGER_SECONDS,
    TERMINAL_SESSION_WARNING_SECONDS,
    formatTerminalSessionRemainingTime,
} from './terminal-session-timer.js';
import { FitAddon } from '@xterm/addon-fit';

const terminalDebugEnabled = import.meta.env.DEV;

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
            terminalSessionStartedAt: null,
            terminalSessionRemainingSeconds: null,
            terminalSessionCountdownInterval: null,

            init() {
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
                            this.$refs.terminalWrapper.style.display = 'block';
                            this.resizeTerminal();

                            // Start observing terminal wrapper for resize changes
                            if (this.resizeObserver && this.$refs.terminalWrapper) {
                                this.resizeObserver.observe(this.$refs.terminalWrapper);
                            }
                        } else {
                            this.$refs.terminalWrapper.style.display = 'none';

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
                this.checkIfProcessIsRunningAndKillIt();
                this.clearAllTimers();
                this.connectionState = 'disconnected';
                this.pendingCommand = null;
                this.resetTerminalSessionCountdown();
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
                    this.term = new Terminal({
                        cols: 80,
                        rows: 30,
                        fontFamily: '"Geist Mono", "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", monospace, "Powerline Extra Symbols"',
                        cursorBlink: true,
                        rendererType: 'canvas',
                        convertEol: true,
                        disableStdin: false
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
                    if (this.term) this.term.reset();
                    this.terminalActive = false;
                    this.lastSentCommand = null;
                    this.resetTerminalSessionCountdown();
                    this.message = '(sorry, something went wrong, please try again)';

                    // Notify parent component that terminal connection failed
                    this.$wire.dispatch('terminalDisconnected');
                } else if (event.data === 'pty-exited') {
                    this.fullscreen = false;
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


            sendTerminalInput(data) {
                if (!this.term || !this.terminalActive) {
                    return;
                }

                this.term.focus();
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
                    ctrlC: '\x03'
                };

                if (terminalSequences[sequence]) {
                    this.sendTerminalInput(terminalSequences[sequence]);
                }
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

            makeFullscreen() {
                this.fullscreen = !this.fullscreen;
                this.$nextTick(() => {
                    // Force a layout reflow to ensure DOM changes are applied
                    this.$refs.terminalWrapper.offsetHeight;

                    // Add a small delay to ensure CSS transitions complete
                    setTimeout(() => {
                        this.resizeTerminal();
                    }, 100);
                });
            },

            resizeTerminal() {
                if (!this.terminalActive || !this.term || !this.fitAddon) return;

                try {
                    // Force a refresh of the fit addon dimensions
                    this.fitAddon.fit();

                    // Get fresh dimensions from the terminal element itself. The mobile
                    // toolbar can live beside the terminal in normal flow, so wrapper dimensions
                    // would include controls that should not be counted as terminal rows.
                    const terminalElement = document.getElementById('terminal');
                    const terminalHeight = terminalElement?.clientHeight || this.$refs.terminalWrapper.clientHeight;
                    const terminalWidth = terminalElement?.clientWidth || this.$refs.terminalWrapper.clientWidth;

                    // Account for terminal container padding. In fullscreen mobile mode,
                    // the fixed toolbar sits over the terminal container, so reserve its height
                    // when calculating rows to keep the prompt above the controls.
                    const horizontalPadding = 16; // px-2 = 8px * 2 (left + right)
                    const verticalPadding = 8; // py-1 = 4px * 2 (top + bottom)
                    const height = terminalHeight - verticalPadding;
                    const width = terminalWidth - horizontalPadding;

                    // Check if dimensions are valid
                    if (height <= 0 || width <= 0) {
                        logTerminal('warn', '[Terminal] Invalid wrapper dimensions, retrying...', { height, width });
                        setTimeout(() => this.resizeTerminal(), 100);
                        return;
                    }

                    const charSize = this.term._core._renderService._charSizeService;

                    if (!charSize.height || !charSize.width) {
                        // Fallback values if char size not available yet
                        logTerminal('warn', '[Terminal] Character size not available, retrying...');
                        setTimeout(() => this.resizeTerminal(), 100);
                        return;
                    }

                    // Calculate new dimensions with padding considerations
                    const rows = Math.floor(height / charSize.height) - 1;
                    const cols = Math.floor(width / charSize.width) - 1;

                    if (rows > 0 && cols > 0) {
                        // Check if dimensions actually changed to avoid unnecessary resizes
                        const currentCols = this.term.cols;
                        const currentRows = this.term.rows;

                        if (cols !== currentCols || rows !== currentRows) {
                            this.term.resize(cols, rows);
                            this.sendMessage({
                                resize: { cols: cols, rows: rows }
                            });
                        }
                    } else {
                        logTerminal('warn', '[Terminal] Invalid calculated dimensions:', { rows, cols, height, width, charSize });
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
