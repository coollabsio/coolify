@include('livewire.project.cluster-application.partials.deployment-history', ['selectedDeploymentUuid' => $selectedDeployment['uuid']])

<div x-data="{
    fullscreen: false,
    showTimestamps: true,
    searchQuery: '',
    matchCount: 0,
    deploymentId: @js($selectedDeployment['uuid']),
    applySearch() {
        const query = this.searchQuery.trim().toLowerCase();
        let count = 0;
        this.$root.querySelectorAll('[data-log-line]').forEach(line => {
            const matches = !query || (line.dataset.logContent || '').toLowerCase().includes(query);
            line.classList.toggle('hidden', !matches);
            if (matches && query) count++;
        });
        this.matchCount = query ? count : 0;
    },
    visibleLogs() {
        return Array.from(this.$root.querySelectorAll('[data-log-line]:not(.hidden)'))
            .map(line => line.textContent.replace(/\s+/g, ' ').trim())
            .filter(Boolean)
            .join(String.fromCharCode(10));
    },
    copyLogs() {
        const content = this.visibleLogs();
        if (!content) return;
        if (!navigator.clipboard?.writeText) {
            Livewire.dispatch('error', ['Clipboard is not available. Please use HTTPS or localhost.']);
            return;
        }
        navigator.clipboard.writeText(content)
            .then(() => Livewire.dispatch('success', ['Logs copied to clipboard.']))
            .catch(() => Livewire.dispatch('error', ['Failed to copy logs to clipboard.']));
    },
    downloadLogs() {
        const content = this.visibleLogs();
        if (!content) return;
        const url = URL.createObjectURL(new Blob([content], { type: 'text/plain' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'deployment-' + this.deploymentId + '.txt';
        link.click();
        URL.revokeObjectURL(url);
    },
    commitCleanup: null,
    init() {
        this.$watch('searchQuery', () => this.applySearch());
        this.commitCleanup = Livewire.hook('commit', ({ succeed }) => succeed(() => this.$nextTick(() => this.applySearch())));
    },
    destroy() {
        if (typeof this.commitCleanup === 'function') this.commitCleanup();
    }
}" class="flex h-[calc(100dvh-8rem)] min-h-[32rem] w-full flex-col overflow-hidden xl:h-[32rem] xl:min-h-0 xl:flex-none">
    <div :class="fullscreen ? 'fullscreen flex flex-col' : 'flex flex-1 min-h-0 flex-col overflow-hidden'">
        <div class="logs-viewer flex min-h-0 w-full flex-col overflow-hidden bg-white text-neutral-800 dark:bg-log dark:text-neutral-100"
            :class="fullscreen ? 'h-full' : 'flex-1 rounded-xl border border-neutral-200 shadow-sm dark:border-coolgray-200'">
            <div class="logs-viewer-toolbar">
                <div class="logs-viewer-toolbar-controls">
                    <div class="logs-viewer-primary">
                        <div class="logs-viewer-actions">
                            <button type="button" title="Toggle Timestamps" x-on:click="showTimestamps = !showTimestamps"
                                :class="showTimestamps ? 'logs-viewer-btn-active' : ''" class="logs-viewer-btn">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </button>
                            <button type="button" title="Fullscreen" x-show="!fullscreen" x-on:click="fullscreen = true"
                                class="logs-viewer-btn">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M4 20l6-6m-6 6v-5m0 5h5M20 4l-6 6m6-6v5m0-5h-5" />
                                </svg>
                            </button>
                            <button type="button" title="Minimize" x-show="fullscreen" x-on:click="fullscreen = false"
                                class="logs-viewer-btn">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                        stroke-width="2" d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                                </svg>
                            </button>
                            <button type="button" title="Copy Logs" x-on:click="copyLogs()" class="logs-viewer-btn">
                                <x-reicon name="copy" class="size-4" />
                            </button>
                            <button type="button" title="Download Logs" x-on:click="downloadLogs()" class="logs-viewer-btn">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                            </button>
                        </div>
                        <x-status-badge :status="$selectedDeployment['status']" :type="$selectedDeployment['statusType']"
                            class="logs-viewer-status-badge" />
                    </div>
                    <div class="logs-viewer-end">
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
                                class="whitespace-nowrap text-xs text-neutral-500"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="logs-viewer-viewport scrollbar flex min-h-40 flex-1 flex-col overflow-x-hidden overflow-y-auto">
                <div id="logs" class="flex min-w-0 flex-col font-logs text-[11px] leading-relaxed sm:text-xs">
                    <div x-show="searchQuery.trim() && matchCount === 0" class="py-2 text-neutral-500">
                        No matches found.
                    </div>
                    @forelse ($selectedDeployment['lines'] as $line)
                        <div wire:key="deployment-log-{{ $loop->index }}"
                            data-log-line data-log-content="{{ $line['timestamp'].' '.$line['line'] }}"
                            class="log-line logs-viewer-line">
                            <span x-show="showTimestamps" class="logs-viewer-timestamp">{{ $line['timestamp'] }}</span>
                            <span @class(['logs-viewer-line-text', 'text-red-500' => $line['stderr']])>{{ $line['line'] }}</span>
                        </div>
                    @empty
                        <span class="mb-2 font-logs text-neutral-400">No logs yet.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
