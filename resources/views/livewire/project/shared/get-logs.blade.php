<div class="{{ $collapsible ? 'my-4 border dark:border-coolgray-200 border-neutral-200' : '' }}">
    <div id="screen" x-data="{
        collapsible: {{ $collapsible ? 'true' : 'false' }},
        expanded: {{ ($expandByDefault || !$collapsible) ? 'true' : 'false' }},
        logsLoaded: false,
        fullscreen: false,
        alwaysScroll: false,
        rafId: null,
        scrollDebounce: null,
        colorLogs: localStorage.getItem('coolify-color-logs') === 'true',
        searchQuery: '',
        matchCount: 0,
        containerName: '{{ $container ?? "logs" }}',
        makeFullscreen() {
            this.fullscreen = !this.fullscreen;
            if (this.fullscreen === false) {
                this.alwaysScroll = false;
                if (this.rafId) {
                    cancelAnimationFrame(this.rafId);
                    this.rafId = null;
                }
            }
        },
        handleKeyDown(event) {
            if (event.key === 'Escape' && this.fullscreen) {
                this.makeFullscreen();
            }
        },
        isScrolling: false,
        scrollToBottom() {
            const logsContainer = document.getElementById('logsContainer');
            if (logsContainer) {
                this.isScrolling = true;
                logsContainer.scrollTop = logsContainer.scrollHeight;
                setTimeout(() => { this.isScrolling = false; }, 50);
            }
        },
        scheduleScroll() {
            if (!this.alwaysScroll) return;
            this.rafId = requestAnimationFrame(() => {
                this.scrollToBottom();
                if (this.alwaysScroll) {
                    setTimeout(() => this.scheduleScroll(), 250);
                }
            });
        },
        toggleScroll() {
            this.alwaysScroll = !this.alwaysScroll;
            if (this.alwaysScroll) {
                this.scheduleScroll();
            } else {
                if (this.rafId) {
                    cancelAnimationFrame(this.rafId);
                    this.rafId = null;
                }
            }
        },
        handleScroll(event) {
            if (!this.alwaysScroll || this.isScrolling) return;
            clearTimeout(this.scrollDebounce);
            this.scrollDebounce = setTimeout(() => {
                const el = event.target;
                const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
                if (distanceFromBottom > 100) {
                    this.alwaysScroll = false;
                    if (this.rafId) {
                        cancelAnimationFrame(this.rafId);
                        this.rafId = null;
                    }
                }
            }, 150);
        },
        toggleColorLogs() {
            this.colorLogs = !this.colorLogs;
            localStorage.setItem('coolify-color-logs', this.colorLogs);
            this.applyColorLogs();
        },
        applyColorLogs() {
            const logs = document.getElementById('logs');
            if (!logs) return;
            const lines = logs.querySelectorAll('[data-log-line]');
            lines.forEach(line => {
                const content = (line.dataset.logContent || '').toLowerCase();
                line.classList.remove('log-error', 'log-warning', 'log-debug', 'log-info');
                if (!this.colorLogs) return;
                if (/\b(error|err|failed|failure|exception|fatal|panic|critical)\b/.test(content)) {
                    line.classList.add('log-error');
                } else if (/\b(warn|warning|wrn|caution)\b/.test(content)) {
                    line.classList.add('log-warning');
                } else if (/\b(debug|dbg|trace|verbose)\b/.test(content)) {
                    line.classList.add('log-debug');
                } else if (/\b(info|inf|notice)\b/.test(content)) {
                    line.classList.add('log-info');
                }
            });
        },
        hasActiveLogSelection() {
            const selection = window.getSelection();
            if (!selection || selection.isCollapsed || !selection.toString().trim()) {
                return false;
            }
            const logsContainer = document.getElementById('logs');
            if (!logsContainer) return false;
            const range = selection.getRangeAt(0);
            return logsContainer.contains(range.commonAncestorContainer);
        },
        decodeHtml(text) {
            const doc = new DOMParser().parseFromString(text, 'text/html');
            return doc.documentElement.textContent;
        },
        applySearch() {
            const logs = document.getElementById('logs');
            if (!logs) return;
            const lines = logs.querySelectorAll('[data-log-line]');
            const query = this.searchQuery.trim().toLowerCase();
            let count = 0;

            lines.forEach(line => {
                const content = (line.dataset.logContent || '').toLowerCase();
                const textSpan = line.querySelector('[data-line-text]');
                const matches = !query || content.includes(query);

                line.classList.toggle('hidden', !matches);
                if (matches && query) count++;

                // Update highlighting
                if (textSpan) {
                    const originalText = this.decodeHtml(textSpan.dataset.lineText || '');
                    if (!query) {
                        textSpan.textContent = originalText;
                    } else if (matches) {
                        this.highlightText(textSpan, originalText, query);
                    }
                }
            });

            this.matchCount = query ? count : 0;
        },
        highlightText(el, text, query) {
            // Skip if user has selection
            if (this.hasActiveLogSelection()) return;

            el.textContent = '';
            const lowerText = text.toLowerCase();
            let lastIndex = 0;
            let index = lowerText.indexOf(query, lastIndex);

            while (index !== -1) {
                if (index > lastIndex) {
                    el.appendChild(document.createTextNode(text.substring(lastIndex, index)));
                }
                const mark = document.createElement('span');
                mark.className = 'log-highlight';
                mark.textContent = text.substring(index, index + query.length);
                el.appendChild(mark);
                lastIndex = index + query.length;
                index = lowerText.indexOf(query, lastIndex);
            }

            if (lastIndex < text.length) {
                el.appendChild(document.createTextNode(text.substring(lastIndex)));
            }
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
            a.download = this.containerName + '-logs-' + timestamp + '.txt';
            a.click();
            URL.revokeObjectURL(url);
        },
        init() {
            if (this.expanded) {
                this.$wire.getLogs(true);
                this.logsLoaded = true;
            }

            // Watch search query changes
            this.$watch('searchQuery', () => {
                this.applySearch();
            });

            // Handler for applying colors and search after DOM changes
            const applyAfterUpdate = () => {
                this.$nextTick(() => {
                    this.applyColorLogs();
                    this.applySearch();
                    if (this.alwaysScroll) {
                        this.scrollToBottom();
                    }
                });
            };

            // Apply colors after Livewire updates (existing content)
            Livewire.hook('morph.updated', ({ el }) => {
                if (el.id === 'logs') {
                    applyAfterUpdate();
                }
            });

            // Apply colors after Livewire adds new content (initial load)
            Livewire.hook('morph.added', ({ el }) => {
                if (el.id === 'logs') {
                    applyAfterUpdate();
                }
            });
        }
    }" @keydown.window="handleKeyDown($event)">
        @if ($collapsible)
            <div class="flex gap-2 items-center p-4 cursor-pointer select-none hover:bg-gray-50 dark:hover:bg-coolgray-200"
                x-on:click="expanded = !expanded; if (expanded && !logsLoaded) { $wire.getLogs(true); logsLoaded = true; }">
                <svg class="w-4 h-4 transition-transform" :class="expanded ? 'rotate-90' : ''" viewBox="0 0 24 24"
                    xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor" d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                </svg>
                @if ($displayName)
                    <h4>{{ $displayName }}</h4>
                @elseif ($resource?->type() === 'application' || str($resource?->type())->startsWith('standalone'))
                    <h4>{{ $container }}</h4>
                @else
                    <h4>{{ str($container)->beforeLast('-')->headline() }}</h4>
                @endif
                @if ($pull_request)
                    <div>({{ $pull_request }})</div>
                @endif
                @if ($streamLogs)
                    <x-loading wire:poll.2000ms='getLogs(true)' />
                @endif
            </div>
        @endif
        <div x-show="expanded" {{ $collapsible ? 'x-collapse' : '' }}
            :class="fullscreen ? 'fullscreen flex flex-col !overflow-visible' : 'relative w-full {{ $collapsible ? 'py-4' : '' }} mx-auto'"
            :style="fullscreen ? 'max-height: none !important; height: 100% !important;' : ''">
            <div class="flex flex-col dark:text-white dark:border-coolgray-300 border-neutral-200"
                :class="fullscreen ? 'h-full w-full bg-white dark:bg-coolgray-100' : 'bg-white dark:bg-coolgray-100 border border-solid rounded-sm'">
                <div
                    class="flex items-center justify-between gap-2 px-4 py-2 border-b dark:border-coolgray-300 border-neutral-200 shrink-0">
                    <div class="flex items-center gap-2">
                        <form wire:submit="getLogs(true)" class="relative flex items-center">
                            <span
                                class="absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">Lines:</span>
                            <input type="number" wire:model="numberOfLines" placeholder="100" min="1"
                                title="Number of Lines" {{ $streamLogs ? 'readonly' : '' }}
                                class="input input-sm w-32 pl-11 text-center dark:bg-coolgray-300" />
                        </form>
                        <span x-show="searchQuery.trim()" x-text="matchCount + ' matches'"
                            class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <svg class="absolute left-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                            </svg>
                            <input type="text" x-model.debounce.300ms="searchQuery" placeholder="Find in logs"
                                class="input input-sm w-48 pl-8 pr-8 dark:bg-coolgray-300" />
                            <button x-show="searchQuery" x-on:click="searchQuery = ''" type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <button wire:click="getLogs(true)" title="Refresh Logs" {{ $streamLogs ? 'disabled' : '' }}
                            class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 disabled:opacity-50">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        </button>
                        <button wire:click="toggleStreamLogs"
                            title="{{ $streamLogs ? 'Stop Streaming' : 'Stream Logs' }}"
                            class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 {{ $streamLogs ? '!text-warning' : '' }}">
                            @if ($streamLogs)
                                {{-- Pause icon --}}
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                    fill="currentColor">
                                    <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" />
                                </svg>
                            @else
                                {{-- Play icon --}}
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                    fill="currentColor">
                                    <path d="M8 5v14l11-7L8 5z" />
                                </svg>
                            @endif
                        </button>
                        <button
                            x-on:click="
                                $wire.copyLogs().then(logs => {
                                    navigator.clipboard.writeText(logs);
                                    Livewire.dispatch('success', ['Logs copied to clipboard.']);
                                });
                            "
                            title="Copy Logs"
                            class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                            </svg>
                        </button>
                        <button x-on:click="downloadLogs()" title="Download Logs"
                            class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                        </button>
                        <button wire:click="toggleTimestamps" title="Toggle Timestamps"
                            class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 {{ $showTimeStamps ? '!text-warning' : '' }}">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </button>
                        <button title="Toggle Log Colors" x-on:click="toggleColorLogs"
                            :class="colorLogs ? '!text-warning' : ''"
                            class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" />
                            </svg>
                        </button>
                        <button title="Follow Logs" :class="alwaysScroll ? '!text-warning' : ''"
                            x-on:click="toggleScroll"
                            class="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
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
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div id="logsContainer" @scroll="handleScroll"
                    class="flex overflow-y-auto overflow-x-hidden flex-col px-4 py-2 w-full min-w-0 scrollbar"
                    :class="fullscreen ? 'flex-1' : 'max-h-[40rem]'">
                    @if ($outputs)
                        @php
                            // Limit rendered lines to prevent memory exhaustion
                            $maxDisplayLines = 2000;
                            $allLines = collect(explode("\n", $outputs))->filter(fn($line) => trim($line) !== '');
                            $totalLines = $allLines->count();
                            $hasMoreLines = $totalLines > $maxDisplayLines;
                            $displayLines = $hasMoreLines ? $allLines->slice(-$maxDisplayLines)->values() : $allLines;
                        @endphp
                        <div id="logs" class="font-mono max-w-full cursor-default">
                            @if ($hasMoreLines)
                                <div class="text-center py-2 text-gray-500 dark:text-gray-400 text-sm border-b dark:border-coolgray-300 mb-2">
                                    Showing last {{ number_format($maxDisplayLines) }} of {{ number_format($totalLines) }} lines
                                </div>
                            @endif
                            <div x-show="searchQuery.trim() && matchCount === 0"
                                class="text-gray-500 dark:text-gray-400 py-2">
                                No matches found.
                            </div>
                            @foreach ($displayLines as $index => $line)
                                @php
                                    // Parse timestamp from log line (ISO 8601 format: 2025-12-04T11:48:39.136764033Z)
                                    $timestamp = '';
                                    $logContent = $line;
                                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d+))?Z?\s*(.*)$/', $line, $matches)) {
                                        $year = $matches[1];
                                        $month = $matches[2];
                                        $day = $matches[3];
                                        $time = $matches[4];
                                        $microseconds = isset($matches[5]) ? substr($matches[5], 0, 6) : '000000';
                                        $logContent = $matches[6];

                                        // Convert month number to abbreviated name
                                        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                        $monthName = $monthNames[(int)$month - 1] ?? $month;

                                        // Format for display: 2025-Dec-04 09:44:58
                                        $timestamp = "{$year}-{$monthName}-{$day} {$time}";
                                        // Include microseconds in key for uniqueness
                                        $lineKey = "{$timestamp}.{$microseconds}";
                                    }
                                @endphp
                                <div wire:key="{{ $lineKey ?? 'line-' . $index }}" data-log-line data-log-content="{{ $line }}" class="flex gap-2 log-line">
                                    @if ($timestamp && $showTimeStamps)
                                        <span class="shrink-0 text-gray-500">{{ $timestamp }}</span>
                                    @endif
                                    <span data-line-text="{{ $logContent }}" class="whitespace-pre-wrap break-all">{{ $logContent }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <pre id="logs"
                            class="font-mono whitespace-pre-wrap break-all max-w-full text-neutral-400">No logs yet.</pre>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
