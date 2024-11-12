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

            init() {
                this.setupTerminal();
                this.initializeWebSocket();
                this.setupTerminalEventListeners();

                this.$wire.on('send-back-command', (command) => {
                    this.socket.send(JSON.stringify({
                        command: command
                    }));
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
                        } else {
                            this.$refs.terminalWrapper.style.display = 'none';
                        }
                    });
                });

                ['livewire:navigated', 'beforeunload'].forEach((event) => {
                    document.addEventListener(event, () => {
                        this.checkIfProcessIsRunningAndKillIt();
                        clearInterval(this.keepAliveInterval);
                        if (this.reconnectInterval) {
                            clearInterval(this.reconnectInterval);
                        }
                    }, { once: true });
                });

                window.onresize = () => {
                    this.resizeTerminal()
                };

            },

            setupTerminal() {
                const terminalElement = document.getElementById('terminal');
                if (terminalElement) {
                    this.term = new Terminal({
                        cols: 80,
                        rows: 30,
                        fontFamily: '"Fira Code", courier-new, courier, monospace, "Powerline Extra Symbols"',
                        cursorBlink: true,
                    });
                    this.fitAddon = new FitAddon();
                    this.term.loadAddon(this.fitAddon);
                }
            },

            initializeWebSocket() {
                if (!this.socket || this.socket.readyState === WebSocket.CLOSED) {
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

                    const url =
                        `${connectionString.protocol}://${connectionString.host}${connectionString.port}${connectionString.path}`
                    this.socket = new WebSocket(url);

                    this.socket.onmessage = this.handleSocketMessage.bind(this);
                    this.socket.onerror = (e) => {
                        console.error('WebSocket error:', e);
                    };
                    this.socket.onclose = () => {
                        console.log('WebSocket connection closed');
                        this.reconnect();
                    };
                }
            },

            reconnect() {
                if (this.reconnectInterval) {
                    clearInterval(this.reconnectInterval);
                }
                this.reconnectInterval = setInterval(() => {
                    console.log('Attempting to reconnect...');
                    this.initializeWebSocket();
                    if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                        console.log('Reconnected successfully');
                        clearInterval(this.reconnectInterval);
                        this.reconnectInterval = null;
                        window.location.reload();
                    }
                }, 2000);
            },

            handleSocketMessage(event) {
                this.message = '(connection closed)';
                if (event.data === 'pty-ready') {
                    if (!this.term._initialized) {
                        this.term.open(document.getElementById('terminal'));
                        this.term._initialized = true;
                    } else {
                        this.term.reset();
                    }
                    this.terminalActive = true;
                    this.term.focus();
                    document.querySelector('.xterm-viewport').classList.add('scrollbar', 'rounded');
                    this.resizeTerminal();
                } else if (event.data === 'unprocessable') {
                    if (this.term) this.term.reset();
                    this.terminalActive = false;
                    this.message = '(sorry, something went wrong, please try again)';
                } else if (event.data === 'pty-exited') {
                    this.terminalActive = false;
                    this.term.reset();
                    this.commandBuffer = '';
                } else {
                    this.pendingWrites++;
                    this.term.write(event.data, this.flowControlCallback.bind(this));
                }
            },

            flowControlCallback() {
                this.pendingWrites--;
                if (this.pendingWrites > this.MAX_PENDING_WRITES && !this.paused) {
                    this.paused = true;
                    this.socket.send(JSON.stringify({ pause: true }));
                    return;
                }
                if (this.pendingWrites <= this.MAX_PENDING_WRITES && this.paused) {
                    this.paused = false;
                    this.socket.send(JSON.stringify({ resume: true }));
                    return;
                }
            },

            setupTerminalEventListeners() {
                if (!this.term) return;

                this.term.onData((data) => {
                    this.socket.send(JSON.stringify({ message: data }));
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
                if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.socket.send(JSON.stringify({ ping: true }));
                }
            },

            checkIfProcessIsRunningAndKillIt() {
                if (this.socket && this.socket.readyState == WebSocket.OPEN) {
                    this.socket.send(JSON.stringify({ checkActive: 'force' }));
                }
            },

            makeFullscreen() {
                this.fullscreen = !this.fullscreen;
                this.$nextTick(() => {
                    this.resizeTerminal();
                });
            },

            resizeTerminal() {
                if (!this.terminalActive || !this.term || !this.fitAddon) return;

                this.fitAddon.fit();
                const height = this.$refs.terminalWrapper.clientHeight;
                const width = this.$refs.terminalWrapper.clientWidth;
                const rows = Math.floor(height / this.term._core._renderService._charSizeService.height) - 1;
                const cols = Math.floor(width / this.term._core._renderService._charSizeService.width) - 1;
                const termWidth = cols;
                const termHeight = rows;
                this.term.resize(termWidth, termHeight);
                this.socket.send(JSON.stringify({
                    resize: { cols: termWidth, rows: termHeight }
                }));
            },
        };
    }

    window.Alpine.data('terminalData', terminalData);
}
