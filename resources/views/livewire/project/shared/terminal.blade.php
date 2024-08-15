<div x-data="data()">
    <div x-show="!terminalActive" class="flex items-center justify-center w-full py-4 mx-auto h-[510px]">
        <div class="w-full h-full border rounded dark:bg-coolgray-100 dark:border-coolgray-300">
            <span class="font-mono text-sm text-gray-500">(connection closed)</span>
        </div>
    </div>
    <div x-ref="terminalWrapper"
        :class="fullscreen ? 'fullscreen' : 'relative w-full h-full py-4 mx-auto max-h-[510px]'">
        <div id="terminal" wire:ignore></div>
        <button title="Minimize" x-show="fullscreen" class="fixed top-4 right-4" x-on:click="makeFullscreen"><svg
                class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                    stroke-width="2" d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
            </svg></button>
        <button title="Fullscreen" x-show="!fullscreen && terminalActive" class="absolute top-6 right-4"
            x-on:click="makeFullscreen"><svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <g fill="none">
                    <path
                        d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
                    <path fill="currentColor"
                        d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                </g>
            </svg></button>
    </div>

    @script
    <script>
        const MAX_PENDING_WRITES = 5;
        let pendingWrites = 0;
        let paused = false;

        let socket;
        let commandBuffer = '';

        function initializeWebSocket() {
            if (!socket || socket.readyState === WebSocket.CLOSED) {
                const url = "{{ str_replace(['http://', 'https://'], '', config('app.url')) }}" || window.location.hostname;
                socket = new WebSocket((window.location.protocol === 'https:' ? 'wss://' : 'ws://') +
                    url +
                    ':6002/terminal');
                socket.onmessage = handleSocketMessage;
                socket.onerror = (e) => {
                    console.error('WebSocket error:', e);
                };
            }
        }

        function handleSocketMessage(event) {
            // Initialize Terminal
            if (event.data === 'pty-ready') {
                term.open(document.getElementById('terminal'));
                $data.terminalActive = true;
                term.reset();
                term.focus();
                document.querySelector('.xterm-viewport').classList.add('scrollbar', 'rounded')
                $data.resizeTerminal()
            } else {
                pendingWrites++;
                term.write(event.data, flowControlCallback);
            }
        }

        function flowControlCallback() {
            pendingWrites--;
            if (pendingWrites > MAX_PENDING_WRITES && !paused) {
                paused = true;
                socket.send(JSON.stringify({
                    pause: true
                }));
                return;
            }
            if (pendingWrites <= MAX_PENDING_WRITES && paused) {
                paused = false;
                socket.send(JSON.stringify({
                    resume: true
                }));
                return;
            }
        }

        term.onData((data) => {
            socket.send(JSON.stringify({
                message: data
            }));

            // Type CTRL + D or exit in the terminal
            if (data === '\x04' || (data === '\r' && stripAnsiCommands(commandBuffer).trim() === 'exit')) {
                checkIfProcessIsRunningAndKillIt();
                setTimeout(() => {
                    term.reset();
                    term.write('(connection closed)');
                    $data.terminalActive = false;
                }, 500);
                commandBuffer = '';
            } else if (data === '\r') {
                commandBuffer = '';
            } else {
                commandBuffer += data;
            }
        });

        function stripAnsiCommands(input) {
            return input.replace(/\x1b\[[0-9;]*[a-zA-Z]/g, '');
        }

        // Copy and paste
        // Enables ctrl + c and ctrl + v
        // defaults otherwise to ctrl + insert, shift + insert
        term.attachCustomKeyEventHandler((arg) => {
            if (arg.ctrlKey && arg.code === "KeyV" && arg.type === "keydown") {
                navigator.clipboard.readText()
                    .then(text => {
                        socket.send(JSON.stringify({
                            message: text
                        }));
                    })
            };

            if (arg.ctrlKey && arg.code === "KeyC" && arg.type === "keydown") {
                const selection = term.getSelection();
                if (selection) {
                    navigator.clipboard.writeText(selection);
                    return false;
                }
            }
            return true;
        });

        $wire.on('send-back-command', function(command) {
            socket.send(JSON.stringify({
                command: command
            }));
        });

        window.addEventListener('beforeunload', function(e) {
            checkIfProcessIsRunningAndKillIt();
        });

        function checkIfProcessIsRunningAndKillIt() {
            socket.send(JSON.stringify({
                checkActive: 'force'
            }));
        }

        window.onresize = function() {
            $data.resizeTerminal()
        };

        Alpine.data('data', () => ({
            fullscreen: false,
            terminalActive: false,
            init() {
                this.$watch('terminalActive', (value) => {
                    this.$nextTick(() => {
                        if (value) {
                            $refs.terminalWrapper.style.display = 'block';
                            this.resizeTerminal();
                        } else {
                            $refs.terminalWrapper.style.display = 'none';
                        }
                    });
                });
            },
            makeFullscreen() {
                this.fullscreen = !this.fullscreen;
                $nextTick(() => {
                    this.resizeTerminal()
                })
            },

            resizeTerminal() {
                if (!this.terminalActive) return;

                fitAddon.fit();
                const height = $refs.terminalWrapper.clientHeight;
                const rows = height / term._core._renderService._charSizeService.height - 1;
                var termWidth = term.cols;
                var termHeight = parseInt(rows.toString(), 10);
                term.resize(termWidth, termHeight);
                socket.send(JSON.stringify({
                    resize: {
                        cols: termWidth,
                        rows: termHeight
                    }
                }));
            }
        }));

        initializeWebSocket();
    </script>
    @endscript
</div>