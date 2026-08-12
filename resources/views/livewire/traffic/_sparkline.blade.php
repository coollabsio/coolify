{{--
    Tiny inline sparkline for a KPI stat card. Renders an axis-less ApexCharts area
    spark from a numeric series, and (when an event name is given) live-updates from
    the host component's chart payload so range/live refreshes stay in sync.

    @param string   $id        unique DOM id for this spark
    @param array    $initial   initial numeric series (server-rendered first paint)
    @param string   $colorVar  CSS custom property for the line color (e.g. --chart-status-3xx)
    @param ?string  $event     Livewire event to listen on for updates (optional)
    @param ?string  $key       payload key holding the numeric array (required with $event)
--}}
@php
    $initial = $initial ?? [];
    $colorVar = $colorVar ?? '--chart-status-3xx';
@endphp
<div wire:ignore id="{!! $id !!}" class="h-9 w-full"></div>

@script
<script>
    (() => {
        const el = document.getElementById('{!! $id !!}');
        if (!el) { return; }

        const readVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        const accent = () => readVar('{!! $colorVar !!}') || '#3b82f6';
        const muted = () => readVar('--chart-geo-empty') || 'rgba(128,128,128,0.4)';

        // No/all-zero data → draw a flat muted baseline placeholder instead of a blank card.
        // A constant series with auto-scaling collapses to a degenerate (invisible) range,
        // so the placeholder pins the y-axis to keep the zero line centered and visible.
        const isFlat = a => !Array.isArray(a) || a.length === 0 || a.every(v => !Number(v));

        const apply = (values) => {
            const flat = isFlat(values);
            const len = Array.isArray(values) && values.length ? values.length : 12;
            chart.updateOptions({
                colors: [flat ? muted() : accent()],
                stroke: { width: flat ? 1 : 1.5, curve: 'smooth' },
                fill: { type: 'gradient', gradient: { opacityFrom: flat ? 0 : 0.35, opacityTo: 0 } },
                tooltip: { enabled: !flat },
                yaxis: flat ? { min: -1, max: 1 } : { min: undefined, max: undefined },
            }, false, false);
            chart.updateSeries([{ data: flat ? new Array(len).fill(0) : values }]);
        };

        const chart = new ApexCharts(el, {
            chart: {
                type: 'area',
                height: 36,
                sparkline: { enabled: true },
                animations: { enabled: false },
                background: 'transparent',
            },
            series: [{ data: [] }],
            stroke: { width: 1.5, curve: 'smooth' },
            colors: [accent()],
            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
            tooltip: {
                enabled: true,
                fixed: { enabled: false },
                x: { show: false },
                y: { formatter: v => `${Math.round(v).toLocaleString()}`, title: { formatter: () => '' } },
                marker: { show: false },
            },
        });
        chart.render();
        apply(@json($initial));

        @if (! empty($event) && ! empty($key))
            Livewire.on('{!! $event !!}', payload => {
                const data = Array.isArray(payload) ? payload[0] : payload;
                if (data && '{!! $key !!}' in data) {
                    apply(data['{!! $key !!}']);
                }
            });
        @endif
    })();
</script>
@endscript
