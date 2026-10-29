import {
    Virtualizer,
    elementScroll,
    measureElement,
    observeElementOffset,
    observeElementRect,
} from '@tanstack/virtual-core';

// Alpine data provider for the runtime log viewer (x-data="runtimeLogs(...)").
// Log lines stay in plain arrays outside Alpine's reactive state. Only the rows
// in the viewport are rendered, so large logs do not freeze the browser tab.

export const MAX_CLIENT_LOG_LINES = 50000;
export const STREAM_INTERVAL_MS = 2000;
const ESTIMATED_ROW_HEIGHT = 30;
const ESTIMATED_TEXT_ROW_HEIGHT = 20;

const TIMESTAMP_PATTERN = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z) (.*)$/s;
const SOURCE_PREFIX = /^([^\t]+)\t(.*)$/s;
const SOURCE_COLORS = ['#6f6c78', '#6d7ea8', '#6e8b74', '#a98462', '#8d7398', '#6f8f95'];

function sourceColor(label) {
    let hash = 0;
    for (let i = 0; i < label.length; i++) {
        hash = (hash * 31 + label.charCodeAt(i)) >>> 0;
    }
    return SOURCE_COLORS[hash % SOURCE_COLORS.length];
}

export function formatLogDetails(content) {
    const trimmed = String(content ?? '').trim();
    if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) return content;
    try {
        return JSON.stringify(JSON.parse(trimmed), null, 2);
    } catch {
        return content;
    }
}

/**
 * A row can expand only when the detail shows more than the row: the row cut
 * the text off, or the text is JSON that the detail formats.
 */
export function isExpandableLog(content, truncated) {
    return truncated || formatLogDetails(content) !== content;
}

export function getLogLevel(content) {
    if (/\b(error|err|failed|failure|exception|fatal|panic|critical)\b/.test(content)) return 'error';
    if (/\b(warn|warning|wrn|caution)\b/.test(content)) return 'warning';
    if (/\b(debug|dbg|trace|verbose)\b/.test(content)) return 'debug';
    return 'info';
}

/**
 * Split raw `docker logs -t` output into frozen line records.
 * Frozen records are not wrapped by Alpine's reactive proxies.
 */
export function parseLogLines(output, nextId) {
    const lines = [];
    for (const rawLine of String(output ?? '').split('\n')) {
        if (rawLine.trim() === '') continue;
        const sourced = rawLine.match(SOURCE_PREFIX);
        const source = sourced ? sourced[1] : '';
        const body = sourced ? sourced[2] : rawLine;
        const match = body.match(TIMESTAMP_PATTERN);
        const ts = match ? match[1] : '';
        const text = match ? match[2] : body;
        const lower = `${source}\n${text}`.toLowerCase();
        lines.push(Object.freeze({
            id: nextId(),
            ts,
            text,
            source,
            sourceColor: source ? sourceColor(source) : '',
            lower,
            level: getLogLevel(text.toLowerCase()),
        }));
    }
    return lines;
}

/**
 * Remove lines that a `--since` request returns again. Docker includes lines
 * with the same timestamp as the `--since` value, so compare that boundary.
 */
function linesSinceBoundary(existing, incoming) {
    let lastTs = '';
    for (let i = existing.length - 1; i >= 0; i--) {
        if (existing[i].ts) {
            lastTs = existing[i].ts;
            break;
        }
    }
    if (!lastTs) return incoming;

    const seenAtBoundary = new Map();
    for (let i = existing.length - 1; i >= 0 && existing[i].ts === lastTs; i--) {
        const key = existing[i].text;
        seenAtBoundary.set(key, (seenAtBoundary.get(key) ?? 0) + 1);
    }

    return incoming.filter((line) => {
        if (!line.ts) return true;
        if (line.ts < lastTs) return false;
        if (line.ts > lastTs) return true;
        const seen = seenAtBoundary.get(line.text) ?? 0;
        if (seen === 0) return true;
        seenAtBoundary.set(line.text, seen - 1);
        return false;
    });
}

function groupLinesBySource(entries) {
    const groups = new Map();
    for (const line of entries) {
        const key = line.source || '';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(line);
    }
    return groups;
}

export function newLinesSince(existing, incoming) {
    const sourced = existing.some((line) => line.source) || incoming.some((line) => line.source);
    if (!sourced) return linesSinceBoundary(existing, incoming);

    const existingBySource = groupLinesBySource(existing);
    const fresh = [];
    for (const [source, group] of groupLinesBySource(incoming)) {
        fresh.push(...linesSinceBoundary(existingBySource.get(source) ?? [], group));
    }
    fresh.sort((left, right) => {
        if (left.ts === right.ts) return (left.source || '').localeCompare(right.source || '');
        if (!left.ts) return 1;
        if (!right.ts) return -1;
        return left.ts < right.ts ? -1 : 1;
    });
    return fresh;
}

export function lastTimestamp(lines) {
    for (let i = lines.length - 1; i >= 0; i--) {
        if (lines[i].ts) return lines[i].ts;
    }
    return null;
}

/** Ctrl+F or Cmd+F. The viewer takes it, because browser find cannot see rows that are not rendered. */
export function isFindShortcut(event) {
    return Boolean(event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key?.toLowerCase() === 'f';
}

const DEFAULT_LOG_FILTERS = { error: true, warning: true, debug: true, info: true };

/** Read saved level filters. Invalid saved values fall back to the defaults. */
export function readLogFilters(saved) {
    const filters = { ...DEFAULT_LOG_FILTERS };
    let parsed;
    try {
        parsed = JSON.parse(saved);
    } catch {
        return filters;
    }
    for (const level of Object.keys(filters)) {
        if (typeof parsed?.[level] === 'boolean') filters[level] = parsed[level];
    }
    return filters;
}

export function filterLogLines(lines, filters, query) {
    const needle = query.trim().toLowerCase();
    return lines.filter((line) => filters[line.level] !== false && (!needle || line.lower.includes(needle)));
}

/** Split text into plain and matching parts. The view renders each part with x-text. */
export function highlightSegments(text, query) {
    const needle = query.trim().toLowerCase();
    if (!needle) return [{ text, match: false }];

    const segments = [];
    const lowerText = text.toLowerCase();
    let lastIndex = 0;
    let index = lowerText.indexOf(needle);
    while (index !== -1) {
        if (index > lastIndex) segments.push({ text: text.substring(lastIndex, index), match: false });
        segments.push({ text: text.substring(index, index + needle.length), match: true });
        lastIndex = index + needle.length;
        index = lowerText.indexOf(needle, lastIndex);
    }
    if (lastIndex < text.length) segments.push({ text: text.substring(lastIndex), match: false });
    return segments;
}

/** Format a Docker UTC timestamp like the server-side log viewer: 2025-Dec-04 11:48:39. */
export function createTimestampFormatter(timeZone) {
    let formatter;
    try {
        formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: timeZone || 'UTC',
            year: 'numeric', month: 'short', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        });
    } catch {
        formatter = createTimestampFormatter('UTC').formatter;
    }

    const format = (ts) => {
        const date = new Date(ts.slice(0, 19) + 'Z');
        if (Number.isNaN(date.getTime())) return '';
        const parts = Object.fromEntries(formatter.formatToParts(date).map((part) => [part.type, part.value]));
        return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
    };
    format.formatter = formatter;
    return format;
}

function downloadText(content, filename) {
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

export function prefersTextView(stored) {
    return stored !== 'false';
}

export function initializeRuntimeLogsComponent() {
    window.Alpine.data('runtimeLogs', (config = {}) => {
        let lines = [];
        let filtered = [];
        let lineId = 0;
        let virtualizer = null;
        let cleanupVirtualizer = null;
        let layoutObserver = null;
        let streamTimer = null;
        const nextId = () => ++lineId;
        const formatTimestamp = createTimestampFormatter(config.timezone);

        return {
            collapsible: config.collapsible ?? true,
            expanded: config.expanded ?? false,
            containerName: config.containerName ?? 'logs',
            findShortcutLabel: /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘F' : 'Ctrl+F',
            logsLoaded: false,
            allLines: false,
            loadingAll: false,
            loadingLines: false,
            loading: false,
            fullscreen: false,
            alwaysScroll: false,
            followManuallyDisabled: false,
            isScrolling: false,
            lastTouchY: 0,
            scrollDebounce: null,
            destroyed: false,
            colorLogs: localStorage.getItem('coolify-color-logs') === 'true',
            textView: prefersTextView(localStorage.getItem('coolify-log-text-view')),
            logFilters: readLogFilters(localStorage.getItem('coolify-log-filters')),
            searchQuery: '',
            appliedQuery: '',
            matchCount: 0,
            lineCount: 0,
            expandedLogs: {},
            virtualRows: [],
            totalSize: 0,
            scrollMargin: 0,
            multipleSources: false,

            init() {
                this.allLines = Number(this.$wire.numberOfLines) === -1;
                this.$watch('searchQuery', () => this.applyFilters());
                this.$watch('loading', () => this.$nextTick(() => this.updateVirtualizer()));
                this.$nextTick(() => this.mountVirtualizer());
                streamTimer = setInterval(() => this.streamTick(), STREAM_INTERVAL_MS);
                if (this.expanded) {
                    this.refresh();
                    this.logsLoaded = true;
                }
            },

            destroy() {
                this.destroyed = true;
                clearInterval(streamTimer);
                clearTimeout(this.scrollDebounce);
                layoutObserver?.disconnect();
                cleanupVirtualizer?.();
                virtualizer = null;
            },

            mountVirtualizer() {
                if (this.destroyed || virtualizer) return;
                virtualizer = new Virtualizer({
                    count: 0,
                    getScrollElement: () => this.$refs.viewport ?? null,
                    estimateSize: () => (this.textView ? ESTIMATED_TEXT_ROW_HEIGHT : ESTIMATED_ROW_HEIGHT),
                    getItemKey: (index) => filtered[index]?.id ?? index,
                    overscan: 12,
                    // Follow mode keeps the newest line in view. Otherwise the first line stays at the top.
                    anchorTo: 'start',
                    observeElementRect,
                    observeElementOffset,
                    scrollToFn: elementScroll,
                    // A row can be measured before its text is laid out. Caching that 0
                    // collapses the top of the list and the first lines never come back.
                    measureElement: (element, entry, instance) => {
                        const size = measureElement(element, entry, instance);
                        if (size > 0) return size;
                        const index = instance.indexFromElement(element);
                        const key = instance.options.getItemKey(index);
                        return instance.itemSizeCache.get(key) ?? instance.options.estimateSize(index);
                    },
                    onChange: () => {
                        // Measurement corrections can advance the internal offset while the
                        // element is still at the top, which hides the first lines.
                        this.pinScrollOffset();
                        this.syncRows();
                    },
                });
                cleanupVirtualizer = virtualizer._didMount();
                virtualizer._willUpdate();
                if (window.ResizeObserver) {
                    layoutObserver = new ResizeObserver(() => {
                        const margin = this.measureScrollMargin();
                        if (margin !== this.scrollMargin) this.updateVirtualizer();
                    });
                    if (this.$refs.list) layoutObserver.observe(this.$refs.list);
                    if (this.$refs.viewport) layoutObserver.observe(this.$refs.viewport);
                }
                this.updateVirtualizer();
            },

            measureScrollMargin() {
                const list = this.$refs.list;
                const viewport = this.$refs.viewport;
                if (!list || !viewport || this.lineCount === 0) return 0;
                const margin = list.getBoundingClientRect().top - viewport.getBoundingClientRect().top + viewport.scrollTop;
                return Number.isFinite(margin) ? Math.max(0, margin) : 0;
            },

            // Keep the virtual offset on the real scroll position while the reader is at the top.
            pinScrollOffset() {
                const viewport = this.$refs.viewport;
                if (!virtualizer || this.alwaysScroll || !viewport || viewport.scrollTop !== 0) return;
                if (!virtualizer.scrollOffset && !virtualizer.scrollAdjustments) return;
                virtualizer.scrollOffset = 0;
                virtualizer.scrollAdjustments = 0;
            },

            updateVirtualizer() {
                if (!virtualizer) return;
                this.pinScrollOffset();
                this.scrollMargin = this.measureScrollMargin();
                virtualizer.setOptions({
                    ...virtualizer.options,
                    count: filtered.length,
                    scrollMargin: this.scrollMargin,
                    anchorTo: this.alwaysScroll ? 'end' : 'start',
                    // A new function makes the virtualizer recalculate keys after every data change.
                    getItemKey: (index) => filtered[index]?.id ?? index,
                });
                virtualizer._willUpdate();
                this.syncRows();
            },

            syncRows() {
                if (!virtualizer) return;
                this.virtualRows = virtualizer.getVirtualItems()
                    .filter((item) => filtered[item.index])
                    .map((item) => ({ key: item.key, index: item.index, start: item.start, line: filtered[item.index] }));
                this.totalSize = virtualizer.getTotalSize();
            },

            measureRow(el, logLine = null) {
                const line = el.querySelector('[data-log-line]');
                const message = line?.querySelector('.runtime-log-message');
                if (line && message && !this.textView) {
                    const text = logLine?.text ?? message.textContent;
                    line.toggleAttribute('data-expandable', isExpandableLog(text, message.scrollWidth > message.clientWidth));
                }
                virtualizer?.measureElement(el);
            },

            async refresh() {
                if (this.loading) return;
                this.loading = true;
                try {
                    this.replaceLines(await this.$wire.getLogs());
                } finally {
                    this.loading = false;
                }
            },

            async refreshLines() {
                if (this.loading) return;
                this.loadingLines = true;
                try {
                    await this.refresh();
                } finally {
                    this.loadingLines = false;
                }
            },

            async showAllLogs() {
                if (this.loading) return;
                this.loading = true;
                this.loadingAll = true;
                try {
                    this.replaceLines(await this.$wire.showAllLogs());
                    this.allLines = true;
                } finally {
                    this.loading = false;
                    this.loadingAll = false;
                }
            },

            sinceArgument() {
                const bySource = {};
                let last = null;
                let sourced = false;
                for (const line of lines) {
                    if (!line.ts) continue;
                    last = line.ts;
                    if (line.source) {
                        sourced = true;
                        bySource[line.source] = line.ts;
                    }
                }
                if (!sourced) return last;
                return Object.keys(bySource).length > 0 ? bySource : null;
            },

            async streamTick() {
                if (this.destroyed || this.loading || !this.expanded || document.hidden || !this.$wire.streamLogs) return;
                const since = this.sinceArgument();
                if (!since) {
                    await this.refresh();
                    return;
                }
                this.loading = true;
                try {
                    const output = await this.$wire.getLogs(since);
                    if (this.destroyed || !this.$wire.streamLogs) return;
                    this.appendLines(newLinesSince(lines, parseLogLines(output, nextId)));
                } finally {
                    this.loading = false;
                }
            },

            replaceLines(output) {
                if (this.destroyed) return;
                lines = parseLogLines(output, nextId);
                this.expandedLogs = {};
                this.applyFilters();
            },

            appendLines(newLines) {
                if (newLines.length === 0) return;
                lines = lines.concat(newLines);
                if (lines.length > MAX_CLIENT_LOG_LINES) {
                    lines = lines.slice(lines.length - MAX_CLIENT_LOG_LINES);
                }
                this.applyFilters();
            },

            applyFilters() {
                filtered = filterLogLines(lines, this.logFilters, this.searchQuery);
                this.appliedQuery = this.searchQuery.trim();
                this.matchCount = this.appliedQuery ? filtered.length : 0;
                this.lineCount = lines.length;
                const sources = new Set(filtered.map((line) => line.source).filter(Boolean));
                this.multipleSources = sources.size > 1;
                // Wait for x-show to reveal the list so its offset can be measured.
                this.$nextTick(() => {
                    this.updateVirtualizer();
                    if (this.alwaysScroll) this.scrollToBottom();
                });
            },

            visibleText(source = filtered) {
                const withTime = this.$wire.showTimeStamps;
                const showSource = new Set(source.map((line) => line.source).filter(Boolean)).size > 1;
                return source
                    .map((line) => {
                        const body = withTime && line.ts ? `${formatTimestamp(line.ts)} ${line.text}` : line.text;
                        return showSource && line.source ? `${line.source} | ${body}` : body;
                    })
                    .join('\n');
            },

            formatTimestamp(ts) {
                return formatTimestamp(ts);
            },

            highlight(text) {
                return highlightSegments(text, this.appliedQuery);
            },

            formatLogDetails(content) {
                return formatLogDetails(content);
            },

            toggleLogDetails(id, event) {
                if (this.textView) return;
                if (window.getSelection()?.toString()) return;
                if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
                if (event.type === 'keydown') event.preventDefault();
                if (!this.expandedLogs[id] && !event.currentTarget?.hasAttribute('data-expandable')) return;
                this.expandedLogs[id] = !this.expandedLogs[id];
            },

            isLogExpanded(id) {
                return !this.textView && this.expandedLogs[id] === true;
            },

            toggleLogFilter(level) {
                this.logFilters[level] = !this.logFilters[level];
                localStorage.setItem('coolify-log-filters', JSON.stringify(this.logFilters));
                this.applyFilters();
            },

            toggleColorLogs() {
                this.colorLogs = !this.colorLogs;
                localStorage.setItem('coolify-color-logs', this.colorLogs);
            },

            // Plain text lines without columns, row borders, or expandable details.
            toggleTextView() {
                this.textView = !this.textView;
                localStorage.setItem('coolify-log-text-view', this.textView);
                this.$nextTick(() => {
                    virtualizer?.measure();
                    this.$refs.list?.querySelectorAll('.runtime-log-virtual-row').forEach((el) => this.measureRow(el));
                    this.updateVirtualizer();
                    if (this.alwaysScroll) this.scrollToBottom();
                });
            },

            // The browser holds unredacted lines, so fetch the redacted copy from the server
            // and apply the same level and search filters before downloading it.
            async downloadLogs() {
                let logs;
                try {
                    logs = await this.$wire.copyLogs();
                } catch {
                    window.Livewire.dispatch('error', ['Failed to prepare logs for download.']);
                    return;
                }
                if (!logs) return;
                let id = 0;
                const exported = filterLogLines(parseLogLines(logs, () => ++id), this.logFilters, this.searchQuery);
                const timestamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
                downloadText(this.visibleText(exported) + '\n', `${this.containerName}-logs-${timestamp}.txt`);
            },

            makeFullscreen() {
                this.fullscreen = !this.fullscreen;
                if (this.fullscreen === false) {
                    this.alwaysScroll = false;
                }
            },

            handleKeyDown(event) {
                if (event.key === 'Escape' && this.fullscreen) {
                    this.makeFullscreen();
                }
                if (isFindShortcut(event) && this.ownsFindShortcut()) {
                    event.preventDefault();
                    this.$refs.search.focus();
                    this.$refs.search.select();
                }
            },

            // Focus inside this viewer wins. Otherwise, unless the user types in another
            // field or works in another viewer, the fullscreen viewer or else the first
            // open viewer takes it. A second Ctrl+F in the search field falls through
            // to the browser find.
            ownsFindShortcut() {
                if (!this.expanded) return false;
                const active = document.activeElement;
                if (active === this.$refs.search) return false;
                if (this.$root.contains(active)) return true;
                if (active?.closest('[data-runtime-logs]')) return false;
                if (active?.isContentEditable || active?.matches('input, textarea, select')) return false;
                const target = document.querySelector('[data-runtime-logs="fullscreen"]')
                    ?? document.querySelector('[data-runtime-logs="expanded"]');
                return target === this.$root;
            },

            scrollToBottom() {
                if (this.destroyed || !virtualizer || filtered.length === 0) return;
                this.isScrolling = true;
                virtualizer.scrollToIndex(filtered.length - 1, { align: 'end' });
                setTimeout(() => { this.isScrolling = false; }, 50);
            },

            toggleScroll() {
                this.alwaysScroll = !this.alwaysScroll;
                this.followManuallyDisabled = !this.alwaysScroll;
                this.updateVirtualizer();
                if (this.alwaysScroll) this.scrollToBottom();
            },

            disableFollow() {
                this.alwaysScroll = false;
            },

            handleWheel(event) {
                if (this.alwaysScroll && event.deltaY < 0) this.disableFollow();
            },

            handleTouchStart(event) {
                this.lastTouchY = event.touches[0].clientY;
            },

            handleTouchMove(event) {
                if (!this.alwaysScroll) return;
                const currentY = event.touches[0].clientY;
                if (currentY > this.lastTouchY) this.disableFollow();
                this.lastTouchY = currentY;
            },

            handleKeyScroll(event) {
                if (this.alwaysScroll && ['ArrowUp', 'PageUp', 'Home'].includes(event.key)) this.disableFollow();
            },

            handleScroll(event) {
                if (this.isScrolling || this.destroyed) return;
                clearTimeout(this.scrollDebounce);
                this.scrollDebounce = setTimeout(() => {
                    if (this.destroyed) return;
                    const el = event.target;
                    const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
                    const overflows = el.scrollHeight - el.clientHeight > 10;
                    if (!this.alwaysScroll && !this.followManuallyDisabled && overflows && el.scrollTop > 0 && distanceFromBottom <= 10) {
                        this.alwaysScroll = true;
                        this.updateVirtualizer();
                    }
                }, 150);
            },
        };
    });
}
