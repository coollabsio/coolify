<div class="flex min-h-[calc(100dvh-7.5rem)] flex-col">
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Deployment | Coolify
        </x-slot>
        <livewire:project.shared.configuration-checker :resource="$application" />
        <livewire:project.application.heading :application="$application" wire:key="application-heading-deployment-show" />
        <section class="application-settings-workspace mt-4 flex min-h-0 w-full max-w-none flex-1 lg:mt-0">
            <div class="grid min-h-0 min-w-0 flex-1 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
                <x-application.configuration-sidebar :application="$application"
                    current-route="project.application.deployment.show" />
                <div class="flex min-h-0 min-w-0 flex-col gap-4 xl:h-full xl:pt-px 2xl:grid 2xl:grid-cols-[30rem_minmax(0,1fr)] 2xl:items-start">
                    <livewire:project.application.deployment.index :embedded="true"
                        :project_uuid="$application->environment->project->uuid"
                        :environment_uuid="$application->environment->uuid"
                        :application_uuid="$application->uuid"
                        :selected-deployment-uuid="$deployment_uuid"
                        wire:key="embedded-deployment-history" />

                    <div wire:key="deployment-logs-{{ $deployment_uuid }}" wire:loading.class="opacity-60" wire:target="selectDeployment" x-data="{
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
        findShortcutLabel: /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘F' : 'Ctrl+F',
        handleFindShortcut(event) {
            const isFind = (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key?.toLowerCase() === 'f';
            if (!isFind) return;
            const active = document.activeElement;
            if (active === this.$refs.search) return;
            if (!this.$root.contains(active) && (active?.isContentEditable || active?.matches('input, textarea, select'))) return;
            event.preventDefault();
            this.$refs.search.focus();
            this.$refs.search.select();
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
        async exportLogs() {
            try {
                return await this.$wire.exportLogs(this.showTimestamps, this.searchQuery.trim());
            } catch {
                Livewire.dispatch('error', ['Failed to prepare logs for export.']);
                return '';
            }
        },
        async copyLogs() {
            const content = await this.exportLogs();
            if (!content) return;
            if (!navigator.clipboard?.writeText) {
                Livewire.dispatch('error', ['Clipboard is not available. Please use HTTPS or localhost.']);
                return;
            }
            navigator.clipboard.writeText(content).then(() => {
                Livewire.dispatch('success', ['Logs copied to clipboard.']);
            }).catch(() => {
                Livewire.dispatch('error', ['Failed to copy logs to clipboard.']);
            });
        },
        async downloadLogs() {
            const content = await this.exportLogs();
            if (!content) return;
            const blob = new Blob([content + String.fromCharCode(10)], { type: 'text/plain' });
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
    }" @keydown.window="handleFindShortcut($event)" class="flex h-[calc(100dvh-8rem)] min-h-[32rem] w-full min-w-0 flex-col overflow-hidden xl:h-[32rem] xl:min-h-0 xl:flex-none 2xl:h-[calc(100dvh-10rem)] 2xl:min-h-[32rem]"
        {{-- A sticky parent is a stacking context: drop it so the fullscreen logs cover the sidebar and topbar. --}}
        :class="fullscreen ? '' : '2xl:sticky 2xl:top-4'">
            <div id="screen" :class="fullscreen ? 'fullscreen flex flex-col' : 'mt-2 flex flex-1 min-h-0 flex-col overflow-hidden lg:mt-0'">
                <div @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif
                    class="logs-viewer flex min-h-0 w-full flex-col overflow-hidden bg-white text-neutral-800 dark:bg-log dark:text-neutral-100"
                    :class="fullscreen ? 'h-full' : 'flex-1 rounded-xl border shadow-sm'">
                    <div class="runtime-log-toolbar logs-viewer-toolbar">
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
                                @if ($isKeepAliveOn)
                                <button title="Follow Logs" :class="alwaysScroll ? 'logs-viewer-btn-active' : ''"
                                    x-on:click="toggleScroll"
                                    class="logs-viewer-btn">
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path fill="none" stroke="currentColor" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
                                    </svg>
                                </button>
                                @endif
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
                                </div>
                                <span class="flex shrink-0 items-center gap-1.5 text-xs font-medium text-neutral-600 dark:text-fg-dim">
                                    <span @class([
                                        'size-2 shrink-0 rounded-full',
                                        'bg-emerald-500' => $deploymentStatusType === 'success',
                                        'bg-warning motion-safe:animate-pulse' => $deploymentStatusType === 'warning',
                                        'bg-red-500' => $deploymentStatusType === 'error',
                                        'bg-neutral-400 dark:bg-neutral-500' => $deploymentStatusType === 'neutral',
                                    ])></span>
                                    {{ $deploymentStatusLabel }}
                                </span>
                            </div>
                            <div class="logs-viewer-end">
                                <livewire:project.application.deployment-navbar
                                    :application_deployment_queue="$application_deployment_queue" />
                            <div class="logs-viewer-search relative">
                                <x-reicon name="search"
                                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                                <input type="search" x-ref="search" x-model.debounce.300ms="searchQuery" placeholder="Find in logs"
                                    aria-label="Find in logs" :aria-keyshortcuts="findShortcutLabel === '⌘F' ? 'Meta+F' : 'Control+F'"
                                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint" />
                                <kbd x-cloak x-show="!searchQuery" x-text="findShortcutLabel" aria-hidden="true"
                                    class="pointer-events-none absolute top-1/2 right-2 z-10 -translate-y-1/2 rounded border border-neutral-200 px-1 font-sans text-[10px] leading-4 text-neutral-400 dark:border-white/[0.08] dark:text-fg-faint"></kbd>
                                <button x-cloak x-show="searchQuery" x-on:click="searchQuery = ''" type="button"
                                    class="absolute top-1/2 right-2 z-10 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                                    aria-label="Clear search">
                                    <x-reicon name="x" class="size-3" />
                                </button>
                            </div>
                            <div class="runtime-log-menus">
                            <x-table.dropdown panel-class="runtime-log-menu min-w-48! max-h-none!" role="menu">
                                <x-slot:trigger><button type="button" title="View Options"
                                    class="button" aria-haspopup="menu" :aria-expanded="open">
                                    <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                                    </svg>
                                    <span>View</span>
                                    <x-reicon name="chevron-down" class="size-3 transition-transform" ::class="open ? 'rotate-180' : ''" />
                                </button></x-slot:trigger>
                                <div>
                                    <p class="runtime-log-menu-label">Display</p>
                                    <button type="button" class="listbox-option" role="menuitemcheckbox"
                                        :aria-checked="showTimestamps" x-on:click="showTimestamps = !showTimestamps">
                                        <span class="flex-1 text-left">Timestamps</span>
                                        <span x-show="showTimestamps">✓</span>
                                    </button>
                                    @can('update', $application)
                                        <button type="button" class="listbox-option" role="menuitemcheckbox"
                                            aria-checked="{{ $is_debug_enabled ? 'true' : 'false' }}" wire:click="toggleDebug">
                                            <span class="flex-1 text-left">Debug logs</span>
                                            <x-loading compact wire:loading.flex wire:target="toggleDebug" class="hidden" aria-label="Loading debug logs..." />
                                            @if ($is_debug_enabled)
                                                <span wire:loading.remove wire:target="toggleDebug">✓</span>
                                            @endif
                                        </button>
                                    @endcan
                                </div>
                            </x-table.dropdown>
                            <x-table.dropdown panel-class="runtime-log-menu min-w-52!" role="menu">
                                <x-slot:trigger><button type="button" title="Copy or Download Logs"
                                    class="button" aria-haspopup="menu" :aria-expanded="open">
                                    <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                    <span>Export</span>
                                    <x-reicon name="chevron-down" class="size-3 transition-transform" ::class="open ? 'rotate-180' : ''" />
                                </button></x-slot:trigger>
                                <div x-data="{ downloadingAllLogs: false }">
                                    <p class="px-2 py-1.5 text-xs text-neutral-500 dark:text-fg-dim">Copied and downloaded logs hide secrets.</p>
                                    <button type="button" role="menuitem" class="listbox-option"
                                        x-on:click="copyLogs(); open = false">
                                        <span class="flex-1 text-left">Copy visible logs</span>
                                    </button>
                                    <button type="button" role="menuitem" class="listbox-option"
                                        x-on:click="downloadLogs(); open = false">
                                        <span class="flex-1 text-left">Download visible logs</span>
                                    </button>
                                    @can('update', $application)
                                        <button type="button" role="menuitem" x-on:click="
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
                                                open = false;
                                            });
                                        "
                                            :disabled="downloadingAllLogs"
                                            :class="{ 'opacity-50 cursor-not-allowed': downloadingAllLogs }"
                                            class="listbox-option">
                                            <span x-show="!downloadingAllLogs" class="flex-1 text-left">Download full log (with debug)</span>
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
                            </x-table.dropdown>
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
