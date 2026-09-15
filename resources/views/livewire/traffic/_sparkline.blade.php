{{--
    Tiny inline sparkline for a KPI stat card. Renders an axis-less ApexCharts area
    spark from a numeric series and (when an event name is given) live-updates from the
    host component's chart payload so range/live refreshes stay in sync.

    Initialized via Alpine (not Livewire's @script): this partial is @include'd several
    times per page, and Livewire dedupes identical @script blocks from the same compiled
    view — so all but the last sparkline would silently never initialize. Alpine's
    init() runs once per element, with no such dedup.

    A no-data / all-zero series draws a flat muted baseline (a constant series with
    auto-scaling collapses to a degenerate, invisible range, so the y-axis is pinned).

    @param string   $id        unique DOM id for this spark
    @param array    $initial   initial numeric series (server-rendered first paint)
    @param string   $colorVar  CSS custom property for the line color (e.g. --chart-status-3xx)
    @param ?string  $event     Livewire event to listen on for updates (optional)
    @param ?string  $key       payload key holding the numeric array (required with $event)
--}}
@php
    $initial = $initial ?? [];
    $colorVar = $colorVar ?? '--chart-status-3xx';
    $event = $event ?? '';
    $key = $key ?? '';
    $initialCategories = array_column($series ?? [], 'bucket');
    $label = match ($key) {
        'requestsSpark' => 'Requests',
        'uniquesSpark' => 'Visitors',
        'bandwidthSpark' => 'Bandwidth',
        'errorsSpark' => 'Errors',
        'latencySpark' => 'p95 latency',
        default => 'Value',
    };
@endphp
<div wire:ignore id="{!! $id !!}" class="h-9 w-full [&_.apexcharts-svg]:overflow-visible!"
    x-data="{
        chart: null,
        refreshCleanup: null,
        accent() { return getComputedStyle(document.documentElement).getPropertyValue('{{ $colorVar }}').trim() || '#3b82f6'; },
        muted() { return getComputedStyle(document.documentElement).getPropertyValue('--chart-geo-empty').trim() || 'rgba(128,128,128,0.45)'; },
        isFlat(a) { return !Array.isArray(a) || a.length === 0 || a.every(v => !Number(v)); },
        points(values, categories) {
            return values.map((value, index) => categories[index] === undefined ? value : { x: categories[index], y: value });
        },
        opts(values, categories = []) {
            const flat = this.isFlat(values);
            const len = (Array.isArray(values) && values.length) ? values.length : 12;
            const data = flat ? Array(len).fill(0) : values;
            return {
                series: [{ name: @js($label), data: this.points(data, categories) }],
                colors: [flat ? this.muted() : this.accent()],
                stroke: { width: flat ? 1 : 1.5, curve: 'smooth' },
                fill: { type: 'gradient', gradient: { opacityFrom: flat ? 0 : 0.35, opacityTo: 0 } },
                tooltip: { enabled: !flat },
                yaxis: { labels: { show: false }, ...(flat ? { min: -1, max: 1 } : {}) },
            };
        },
        init() {
            if (typeof ApexCharts === 'undefined') { return; }
            const o = this.opts(@js($initial), @js($initialCategories));
            const formatLocalTimestamp = timestamp => new Date(timestamp).toLocaleString(undefined, {
                hour12: false,
                timeZoneName: 'short',
            });
            const formatUtcTimestamp = timestamp => new Date(timestamp).toLocaleString(undefined, {
                hour12: false,
                timeZone: 'UTC',
                timeZoneName: 'short',
            });
            const formatValue = value => {
                if (@js($key) === 'bandwidthSpark') {
                    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                    let amount = Number(value) || 0;
                    let unit = 0;
                    while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit++; }
                    return `${Number(amount.toFixed(unit === 0 ? 0 : 2))} ${units[unit]}`;
                }
                if (@js($key) === 'latencySpark') { return `${Number(value).toLocaleString()} ms`; }
                return Number(value).toLocaleString();
            };
            this.chart = new ApexCharts($el, {
                chart: { type: 'area', height: 36, sparkline: { enabled: true }, animations: { enabled: false }, background: 'transparent' },
                series: o.series,
                colors: o.colors,
                stroke: o.stroke,
                fill: o.fill,
                markers: { size: 0, hover: { size: 5, sizeOffset: 2 } },
                yaxis: o.yaxis,
                xaxis: { type: 'datetime', labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
                grid: { padding: { left: 4, right: 4, top: 0, bottom: 0 } },
                tooltip: {
                    enabled: o.tooltip.enabled,
                    shared: true,
                    intersect: false,
                    fixed: { enabled: false },
                    x: { show: false },
                    marker: { show: false },
                    custom: ({ series, dataPointIndex, w }) => {
                        const value = series[0][dataPointIndex];
                        const timestamp = w.globals.seriesX[0][dataPointIndex];
                        return `<div class='apexcharts-tooltip-custom'>
                            <div class='apexcharts-tooltip-custom-value'>${@js($label)}: <span class='apexcharts-tooltip-value-bold'>${formatValue(value)}</span></div>
                            <div class='apexcharts-tooltip-custom-title'>Your time: ${formatLocalTimestamp(timestamp)}</div>
                            <div class='apexcharts-tooltip-custom-title'>UTC: ${formatUtcTimestamp(timestamp)}</div>
                        </div>`;
                    },
                },
            });
            this.chart.render();
            const event = @js($event);
            const key = @js($key);
            if (event && key) {
                this.refreshCleanup = Livewire.on(event, payload => {
                    const data = Array.isArray(payload) ? payload[0] : payload;
                    if (this.chart && data && key in data) {
                        const u = this.opts(data[key], data.sparkCategories || []);
                        this.chart.updateOptions({ colors: u.colors, stroke: u.stroke, fill: u.fill, tooltip: { enabled: u.tooltip.enabled }, yaxis: u.yaxis }, false, false);
                        this.chart.updateSeries(u.series);
                    }
                });
            }
        },
        destroy() {
            this.refreshCleanup?.();
            this.chart?.destroy();
        },
    }"></div>
