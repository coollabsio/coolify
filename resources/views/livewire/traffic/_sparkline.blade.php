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

        const cssVar = () => getComputedStyle(document.documentElement).getPropertyValue('{!! $colorVar !!}').trim() || '#3b82f6';

        const chart = new ApexCharts(el, {
            chart: {
                type: 'area',
                height: 36,
                sparkline: { enabled: true },
                animations: { enabled: false },
                background: 'transparent',
            },
            series: [{ data: @json($initial) }],
            stroke: { width: 1.5, curve: 'smooth' },
            colors: [cssVar()],
            fill: {
                type: 'gradient',
                gradient: { opacityFrom: 0.35, opacityTo: 0.02 },
            },
            tooltip: {
                enabled: true,
                fixed: { enabled: false },
                x: { show: false },
                y: { formatter: v => `${Math.round(v).toLocaleString()}`, title: { formatter: () => '' } },
                marker: { show: false },
            },
        });
        chart.render();

        @if (! empty($event) && ! empty($key))
            Livewire.on('{!! $event !!}', payload => {
                const data = Array.isArray(payload) ? payload[0] : payload;
                const next = data && data['{!! $key !!}'];
                if (Array.isArray(next)) {
                    chart.updateOptions({ colors: [cssVar()] });
                    chart.updateSeries([{ data: next }]);
                }
            });
        @endif
    })();
</script>
@endscript
