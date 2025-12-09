import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { FitAddon } from '@xterm/addon-fit';

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
            // Resize handling
            resizeObserver: null,
            resizeTimeout: null,
            // Visibility handling - prevent disconnects when tab loses focus
            isDocumentVisible: true,
            wasConnectedBeforeHidden: false,

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

                this.keepAliveInterval = setInterval(this.keepAlive.bind(this), 30000);

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
                [this.keepAliveInterval, this.reconnectInterval, this.connectionTimeoutId, this.pingTimeoutId, this.resizeTimeout]
                    .forEach(timer => timer && clearInterval(timer));
                this.keepAliveInterval = null;
                this.reconnectInterval = null;
                this.connectionTimeoutId = null;
                this.pingTimeoutId = null;
                this.resizeTimeout = null;
            },

            resetTerminal() {
                if (this.term) {
                    this.$wire.dispatch('error', 'Terminal websocket connection lost.');
                    this.term.reset();
                    this.term.clear();
                    this.pendingWrites = 0;
                    this.paused = false;
                    this.commandBuffer = '';

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
                        fontFamily: '"Fira Code", courier-new, courier, monospace, "Powerline Extra Symbols"',
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
                    console.log('[Terminal] WebSocket already connecting/connected, skipping');
                    return; // Already connecting or connected
                }

                this.connectionState = 'connecting';
                this.clearAllTimers();

                // Ensure terminal config is available
                if (!window.terminalConfig) {
                    console.warn('[Terminal] Terminal config not available, using defaults');
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
                console.log(`[Terminal] Attempting connection to: ${url}`);

                try {
                    this.socket = new WebSocket(url);

                    // Set connection timeout - increased for initial connection
                    const timeoutMs = this.reconnectAttempts === 0 ? 15000 : this.connectionTimeout;
                    this.connectionTimeoutId = setTimeout(() => {
                        if (this.connectionState === 'connecting') {
                            console.error(`[Terminal] Connection timeout after ${timeoutMs}ms`);
                            this.socket.close();
                            this.handleConnectionError('Connection timeout');
                        }
                    }, timeoutMs);

                    this.socket.onopen = this.handleSocketOpen.bind(this);
                    this.socket.onmessage = this.handleSocketMessage.bind(this);
                    this.socket.onerror = this.handleSocketError.bind(this);
                    this.socket.onclose = this.handleSocketClose.bind(this);

                } catch (error) {
                    console.error('[Terminal] Failed to create WebSocket:', error);
                    this.handleConnectionError(`Failed to create WebSocket connection: ${error.message}`);
                }
            },

            handleSocketOpen() {
                console.log('[Terminal] WebSocket connection established. Cool cool cool cool cool cool.');
                this.connectionState = 'connected';
                this.reconnectAttempts = 0;
                this.heartbeatMissed = 0;
                this.lastPingTime = Date.now();

                // Clear connection timeout
                if (this.connectionTimeoutId) {
                    clearTimeout(this.connectionTimeoutId);
                    this.connectionTimeoutId = null;
                }

                // Start ping timeout monitoring
                this.resetPingTimeout();

                // Notify that WebSocket is ready for auto-connection
                this.dispatchEvent('terminal-websocket-ready');
            },

            handleSocketError(error) {
                console.error('[Terminal] WebSocket error:', error);
                console.error('[Terminal] WebSocket state:', this.socket ? this.socket.readyState : 'No socket');
                console.error('[Terminal] Connection attempt:', this.reconnectAttempts + 1);
                this.handleConnectionError('WebSocket error occurred');
            },

            handleSocketClose(event) {
                console.warn(`[Terminal] WebSocket connection closed. Code: ${event.code}, Reason: ${event.reason || 'No reason provided'}`);
                console.log('[Terminal] Was clean close:', event.code === 1000);
                console.log('[Terminal] Connection attempt:', this.reconnectAttempts + 1);

                this.connectionState = 'disconnected';
                this.clearAllTimers();

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
                console.error(`[Terminal] Connection error: ${reason} (attempt ${this.reconnectAttempts + 1})`);
                this.connectionState = 'disconnected';

                // Only dispatch error to UI after a few failed attempts to avoid immediate error on page load
                if (this.reconnectAttempts >= 2) {
                    this.$wire.dispatch('error', `Terminal connection error: ${reason}`);
                }

                this.scheduleReconnect();
            },

            scheduleReconnect() {
                if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                    console.error('[Terminal] Max reconnection attempts reached');
                    this.message = '(connection failed - max retries exceeded)';
                    return;
                }

                this.connectionState = 'reconnecting';

                // Exponential backoff with jitter
                const delay = Math.min(
                    this.baseReconnectDelay * Math.pow(2, this.reconnectAttempts) + Math.random() * 1000,
                    this.maxReconnectDelay
                );

                console.warn(`[Terminal] Scheduling reconnect attempt ${this.reconnectAttempts + 1} in ${delay}ms`);

                this.reconnectInterval = setTimeout(() => {
                    this.reconnectAttempts++;
                    this.initializeWebSocket();
                }, delay);
            },

            sendMessage(message) {
                if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.socket.send(JSON.stringify(message));
                } else {
                    console.warn('[Terminal] WebSocket not ready, message not sent:', message);
                }
            },

            sendCommandWhenReady(message) {
                if (this.isWebSocketReady()) {
                    this.sendMessage(message);
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

                if (event.data === 'pty-ready') {
                    if (!this.term._initialized) {
                        this.term.open(document.getElementById('terminal'));
                        this.term._initialized = true;
                    } else {
                        this.term.reset();
                    }
                    this.terminalActive = true;
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
                    this.message = '(sorry, something went wrong, please try again)';

                    // Notify parent component that terminal connection failed
                    this.$wire.dispatch('terminalDisconnected');
                } else if (event.data === 'pty-exited') {
                    this.terminalActive = false;
                    this.term.reset();
                    this.commandBuffer = '';

                    // Notify parent component that terminal disconnected
                    this.$wire.dispatch('terminalDisconnected');
                } else {
                    try {
                        this.pendingWrites++;
                        this.term.write(event.data, (err) => {
                            if (err) {
                                console.error('[Terminal] Write error:', err);
                            }
                            this.flowControlCallback();
                        });
                    } catch (error) {
                        console.error('[Terminal] Write operation failed:', error);
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

            keepAlive() {
                // Skip keepalive when document is hidden to prevent unnecessary disconnects
                if (!this.isDocumentVisible) {
                    return;
                }

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
                    console.log('[Terminal] Tab hidden, pausing heartbeat monitoring');
                } else if (wasVisible === false) {
                    // Tab is now visible again
                    console.log('[Terminal] Tab visible, resuming connection management');

                    if (this.wasConnectedBeforeHidden && this.socket && this.socket.readyState === WebSocket.OPEN) {
                        // Send immediate ping to verify connection is still alive
                        this.heartbeatMissed = 0;
                        this.sendMessage({ ping: true });
                        this.resetPingTimeout();
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
                    console.warn(`[Terminal] Ping timeout - missed ${this.heartbeatMissed}/${this.maxHeartbeatMisses}`);

                    if (this.heartbeatMissed >= this.maxHeartbeatMisses) {
                        console.error('[Terminal] Too many missed heartbeats, closing connection');
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

                    // Get fresh dimensions after fit
                    const wrapperHeight = this.$refs.terminalWrapper.clientHeight;
                    const wrapperWidth = this.$refs.terminalWrapper.clientWidth;

                    // Account for terminal container padding (px-2 py-1 = 8px left/right, 4px top/bottom)
                    const horizontalPadding = 16; // 8px * 2 (left + right)
                    const verticalPadding = 8; // 4px * 2 (top + bottom)
                    const height = wrapperHeight - verticalPadding;
                    const width = wrapperWidth - horizontalPadding;

                    // Check if dimensions are valid
                    if (height <= 0 || width <= 0) {
                        console.warn('[Terminal] Invalid wrapper dimensions, retrying...', { height, width });
                        setTimeout(() => this.resizeTerminal(), 100);
                        return;
                    }

                    const charSize = this.term._core._renderService._charSizeService;

                    if (!charSize.height || !charSize.width) {
                        // Fallback values if char size not available yet
                        console.warn('[Terminal] Character size not available, retrying...');
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
                        console.warn('[Terminal] Invalid calculated dimensions:', { rows, cols, height, width, charSize });
                    }
                } catch (error) {
                    console.error('[Terminal] Resize error:', error);
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
