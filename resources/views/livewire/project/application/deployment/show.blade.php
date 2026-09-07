<div class="flex min-h-[calc(100dvh-7.5rem)] flex-col">
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Deployment | Coolify
        </x-slot>
        <livewire:project.shared.configuration-checker :resource="$application" />
        <livewire:project.application.heading :application="$application" wire:key="application-heading-deployment-show" />
        <section class="application-settings-workspace mt-4 flex min-h-0 w-full max-w-none flex-1 lg:mt-0">
            <div class="grid min-h-0 min-w-0 flex-1 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
                <x-application.configuration-sidebar :application="$application" :flush="true"
                    current-route="project.application.deployment.show" />
                <div class="flex min-h-0 min-w-0 flex-col gap-4 xl:h-full xl:pt-px">
                    <livewire:project.application.deployment.index :embedded="true"
                        :selected-deployment-uuid="$deployment_uuid"
                        wire:key="embedded-deployment-history-{{ $deployment_uuid }}" />

                    <div x-data="{
        fullscreen: @entangle('fullscreen'),
        alwaysScroll: {{ $isKeepAliveOn ? 'true' : 'false' }},
        followManuallyDisabled: false,
        rafId: null,
        scrollTimeout: null,
        scrollDebounce: null,
        isScrolling: false,
        destroyed: false,
        morphUpdatedCleanup: null,
        deploymentFinishedCleanup: null,
        lastTouchY: 0,
        showTimestamps: true,
        searchQuery: '',
        matchCount: 0,
        deploymentId: '{{ $application_deployment_queue->deployment_uuid ?? 'deployment' }}',
        makeFullscreen() {
            this.fullscreen = !this.fullscreen;
        },
        scrollToBottom() {
            if (this.destroyed) return;
            const logsContainer = this.$root.querySelector('#logsContainer');
            if (logsContainer) {
                this.isScrolling = true;
                logsContainer.scrollTop = logsContainer.scrollHeight;
                requestAnimationFrame(() => { this.isScrolling = false; });
            }
        },
        cancelScrollLoop() {
            if (this.rafId) {
                cancelAnimationFrame(this.rafId);
                this.rafId = null;
            }
            if (this.scrollTimeout) {
                clearTimeout(this.scrollTimeout);
                this.scrollTimeout = null;
            }
            if (this.scrollDebounce) {
                clearTimeout(this.scrollDebounce);
                this.scrollDebounce = null;
            }
        },
        disableFollow() {
            if (!this.alwaysScroll) return;
            this.alwaysScroll = false;
            this.cancelScrollLoop();
        },
        handleWheel(event) {
            if (this.alwaysScroll && event.deltaY < 0) {
                this.disableFollow();
            }
        },
        handleTouchStart(event) {
            this.lastTouchY = event.touches[0].clientY;
        },
        handleTouchMove(event) {
            if (!this.alwaysScroll) return;
            const currentY = event.touches[0].clientY;
            if (currentY > this.lastTouchY) {
                this.disableFollow();
            }
            this.lastTouchY = currentY;
        },
        handleKeyScroll(event) {
            if (!this.alwaysScroll) return;
            const upKeys = ['ArrowUp', 'PageUp', 'Home'];
            if (upKeys.includes(event.key)) {
                this.disableFollow();
            }
        },
        handleScroll(event) {
            if (this.isScrolling || this.destroyed) return;
            const el = event.target;
            clearTimeout(this.scrollDebounce);
            this.scrollDebounce = setTimeout(() => {
                if (this.destroyed) return;
                const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
                if (!this.alwaysScroll && !this.followManuallyDisabled && distanceFromBottom <= 10) {
                    this.alwaysScroll = true;
                    this.scheduleScroll();
                }
            }, 150);
        },
        scheduleScroll() {
            if (!this.alwaysScroll || this.destroyed) return;
            this.rafId = requestAnimationFrame(() => {
                if (!this.alwaysScroll || this.destroyed) return;
                this.scrollToBottom();
                if (this.alwaysScroll && !this.destroyed) {
                    this.scrollTimeout = setTimeout(() => this.scheduleScroll(), 250);
                }
            });
        },
        toggleScroll() {
            this.alwaysScroll = !this.alwaysScroll;
            if (this.alwaysScroll) {
                this.followManuallyDisabled = false;
                this.scheduleScroll();
            } else {
                this.followManuallyDisabled = true;
                this.cancelScrollLoop();
            }
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
        highlightText(el, text, query) {
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

                if (textSpan) {
                    const originalText = textSpan.dataset.lineText || '';
                    if (!query) {
                        textSpan.textContent = originalText;
                    } else if (matches) {
                        this.highlightText(textSpan, originalText, query);
                    }
                }
            });

            this.matchCount = query ? count : 0;
        },
        collectVisibleLogs() {
            const logs = document.getElementById('logs');
            if (!logs) return '';
            const visibleLines = logs.querySelectorAll('[data-log-line]:not(.hidden)');
            let content = '';
            visibleLines.forEach(line => {
                const text = line.textContent.replace(/\s+/g, ' ').trim();
                if (text) {
                    content += text + String.fromCharCode(10);
                }
            });
            return content;
        },
        copyLogs() {
            const content = this.collectVisibleLogs();
            if (!content) return;
            if (!navigator.clipboard?.writeText) {
                Livewire.dispatch('error', ['Clipboard is not available. Please use HTTPS or localhost.']);
                return;
            }
            navigator.clipboard?.writeText(content).then(() => {
                Livewire.dispatch('success', ['Logs copied to clipboard.']);
            }).catch(() => {
                Livewire.dispatch('error', ['Failed to copy logs to clipboard.']);
            });
        },
        downloadLogs() {
            const content = this.collectVisibleLogs();
            if (!content) return;
            const blob = new Blob([content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const timestamp = new Date().toISOString().slice(0,19).replace(/[T:]/g, '-');
            a.download = 'deployment-' + this.deploymentId + '-' + timestamp + '.txt';
            a.click();
            URL.revokeObjectURL(url);
        },
        stopScroll() {
            this.scrollToBottom();
            this.alwaysScroll = false;
            this.cancelScrollLoop();
        },
        init() {
            // Watch search query changes
            this.$watch('searchQuery', () => {
                this.applySearch();
            });

            // Apply search after Livewire updates.
            // Livewire.hook() returns an unregister fn; keep it for destroy().
            this.morphUpdatedCleanup = Livewire.hook('morph.updated', ({ el }) => {
                if (this.destroyed) return;
                if (el.id !== 'logs' || !this.$root.contains(el)) return;
                this.$nextTick(() => {
                    if (this.destroyed) return;
                    this.applySearch();
                    if (this.alwaysScroll) {
                        this.scrollToBottom();
                    }
                });
            });

            // Stop auto-scroll when deployment finishes.
            // Livewire.on() returns an unregister fn; keep it for destroy().
            this.deploymentFinishedCleanup = Livewire.on('deploymentFinished', () => {
                if (this.destroyed) return;
                setTimeout(() => {
                    if (this.destroyed) return;
                    this.stopScroll();
                }, 500);
            });

            // Start auto-scroll if deployment is in progress
            if (this.alwaysScroll) {
                this.scheduleScroll();
            }
        },
        destroy() {
            // Runs when Alpine tears the component down (wire:navigate away).
            this.destroyed = true;
            this.alwaysScroll = false;
            this.cancelScrollLoop();
            if (this.scrollDebounce) {
                clearTimeout(this.scrollDebounce);
                this.scrollDebounce = null;
            }
            if (typeof this.morphUpdatedCleanup === 'function') {
                this.morphUpdatedCleanup();
                this.morphUpdatedCleanup = null;
            }
            if (typeof this.deploymentFinishedCleanup === 'function') {
                this.deploymentFinishedCleanup();
                this.deploymentFinishedCleanup = null;
            }
        }
    }" class="flex h-[calc(100dvh-8rem)] min-h-[32rem] w-full flex-col overflow-hidden xl:h-[32rem] xl:min-h-0 xl:flex-none">
            <div id="screen" :class="fullscreen ? 'fullscreen flex flex-col' : 'mt-2 flex flex-1 min-h-0 flex-col overflow-hidden lg:mt-0'">
                <div @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif
                    class="logs-viewer flex min-h-0 w-full flex-col overflow-hidden bg-white text-neutral-800 dark:bg-log dark:text-neutral-100"
                    :class="fullscreen ? 'h-full' : 'flex-1 rounded-xl border border-neutral-200 shadow-sm dark:border-coolgray-200'">
                    <div class="logs-viewer-toolbar">
                        @php
                            $deploymentStatus = data_get($application_deployment_queue, 'status');
                            $deploymentStatusLabel = $deploymentStatus === 'in_progress'
                                ? 'In progress'
                                : Str::headline((string) $deploymentStatus);
                            $deploymentStatusType = match ($deploymentStatus) {
                                'finished' => 'success',
                                'failed' => 'error',
                                'queued', 'in_progress' => 'warning',
                                default => 'neutral',
                            };
                        @endphp
                        <div class="logs-viewer-toolbar-controls">
                            <div class="logs-viewer-primary">
                                <div class="logs-viewer-actions">
                                <button title="Toggle Timestamps" x-on:click="showTimestamps = !showTimestamps"
                                    :class="showTimestamps ? 'logs-viewer-btn-active' : ''"
                                    class="logs-viewer-btn">
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                </button>
                                <button title="Follow Logs" :class="alwaysScroll ? 'logs-viewer-btn-active' : ''"
                                    x-on:click="toggleScroll"
                                    class="logs-viewer-btn">
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="none" stroke="currentColor" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
                                    </svg>
                                </button>
                                @can('update', $application)
                                <button wire:click="toggleDebug"
                                    title="{{ $is_debug_enabled ? 'Hide Debug Logs' : 'Show Debug Logs' }}"
                                    class="logs-viewer-btn {{ $is_debug_enabled ? 'logs-viewer-btn-active' : '' }}">
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 12.75c1.148 0 2.278.08 3.383.237 1.037.146 1.866.966 1.866 2.013 0 3.728-2.35 6.75-5.25 6.75S6.75 18.728 6.75 15c0-1.046.83-1.867 1.866-2.013A24.204 24.204 0 0 1 12 12.75Zm0 0c2.883 0 5.647.508 8.207 1.44a23.91 23.91 0 0 1-1.152 6.06M12 12.75c-2.883 0-5.647.508-8.208 1.44.125 2.104.52 4.136 1.153 6.06M12 12.75a2.25 2.25 0 0 0 2.248-2.354M12 12.75a2.25 2.25 0 0 1-2.248-2.354M12 8.25c.995 0 1.971-.08 2.922-.236.403-.066.74-.358.795-.762a3.778 3.778 0 0 0-.399-2.25M12 8.25c-.995 0-1.97-.08-2.922-.236-.402-.066-.74-.358-.795-.762a3.734 3.734 0 0 1 .4-2.253M12 8.25a2.25 2.25 0 0 0-2.248 2.146M12 8.25a2.25 2.25 0 0 1 2.248 2.146M8.683 5a6.032 6.032 0 0 1-1.155-1.002c.07-.63.27-1.222.574-1.747m.581 2.749A3.75 3.75 0 0 1 15.318 5m0 0c.427-.283.815-.62 1.155-.999a4.471 4.471 0 0 0-.575-1.752M4.921 6a24.048 24.048 0 0 0-.392 3.314c1.668.546 3.416.914 5.223 1.082M19.08 6c.205 1.08.337 2.187.392 3.314a23.882 23.882 0 0 1-5.223 1.082" />
                                    </svg>
                                </button>
                                @endcan
                                <button title="Fullscreen" x-show="!fullscreen" x-on:click="makeFullscreen"
                                    class="logs-viewer-btn">
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <g fill="none">
                                            <path
                                                d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
                                            <path fill="currentColor"
                                                d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                                        </g>
                                    </svg>
                                </button>
                                <button title="Minimize" x-show="fullscreen" x-on:click="makeFullscreen"
                                    class="logs-viewer-btn">
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="none" stroke="currentColor" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2"
                                            d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                                    </svg>
                                </button>
                                <button
                                    x-on:click="copyLogs()"
                                    title="Copy Logs"
                                    class="logs-viewer-btn">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                                    </svg>
                                </button>
                                <div x-data="{ downloadMenuOpen: false, downloadingAllLogs: false }" class="relative shrink-0">
                                    <button x-on:click="downloadMenuOpen = !downloadMenuOpen" title="Download Logs"
                                        class="logs-viewer-btn">
                                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                        </svg>
                                    </button>
                                    <div x-show="downloadMenuOpen" x-on:click.away="downloadMenuOpen = false"
                                        x-transition:enter="transition ease-out duration-100"
                                        x-transition:enter-start="transform opacity-0 scale-95"
                                        x-transition:enter-end="transform opacity-100 scale-100"
                                        x-transition:leave="transition ease-in duration-75"
                                        x-transition:leave-start="transform opacity-100 scale-100"
                                        x-transition:leave-end="transform opacity-0 scale-95"
                                        class="absolute right-0 z-50 mt-2 w-max origin-top-right rounded-lg border border-neutral-200 bg-white p-1 shadow-modal focus:outline-none dark:border-white/[0.1] dark:bg-[#181818]">
                                        <div>
                                            <button x-on:click="downloadLogs(); downloadMenuOpen = false"
                                                class="listbox-option text-neutral-700! hover:bg-neutral-100! dark:text-neutral-200! dark:hover:bg-white/[0.07]!">
                                                Download displayed logs
                                            </button>
                                            @can('update', $application)
                                            <button x-on:click="
                                                downloadingAllLogs = true;
                                                $wire.downloadAllLogs().then(logs => {
                                                    if (!logs) return;
                                                    const blob = new Blob([logs], { type: 'text/plain' });
                                                    const url = URL.createObjectURL(blob);
                                                    const a = document.createElement('a');
                                                    a.href = url;
                                                    const timestamp = new Date().toISOString().slice(0,19).replace(/[T:]/g, '-');
                                                    a.download = 'deployment-' + deploymentId + '-all-logs-' + timestamp + '.txt';
                                                    a.click();
                                                    URL.revokeObjectURL(url);
                                                    Livewire.dispatch('success', ['All logs downloaded.']);
                                                }).finally(() => {
                                                    downloadingAllLogs = false;
                                                    downloadMenuOpen = false;
                                                });
                                            "
                                                :disabled="downloadingAllLogs"
                                                :class="{ 'opacity-50 cursor-not-allowed': downloadingAllLogs }"
                                                class="listbox-option text-neutral-700! hover:bg-neutral-100! dark:text-neutral-200! dark:hover:bg-white/[0.07]!">
                                                <span x-show="!downloadingAllLogs">Download all logs</span>
                                                <span x-show="downloadingAllLogs" class="flex items-center gap-2">
                                                    <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                    Downloading...
                                                </span>
                                            </button>
                                            @endcan
                                        </div>
                                    </div>
                                </div>
                                </div>
                                <x-status-badge :status="$deploymentStatusLabel" :type="$deploymentStatusType"
                                    class="logs-viewer-status-badge" />
                            </div>
                            <div class="logs-viewer-end">
                                <livewire:project.application.deployment-navbar
                                    :application_deployment_queue="$application_deployment_queue" />
                            <div class="logs-viewer-search relative">
                                <x-reicon name="search"
                                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-neutral-500" />
                                <input type="search" x-model.debounce.300ms="searchQuery" placeholder="Find in logs"
                                    aria-label="Find in logs"
                                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! text-neutral-800! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.05]! dark:text-white! dark:placeholder:text-neutral-500" />
                                <button x-cloak x-show="searchQuery" x-on:click="searchQuery = ''" type="button"
                                    class="absolute top-1/2 right-2 z-10 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-neutral-800 dark:text-neutral-500 dark:hover:bg-white/[0.07] dark:hover:text-white"
                                    aria-label="Clear search">
                                    <x-reicon name="x" class="size-3" />
                                </button>
                            </div>
                            <div class="logs-viewer-meta">
                                <span x-show="searchQuery.trim()" x-text="matchCount + ' matches'"
                                    class="text-xs text-neutral-500 whitespace-nowrap"></span>
                            </div>
                            </div>
                        </div>
                    </div>
                    <div id="logsContainer" @scroll="handleScroll" @wheel="handleWheel"
                        @touchstart="handleTouchStart" @touchmove="handleTouchMove" @keydown="handleKeyScroll" tabindex="0"
                        class="logs-viewer-viewport flex min-h-40 flex-1 flex-col overflow-y-auto overflow-x-hidden scrollbar">
                        <div id="logs" class="flex min-w-0 flex-col font-logs text-[11px] leading-relaxed sm:text-xs">
                            <div x-show="searchQuery.trim() && matchCount === 0"
                                class="py-2 text-neutral-500">
                                No matches found.
                            </div>
                            @forelse ($this->logLines as $line)
                                @php
                                    $lineContent = (isset($line['command']) && $line['command'] ? '[CMD]: ' : '') . trim($line['line']);
                                    $searchableContent = $line['timestamp'] . ' ' . $lineContent;
                                @endphp
                                <div data-log-line data-log-content="{{ $searchableContent }}"
                                    @class([
                                        'mt-2' => isset($line['command']) && $line['command'],
                                        'log-line logs-viewer-line',
                                    ])>
                                    <span x-show="showTimestamps"
                                        class="logs-viewer-timestamp">{{ $line['timestamp'] }}</span>
                                    <span data-line-text="{{ $lineContent }}"
                                        @class([
                                            'text-success dark:text-warning' => $line['hidden'],
                                            'text-red-500' => $line['stderr'],
                                            'font-bold' => isset($line['command']) && $line['command'],
                                            'logs-viewer-line-text',
                                        ])>{{ $lineContent }}</span>
                                </div>
                            @empty
                                <span class="mb-2 font-logs text-neutral-400">No logs yet.</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
                </div>
            </div>
        </section>
</div>
