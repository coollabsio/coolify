<div @class(['w-full min-w-0', 'runtime-log-shell' => $collapsible])>
    <div id="screen" x-data="runtimeLogs(@js([
        'collapsible' => $collapsible,
        'expanded' => $expandByDefault || ! $collapsible,
        'containerName' => $container ?? 'logs',
        'timezone' => getServerTimezone($server),
    ]))" @keydown.window="handleKeyDown($event)"
        :data-runtime-logs="fullscreen ? 'fullscreen' : (expanded ? 'expanded' : 'collapsed')">
        @if ($collapsible)
            <div class="runtime-log-trigger"
                x-on:click="expanded = !expanded; if (expanded && !logsLoaded) { refresh(); logsLoaded = true; }">
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
                    <x-loading />
                @endif
            </div>
        @endif
        <div x-show="expanded" {{ $collapsible ? 'x-collapse.duration.200ms' : '' }}
            :class="fullscreen ? 'fullscreen flex flex-col !overflow-visible' : 'relative w-full mx-auto'"
            :style="fullscreen ? 'max-height: none !important; height: 100% !important;' : ''">
            <div class="runtime-log-panel"
                :class="fullscreen ? 'h-full w-full' : ''">
                <div class="runtime-log-toolbar logs-viewer-toolbar">
                    <div class="logs-viewer-toolbar-controls">
                        <div class="logs-viewer-actions">
                            <button x-on:click="refresh()" title="Refresh Logs" {{ $streamLogs ? 'disabled' : '' }}
                                class="runtime-log-icon-button order-8">
                                <x-reicon name="refresh" class="size-3.5" />
                            </button>
                            <button wire:click="toggleStreamLogs"
                                title="{{ $streamLogs ? 'Stop Streaming' : 'Stream Logs' }}"
                                class="runtime-log-icon-button order-9 {{ $streamLogs ? 'runtime-log-icon-button-active' : '' }}">
                                @if ($streamLogs)
                                    {{-- Pause icon --}}
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                        fill="currentColor">
                                        <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" />
                                    </svg>
                                @else
                                    {{-- Play icon --}}
                                    <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                        fill="currentColor">
                                        <path d="M8 5v14l11-7L8 5z" />
                                    </svg>
                                @endif
                            </button>
                            <button
                                x-on:click="
                                    $wire.copyLogs().then(logs => {
                                        if (!navigator.clipboard?.writeText) {
                                            Livewire.dispatch('error', ['Clipboard is not available. Please use HTTPS or localhost.']);
                                            return;
                                        }
                                        navigator.clipboard.writeText(logs).then(() => {
                                            Livewire.dispatch('success', ['Logs copied to clipboard.']);
                                        }).catch(() => {
                                            Livewire.dispatch('error', ['Failed to copy logs to clipboard.']);
                                        });
                                    }).catch(() => {
                                        Livewire.dispatch('error', ['Failed to prepare logs for clipboard.']);
                                    });
                                "
                                title="Copy Logs"
                                class="runtime-log-icon-button order-6">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                                </svg>
                            </button>
                            <div x-data="{ downloadMenuOpen: false, downloadingAllLogs: false }" class="relative order-7 shrink-0">
                                <button x-on:click="downloadMenuOpen = !downloadMenuOpen" title="Download Logs"
                                    class="runtime-log-icon-button">
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
                                    class="runtime-log-menu listbox-panel left-auto! right-0! z-[90]! min-w-52!">
                                    <div>
                                        <button x-on:click="downloadLogs(); downloadMenuOpen = false"
                                            class="listbox-option">
                                            Download displayed logs
                                        </button>
                                        <button x-on:click="
                                            downloadingAllLogs = true;
                                            $wire.downloadAllLogs().then(logs => {
                                                if (!logs) return;
                                                const blob = new Blob([logs], { type: 'text/plain' });
                                                const url = URL.createObjectURL(blob);
                                                const a = document.createElement('a');
                                                a.href = url;
                                                const timestamp = new Date().toISOString().slice(0,19).replace(/[T:]/g, '-');
                                                a.download = containerName + '-all-logs-' + timestamp + '.txt';
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
                                            class="listbox-option">
                                            <span x-show="!downloadingAllLogs">Download all logs</span>
                                            <span x-show="downloadingAllLogs" class="flex items-center gap-2">
                                                <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Downloading...
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button wire:click="toggleTimestamps" title="Toggle Timestamps"
                                class="runtime-log-icon-button order-1 {{ $showTimeStamps ? 'runtime-log-icon-button-active' : '' }}">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </button>
                            <button title="Toggle Log Colors" x-on:click="toggleColorLogs"
                                :class="colorLogs ? 'runtime-log-icon-button-active' : ''"
                                class="runtime-log-icon-button order-3">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" />
                                </svg>
                            </button>
                            <x-table.dropdown panel-class="runtime-log-menu min-w-40!">
                                <x-slot:trigger><button type="button" title="Filter Log Levels"
                                    :class="Object.values(logFilters).some(v => !v) ? 'runtime-log-icon-button-active' : ''"
                                    class="runtime-log-icon-button" aria-haspopup="listbox" :aria-expanded="open">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                                    </svg>
                                </button></x-slot:trigger>
                                    <div>
                                        <button type="button" class="listbox-option" x-on:click="toggleLogFilter('error')">
                                            <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                                            <span class="flex-1 text-left">Error</span>
                                            <span x-show="logFilters.error">✓</span>
                                        </button>
                                        <button type="button" class="listbox-option" x-on:click="toggleLogFilter('warning')">
                                            <span class="w-2.5 h-2.5 rounded-full bg-yellow-500"></span>
                                            <span class="flex-1 text-left">Warning</span>
                                            <span x-show="logFilters.warning">✓</span>
                                        </button>
                                        <button type="button" class="listbox-option" x-on:click="toggleLogFilter('debug')">
                                            <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                                            <span class="flex-1 text-left">Debug</span>
                                            <span x-show="logFilters.debug">✓</span>
                                        </button>
                                        <button type="button" class="listbox-option" x-on:click="toggleLogFilter('info')">
                                            <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                                            <span class="flex-1 text-left">Info</span>
                                            <span x-show="logFilters.info">✓</span>
                                        </button>
                                    </div>
                            </x-table.dropdown>
                            <button title="Follow Logs" :class="alwaysScroll ? 'runtime-log-icon-button-active' : ''"
                                x-on:click="toggleScroll"
                                class="runtime-log-icon-button order-2">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                        stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
                                </svg>
                            </button>
                            <button title="Fullscreen" x-show="!fullscreen" x-on:click="makeFullscreen"
                                class="runtime-log-icon-button order-5">
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
                                class="runtime-log-icon-button order-5">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                        stroke-width="2" d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                                </svg>
                            </button>
                        </div>
                        <div class="logs-viewer-end runtime-logs-viewer-end">
                            <div class="logs-viewer-meta">
                                <form x-on:submit.prevent="refreshLines()" class="logs-viewer-lines runtime-log-lines-group">
                                    <span class="logs-viewer-lines-label relative" :aria-busy="loadingLines">
                                        <span :class="loadingLines ? 'invisible' : ''">Lines</span>
                                        <x-loading compact x-cloak x-show="loadingLines" aria-label="Loading logs"
                                            class="absolute inset-0 text-inherit!" />
                                    </span>
                                    <input type="number" wire:model="numberOfLines" x-on:input="allLines = Number($event.target.value) === -1" placeholder="100" min="-1" max="50000"
                                        title="Number of lines (max 50,000; use -1 for all)" {{ $streamLogs ? 'readonly' : '' }}
                                        class="input logs-viewer-lines-input" />
                                    <button type="button" x-on:click="showAllLogs()" title="Show all logs"
                                        :class="allLines ? 'runtime-log-lines-all-active' : ''"
                                        :aria-pressed="allLines" :aria-busy="loadingAll"
                                        class="runtime-log-lines-all relative" {{ $streamLogs ? 'disabled' : '' }}>
                                        <span :class="loadingAll ? 'invisible' : ''">All</span>
                                        <x-loading compact x-cloak x-show="loadingAll" aria-label="Loading all logs"
                                            class="absolute inset-0 text-inherit!" />
                                    </button>
                                </form>
                            </div>
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
                        </div>
                    </div>
                </div>
                <div id="logsContainer" x-ref="viewport" @scroll="handleScroll" @wheel="handleWheel"
                    @touchstart="handleTouchStart" @touchmove="handleTouchMove" @keydown="handleKeyScroll" tabindex="0"
                    class="runtime-log-viewport logs-viewer-viewport relative flex min-w-0 w-full flex-col overflow-x-hidden overflow-y-auto scrollbar"
                    :class="fullscreen ? 'flex-1' : 'max-h-[min(40rem,70dvh)] sm:max-h-[40rem]'">
                    <div class="runtime-log-loading" x-show="loading && lineCount === 0" role="status">
                        <x-loading compact aria-label="Loading logs" />
                        <span>Loading logs</span>
                    </div>
                    <div class="runtime-log-empty" x-show="!loading && lineCount === 0" role="status">
                        <span class="runtime-log-empty-icon" aria-hidden="true">
                            <x-reicon name="terminal" class="size-4" />
                        </span>
                        <div>
                            <p>No logs yet</p>
                            <span>Logs will appear here when the container produces output.</span>
                        </div>
                    </div>
                    {{-- Alpine renders only the visible rows. Livewire must not morph them. --}}
                    <div id="logs" wire:ignore x-cloak x-show="lineCount > 0"
                        :class="{ 'runtime-log-without-time': !$wire.showTimeStamps }"
                        class="font-logs max-w-full cursor-default text-[11px] leading-relaxed sm:text-xs">
                        <div x-ref="columns" class="runtime-log-columns" aria-hidden="true">
                            <span x-show="$wire.showTimeStamps">Time</span>
                            <span>Type</span>
                            <span>Message</span>
                        </div>
                        <div x-ref="list" class="runtime-log-virtual-list" :style="{ height: totalSize + 'px' }">
                            <template x-for="row in virtualRows" :key="row.key">
                                <div class="runtime-log-virtual-row" :data-index="row.index"
                                    :style="{ transform: 'translateY(' + (row.start - scrollMargin) + 'px)' }"
                                    x-init="$nextTick(() => measureRow($el))">
                                    <div data-log-line role="button" tabindex="0" :data-log-level="row.line.level"
                                        :class="colorLogs ? 'log-' + row.line.level : ''"
                                        :aria-expanded="isLogExpanded(row.line.id)"
                                        x-on:click="toggleLogDetails(row.line.id, $event)"
                                        x-on:keydown="toggleLogDetails(row.line.id, $event)"
                                        class="log-line logs-viewer-line">
                                        <template x-if="$wire.showTimeStamps && row.line.ts">
                                            <span class="logs-viewer-timestamp text-gray-500" x-text="formatTimestamp(row.line.ts)"></span>
                                        </template>
                                        <span data-line-text class="logs-viewer-line-text"><template x-for="(segment, segmentIndex) in highlight(row.line.text)" :key="segmentIndex"><span :class="segment.match ? 'log-highlight' : ''" x-text="segment.text"></span></template></span>
                                    </div>
                                    <template x-if="isLogExpanded(row.line.id)">
                                        <pre class="runtime-log-detail" aria-label="Full log entry"
                                            x-text="formatLogDetails(row.line.text)"></pre>
                                    </template>
                                </div>
                            </template>
                        </div>
                        <div x-show="appliedQuery && matchCount === 0" class="px-4 py-2 text-gray-500 dark:text-gray-400">
                            No matches found.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
