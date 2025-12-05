<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Deployment | Coolify
        </x-slot>
        <h1 class="py-0">Deployment</h1>
        <livewire:project.shared.configuration-checker :resource="$application" />
        <livewire:project.application.heading :application="$application" />
        <div x-data="{
        fullscreen: false,
        alwaysScroll: {{ $isKeepAliveOn ? 'true' : 'false' }},
        intervalId: null,
        showTimestamps: true,
        colorLogs: localStorage.getItem('coolify-color-logs') === 'true',
        searchQuery: '',
        renderTrigger: 0,
        deploymentId: '{{ $application_deployment_queue->deployment_uuid ?? 'deployment' }}',
        makeFullscreen() {
            this.fullscreen = !this.fullscreen;
            if (this.fullscreen === false) {
                this.alwaysScroll = false;
                clearInterval(this.intervalId);
            }
        },
        isScrolling: false,
        toggleScroll() {
            this.alwaysScroll = !this.alwaysScroll;
            if (this.alwaysScroll) {
                this.intervalId = setInterval(() => {
                    const logsContainer = document.getElementById('logsContainer');
                    if (logsContainer) {
                        this.isScrolling = true;
                        logsContainer.scrollTop = logsContainer.scrollHeight;
                        setTimeout(() => { this.isScrolling = false; }, 50);
                    }
                }, 100);
            } else {
                clearInterval(this.intervalId);
                this.intervalId = null;
            }
        },
        handleScroll(event) {
            if (!this.alwaysScroll || this.isScrolling) return;
            const el = event.target;
            // Check if user scrolled away from the bottom
            const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
            if (distanceFromBottom > 50) {
                this.alwaysScroll = false;
                clearInterval(this.intervalId);
                this.intervalId = null;
            }
        },
        toggleColorLogs() {
            this.colorLogs = !this.colorLogs;
            localStorage.setItem('coolify-color-logs', this.colorLogs);
        },
        getLogLevel(text) {
            const lowerText = text.toLowerCase();
            // Error detection (highest priority)
            if (/\b(error|err|failed|failure|exception|fatal|panic|critical)\b/.test(lowerText)) {
                return 'error';
            }
            // Warning detection
            if (/\b(warn|warning|wrn|caution)\b/.test(lowerText)) {
                return 'warning';
            }
            // Debug detection
            if (/\b(debug|dbg|trace|verbose)\b/.test(lowerText)) {
                return 'debug';
            }
            // Info detection
            if (/\b(info|inf|notice)\b/.test(lowerText)) {
                return 'info';
            }
            return null;
        },
        matchesSearch(text) {
            if (!this.searchQuery.trim()) return true;
            return text.toLowerCase().includes(this.searchQuery.toLowerCase());
        },
        decodeHtml(text) {
            // Decode HTML entities, handling double-encoding with max iteration limit to prevent DoS
            let decoded = text;
            let prev = '';
            let iterations = 0;
            const maxIterations = 3; // Prevent DoS from deeply nested HTML entities

            while (decoded !== prev && iterations < maxIterations) {
                prev = decoded;
                const doc = new DOMParser().parseFromString(decoded, 'text/html');
                decoded = doc.documentElement.textContent;
                iterations++;
            }
            return decoded;
        },
        renderHighlightedLog(el, text) {
            const decoded = this.decodeHtml(text);
            el.textContent = '';

            if (!this.searchQuery.trim()) {
                el.textContent = decoded;
                return;
            }

            const query = this.searchQuery.toLowerCase();
            const lowerText = decoded.toLowerCase();
            let lastIndex = 0;

            let index = lowerText.indexOf(query, lastIndex);
            while (index !== -1) {
                // Add text before match
                if (index > lastIndex) {
                    el.appendChild(document.createTextNode(decoded.substring(lastIndex, index)));
                }
                // Add highlighted match
                const mark = document.createElement('span');
                mark.className = 'log-highlight';
                mark.textContent = decoded.substring(index, index + this.searchQuery.length);
                el.appendChild(mark);

                lastIndex = index + this.searchQuery.length;
                index = lowerText.indexOf(query, lastIndex);
            }

            // Add remaining text
            if (lastIndex < decoded.length) {
                el.appendChild(document.createTextNode(decoded.substring(lastIndex)));
            }
        },
        getMatchCount() {
            if (!this.searchQuery.trim()) return 0;
            const logs = document.getElementById('logs');
            if (!logs) return 0;
            const lines = logs.querySelectorAll('[data-log-line]');
            let count = 0;
            lines.forEach(line => {
                if (line.dataset.logContent && line.dataset.logContent.toLowerCase().includes(this.searchQuery.toLowerCase())) {
                    count++;
                }
            });
            return count;
        },
        downloadLogs() {
            const logs = document.getElementById('logs');
            if (!logs) return;
            const visibleLines = logs.querySelectorAll('[data-log-line]:not(.hidden)');
            let content = '';
            visibleLines.forEach(line => {
                const text = line.textContent.replace(/\s+/g, ' ').trim();
                if (text) {
                    content += text + String.fromCharCode(10);
                }
            });
            const blob = new Blob([content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const timestamp = new Date().toISOString().slice(0,19).replace(/[T:]/g, '-');
            a.download = 'deployment-' + this.deploymentId + '-' + timestamp + '.txt';
            a.click();
            URL.revokeObjectURL(url);
        },
        init() {
            // Re-render logs after Livewire updates
            document.addEventListener('livewire:navigated', () => {
                this.$nextTick(() => { this.renderTrigger++; });
            });
            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    this.$nextTick(() => { this.renderTrigger++; });
                });
            });
            // Start auto-scroll if deployment is in progress
            if (this.alwaysScroll) {
                this.intervalId = setInterval(() => {
                    const logsContainer = document.getElementById('logsContainer');
                    if (logsContainer) {
                        this.isScrolling = true;
                        logsContainer.scrollTop = logsContainer.scrollHeight;
                        setTimeout(() => { this.isScrolling = false; }, 50);
                    }
                }, 100);
            }
        }
    }">
            <livewire:project.application.deployment-navbar
                :application_deployment_queue="$application_deployment_queue" />
            @if (data_get($application_deployment_queue, 'status') === 'in_progress')
                <div class="flex items-center gap-1 pt-2 ">Deployment is
                    <div class="dark:text-warning">
                        {{ Str::headline(data_get($this->application_deployment_queue, 'status')) }}.
                    </div>
                    <x-loading class="loading-ring" />
                </div>
                {{-- <div class="">Logs will be updated automatically.</div> --}}
            @else
                <div class="pt-2 ">Deployment is <span
                        class="dark:text-warning">{{ Str::headline(data_get($application_deployment_queue, 'status')) }}</span>.
                </div>
            @endif
            <div id="screen" :class="fullscreen ? 'fullscreen flex flex-col' : 'relative'">
                <div @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif
                    class="flex flex-col w-full bg-white dark:text-white dark:bg-coolgray-100 dark:border-coolgray-300"
                    :class="fullscreen ? 'h-full' : 'mt-4 border border-dotted rounded-sm'">
                    <div
                        class="flex items-center justify-between gap-2 px-4 py-2 border-b dark:border-coolgray-300 border-neutral-200 shrink-0">
                        <span x-show="searchQuery.trim()" x-text="getMatchCount() + ' matches'"
                            class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"></span>
                        <span x-show="!searchQuery.trim()"></span>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <svg class="absolute left-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                </svg>
                                <input type="text" x-model="searchQuery" placeholder="Find in logs"
                                    class="input input-sm w-48 pl-8 pr-8 dark:bg-coolgray-200" />
                                <button x-show="searchQuery" x-on:click="searchQuery = ''" type="button"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <button x-on:click="downloadLogs()" title="Download Logs"
                                class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                            </button>
                            <button title="Toggle Timestamps" x-on:click="showTimestamps = !showTimestamps"
                                :class="showTimestamps ? '!text-warning' : ''"
                                class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </button>
                            <button wire:click="toggleDebug"
                                title="{{ $is_debug_enabled ? 'Hide Debug Logs' : 'Show Debug Logs' }}"
                                class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 {{ $is_debug_enabled ? '!text-warning' : '' }}">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 12.75c1.148 0 2.278.08 3.383.237 1.037.146 1.866.966 1.866 2.013 0 3.728-2.35 6.75-5.25 6.75S6.75 18.728 6.75 15c0-1.046.83-1.867 1.866-2.013A24.204 24.204 0 0 1 12 12.75Zm0 0c2.883 0 5.647.508 8.207 1.44a23.91 23.91 0 0 1-1.152 6.06M12 12.75c-2.883 0-5.647.508-8.208 1.44.125 2.104.52 4.136 1.153 6.06M12 12.75a2.25 2.25 0 0 0 2.248-2.354M12 12.75a2.25 2.25 0 0 1-2.248-2.354M12 8.25c.995 0 1.971-.08 2.922-.236.403-.066.74-.358.795-.762a3.778 3.778 0 0 0-.399-2.25M12 8.25c-.995 0-1.97-.08-2.922-.236-.402-.066-.74-.358-.795-.762a3.734 3.734 0 0 1 .4-2.253M12 8.25a2.25 2.25 0 0 0-2.248 2.146M12 8.25a2.25 2.25 0 0 1 2.248 2.146M8.683 5a6.032 6.032 0 0 1-1.155-1.002c.07-.63.27-1.222.574-1.747m.581 2.749A3.75 3.75 0 0 1 15.318 5m0 0c.427-.283.815-.62 1.155-.999a4.471 4.471 0 0 0-.575-1.752M4.921 6a24.048 24.048 0 0 0-.392 3.314c1.668.546 3.416.914 5.223 1.082M19.08 6c.205 1.08.337 2.187.392 3.314a23.882 23.882 0 0 1-5.223 1.082" />
                                </svg>
                            </button>
                            <button title="Toggle Log Colors" x-on:click="toggleColorLogs"
                                :class="colorLogs ? '!text-warning' : ''"
                                class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M4.098 19.902a3.75 3.75 0 0 0 5.304 0l6.401-6.402M6.75 21A3.75 3.75 0 0 1 3 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 0 0 3.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008Z" />
                                </svg>
                            </button>
                            <button title="Follow Logs" :class="alwaysScroll ? '!text-warning' : ''"
                                x-on:click="toggleScroll"
                                class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round"
                                        stroke-linejoin="round" stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
                                </svg>
                            </button>
                            <button title="Fullscreen" x-show="!fullscreen" x-on:click="makeFullscreen"
                                class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <g fill="none">
                                        <path
                                            d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
                                        <path fill="currentColor"
                                            d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                                    </g>
                                </svg>
                            </button>
                            <button title="Minimize" x-show="fullscreen" x-on:click="makeFullscreen"
                                class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round"
                                        stroke-linejoin="round" stroke-width="2"
                                        d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div id="logsContainer" @scroll="handleScroll"
                        class="flex flex-col overflow-y-auto p-2 px-4 min-h-4 scrollbar"
                        :class="fullscreen ? 'flex-1' : 'max-h-[40rem]'">
                        <div id="logs" class="flex flex-col font-mono">
                            <div x-show="searchQuery.trim() && getMatchCount() === 0"
                                class="text-gray-500 dark:text-gray-400 py-2">
                                No matches found.
                            </div>
                            @forelse ($this->logLines as $line)
                                @php
                                    $lineContent = (isset($line['command']) && $line['command'] ? '[CMD]: ' : '') . trim($line['line']);
                                    $searchableContent = $line['timestamp'] . ' ' . $lineContent;
                                @endphp
                                <div data-log-line data-log-content="{{ htmlspecialchars($searchableContent) }}"
                                    x-bind:class="{
                                        'hidden': !matchesSearch($el.dataset.logContent),
                                        'bg-red-500/10 dark:bg-red-500/15': colorLogs && getLogLevel($el.dataset.logContent) === 'error',
                                        'bg-yellow-500/10 dark:bg-yellow-500/15': colorLogs && getLogLevel($el.dataset.logContent) === 'warning',
                                        'bg-purple-500/10 dark:bg-purple-500/15': colorLogs && getLogLevel($el.dataset.logContent) === 'debug',
                                        'bg-blue-500/10 dark:bg-blue-500/15': colorLogs && getLogLevel($el.dataset.logContent) === 'info',
                                    }" @class([
                                        'mt-2' => isset($line['command']) && $line['command'],
                                        'flex gap-2',
                                    ])>
                                    <span x-show="showTimestamps"
                                        class="shrink-0 text-gray-500">{{ $line['timestamp'] }}</span>
                                    <span data-line-text="{{ htmlspecialchars($lineContent) }}" @class([
                                        'text-success dark:text-warning' => $line['hidden'],
                                        'text-red-500' => $line['stderr'],
                                        'font-bold' => isset($line['command']) && $line['command'],
                                        'whitespace-pre-wrap',
                                    ])
                                        x-effect="renderTrigger; searchQuery; renderHighlightedLog($el, $el.dataset.lineText)"></span>
                                </div>
                            @empty
                                <span class="font-mono text-neutral-400 mb-2">No logs yet.</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
</div>