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
@endphp
<div wire:ignore id="{!! $id !!}" class="h-9 w-full"
    x-data="{
        chart: null,
        accent() { return getComputedStyle(document.documentElement).getPropertyValue('{{ $colorVar }}').trim() || '#3b82f6'; },
        muted() { return getComputedStyle(document.documentElement).getPropertyValue('--chart-geo-empty').trim() || 'rgba(128,128,128,0.45)'; },
        isFlat(a) { return !Array.isArray(a) || a.length === 0 || a.every(v => !Number(v)); },
        opts(values) {
            const flat = this.isFlat(values);
            const len = (Array.isArray(values) && values.length) ? values.length : 12;
            return {
                series: [{ data: flat ? Array(len).fill(0) : values }],
                colors: [flat ? this.muted() : this.accent()],
                stroke: { width: flat ? 1 : 1.5, curve: 'smooth' },
                fill: { type: 'gradient', gradient: { opacityFrom: flat ? 0 : 0.35, opacityTo: 0 } },
                tooltip: { enabled: !flat },
                yaxis: flat ? { min: -1, max: 1 } : {},
            };
        },
        init() {
            if (typeof ApexCharts === 'undefined') { return; }
            const o = this.opts(@js($initial));
            this.chart = new ApexCharts($el, {
                chart: { type: 'area', height: 36, sparkline: { enabled: true }, animations: { enabled: false }, background: 'transparent' },
                series: o.series,
                colors: o.colors,
                stroke: o.stroke,
                fill: o.fill,
                yaxis: o.yaxis,
                tooltip: {
                    enabled: o.tooltip.enabled,
                    fixed: { enabled: false },
                    x: { show: false },
                    y: { formatter: v => Math.round(v).toLocaleString(), title: { formatter: () => '' } },
                    marker: { show: false },
                },
            });
            this.chart.render();
            @if ($event && $key)
                Livewire.on('{{ $event }}', payload => {
                    const data = Array.isArray(payload) ? payload[0] : payload;
                    if (this.chart && data && '{{ $key }}' in data) {
                        const u = this.opts(data['{{ $key }}']);
                        this.chart.updateOptions({ colors: u.colors, stroke: u.stroke, fill: u.fill, tooltip: { enabled: u.tooltip.enabled }, yaxis: u.yaxis }, false, false);
                        this.chart.updateSeries(u.series);
                    }
                });
            @endif
        },
    }"></div>
