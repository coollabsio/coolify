<div @class(['flex min-h-0 min-w-0 flex-1 flex-col' => $fill])>
@if (! $canReadLogs)
    <x-callout type="info" title="Hidden (only admins can view)">
        Container logs can contain secrets.
    </x-callout>
@else
<div @class(['w-full min-w-0', 'runtime-log-shell' => $collapsible, 'runtime-logs-fill flex flex-1 flex-col' => $fill])>
    <div id="screen" @class(['flex min-h-0 flex-1 flex-col' => $fill]) x-data="runtimeLogs(@js([
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
            @class(['runtime-logs-stream', 'flex min-h-0 w-full flex-1 flex-col' => $fill, 'mx-auto w-full' => ! $fill])
            :class="fullscreen ? 'fullscreen flex flex-col !overflow-visible' : 'relative'"
            :style="fullscreen ? 'max-height: none !important; height: 100% !important;' : ''">
            <div class="runtime-log-panel"
                :class="fullscreen ? 'h-full w-full' : '{{ $fill ? 'flex min-h-0 flex-1 flex-col' : '' }}'">
                <div class="runtime-log-toolbar logs-viewer-toolbar">
                    <div class="logs-viewer-toolbar-controls">
                        <div class="logs-viewer-actions">
                            <button wire:click="toggleStreamLogs"
                                title="{{ $streamLogs ? 'Stop Streaming' : 'Stream Logs' }}"
                                class="runtime-log-icon-button {{ $streamLogs ? 'runtime-log-icon-button-active' : '' }}">
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
                            <button x-on:click="refresh()" title="Refresh Logs" {{ $streamLogs ? 'disabled' : '' }}
                                class="runtime-log-icon-button">
                                <x-reicon name="refresh" class="size-3.5" />
                            </button>
                            <button title="Follow Logs" :class="alwaysScroll ? 'runtime-log-icon-button-active' : ''"
                                x-on:click="toggleScroll"
                                class="runtime-log-icon-button">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                        stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
                                </svg>
                            </button>
                            <button title="Fullscreen" x-show="!fullscreen" x-on:click="makeFullscreen"
                                class="runtime-log-icon-button">
                                <svg class="size-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <g fill="none">
                                        <path fill="currentColor"
                                            d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                                    </g>
                                </svg>
                            </button>
                            <button title="Minimize" x-show="fullscreen" x-on:click="makeFullscreen"
                                class="runtime-log-icon-button">
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
                            <div class="runtime-log-menus">
                            {{-- Display settings and level filters --}}
                            <x-table.dropdown panel-class="runtime-log-menu min-w-48! max-h-none!" role="menu">
                                <x-slot:trigger><button type="button" title="View Options"
                                    :class="Object.values(logFilters).some(v => !v) ? 'button-highlighted' : ''"
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
                                        :aria-checked="$wire.showTimeStamps" wire:click="toggleTimestamps">
                                        <span class="flex-1 text-left">Timestamps</span>
                                        <span x-show="$wire.showTimeStamps">✓</span>
                                    </button>
                                    <button type="button" class="listbox-option" role="menuitemcheckbox"
                                        :aria-checked="textView" x-on:click="toggleTextView">
                                        <span class="flex-1 text-left">Text view</span>
                                        <span x-show="textView">✓</span>
                                    </button>
                                    <button type="button" class="listbox-option" role="menuitemcheckbox"
                                        :aria-checked="colorLogs" x-on:click="toggleColorLogs">
                                        <span class="flex-1 text-left">Color by level</span>
                                        <span x-show="colorLogs">✓</span>
                                    </button>
                                    <p class="runtime-log-menu-label">Log levels</p>
                                    <button type="button" class="listbox-option" role="menuitemcheckbox"
                                        :aria-checked="logFilters.error" x-on:click="toggleLogFilter('error')">
                                        <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                                        <span class="flex-1 text-left">Error</span>
                                        <span x-show="logFilters.error">✓</span>
                                    </button>
                                    <button type="button" class="listbox-option" role="menuitemcheckbox"
                                        :aria-checked="logFilters.warning" x-on:click="toggleLogFilter('warning')">
                                        <span class="w-2.5 h-2.5 rounded-full bg-yellow-500"></span>
                                        <span class="flex-1 text-left">Warning</span>
                                        <span x-show="logFilters.warning">✓</span>
                                    </button>
                                    <button type="button" class="listbox-option" role="menuitemcheckbox"
                                        :aria-checked="logFilters.debug" x-on:click="toggleLogFilter('debug')">
                                        <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                                        <span class="flex-1 text-left">Debug</span>
                                        <span x-show="logFilters.debug">✓</span>
                                    </button>
                                    <button type="button" class="listbox-option" role="menuitemcheckbox"
                                        :aria-checked="logFilters.info" x-on:click="toggleLogFilter('info')">
                                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                                        <span class="flex-1 text-left">Info</span>
                                        <span x-show="logFilters.info">✓</span>
                                    </button>
                                </div>
                            </x-table.dropdown>
                            {{-- Copy and download --}}
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
                                    <p class="px-2 py-1.5 text-xs text-neutral-500 dark:text-fg-dim">Copied and downloaded logs hide secrets. The live view shows the raw output.</p>
                                    <button type="button" role="menuitem" class="listbox-option"
                                        x-on:click="
                                            open = false;
                                            $wire.copyLogs().then(logs => {
                                                logs = logs.replace(/^([^\t\n]+)\t/gm, '$1 | ');
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
                                        ">
                                        <span class="flex-1 text-left">Copy logs</span>
                                    </button>
                                    <button type="button" role="menuitem" class="listbox-option"
                                        x-on:click="downloadLogs(); open = false">
                                        <span class="flex-1 text-left">Download logs</span>
                                    </button>
                                    <button type="button" role="menuitem" x-on:click="
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
                                            open = false;
                                        });
                                    "
                                        :disabled="downloadingAllLogs"
                                        :class="{ 'opacity-50 cursor-not-allowed': downloadingAllLogs }"
                                        class="listbox-option">
                                        <span x-show="!downloadingAllLogs" class="flex-1 text-left">Download all logs</span>
                                        <span x-show="downloadingAllLogs" class="flex items-center gap-2">
                                            <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            Downloading...
                                        </span>
                                    </button>
                                </div>
                            </x-table.dropdown>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="logsContainer" x-ref="viewport" @scroll="handleScroll" @wheel="handleWheel"
                    @touchstart="handleTouchStart" @touchmove="handleTouchMove" @keydown="handleKeyScroll" tabindex="0"
                    class="runtime-log-viewport logs-viewer-viewport relative flex min-w-0 w-full flex-col overflow-x-hidden overflow-y-auto scrollbar"
                    :class="fullscreen ? 'flex-1' : '{{ $fill ? 'min-h-0 flex-1' : 'max-h-[min(40rem,70dvh)] sm:max-h-[40rem]' }}'">
                    <div class="runtime-log-loading" x-show="loading && lineCount === 0" x-cloak role="status">
                        <x-loading compact aria-label="Loading logs" />
                        <span>Loading logs</span>
                    </div>
                    <div class="runtime-log-empty" x-show="!loading && lineCount === 0" role="status">
                        <div>
                            <p>No logs yet</p>
                            <span>Logs will appear here when the container produces output.</span>
                        </div>
                    </div>
                    {{-- Alpine renders only the visible rows. Livewire must not morph them. --}}
                    <div id="logs" wire:ignore x-cloak x-show="lineCount > 0"
                        :class="{ 'runtime-log-without-time': !$wire.showTimeStamps, 'runtime-log-text-view': textView, 'mt-auto': alwaysScroll }"
                        class="font-logs max-w-full cursor-default text-[11px] leading-relaxed sm:text-xs">
                        <div x-ref="columns" x-show="!textView" class="runtime-log-columns" aria-hidden="true">
                            <span x-show="$wire.showTimeStamps">Time</span>
                            <span>Type</span>
                            <span>Message</span>
                        </div>
                        <div x-ref="list" class="runtime-log-virtual-list" :style="{ height: totalSize + 'px' }">
                            <template x-for="row in virtualRows" :key="row.key">
                                <div class="runtime-log-virtual-row" :data-index="row.index"
                                    :style="{ transform: 'translateY(' + (row.start - scrollMargin) + 'px)' }"
                                    x-init="$nextTick(() => measureRow($el, row.line))">
                                    <div data-log-line :role="textView ? null : 'button'" :tabindex="textView ? null : 0" :data-log-level="row.line.level"
                                        :class="colorLogs ? 'log-' + row.line.level : ''"
                                        :aria-expanded="isLogExpanded(row.line.id)"
                                        x-on:click="toggleLogDetails(row.line.id, $event)"
                                        x-on:keydown="toggleLogDetails(row.line.id, $event)"
                                        class="log-line logs-viewer-line">
                                        <template x-if="$wire.showTimeStamps && row.line.ts">
                                            <span class="logs-viewer-timestamp text-gray-500" x-text="formatTimestamp(row.line.ts)"></span>
                                        </template>
                                        <span data-line-text class="logs-viewer-line-text"><template x-if="multipleSources && row.line.source"><span class="runtime-log-source" :style="{ color: row.line.sourceColor }" x-text="row.line.source"></span></template><span class="runtime-log-message"><template x-for="(segment, segmentIndex) in highlight(row.line.text)" :key="segmentIndex"><span :class="segment.match ? 'log-highlight' : ''" x-text="segment.text"></span></template></span></span>
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
@endif
</div>
