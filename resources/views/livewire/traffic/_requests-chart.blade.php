{{--
    Requests-over-time chart: a single area series of total requests per bucket for the
    selected range. Reads its accent color from the shared --chart-status-3xx design
    token. Updated via the `refreshChartData-{chartId}-status` event (the `timeSeries.requests`
    array, aligned with `timeSeries.categories`). Expects `$chartId` in scope.
--}}
<div wire:ignore id="{!! $chartId !!}-requests" class="min-h-[220px] w-full"></div>

@script
<script>
    (() => {
        checkTheme();

        const el = document.getElementById('{!! $chartId !!}-requests');
        const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        const accent = () => cssVar('--chart-status-3xx') || '#3b82f6';
        const gridColor = () => cssVar('--chart-geo-empty') || 'rgba(128,128,128,0.15)';

        // `24h` buckets are hourly, `7d`/`30d` daily — pick a matching axis/tooltip format.
        const timeFormat = range => (range === '24h' ? 'HH:mm' : 'dd MMM');

        const chart = new ApexCharts(el, {
            chart: {
                height: 220,
                type: 'area',
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: { enabled: false },
                background: 'transparent',
            },
            series: [{ name: 'Requests', data: [] }],
            colors: [accent()],
            dataLabels: { enabled: false },
            stroke: { width: 2, curve: 'smooth' },
            fill: {
                type: 'gradient',
                gradient: { opacityFrom: 0.35, opacityTo: 0.05 },
            },
            xaxis: {
                type: 'datetime',
                labels: { style: { colors: textColor }, datetimeUTC: false },
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: {
                labels: {
                    style: { colors: textColor },
                    formatter: value => Math.round(value).toLocaleString(),
                },
            },
            grid: { borderColor: gridColor() },
            legend: { show: false },
            noData: {
                text: 'Loading requests…',
                style: { color: textColor },
            },
            tooltip: {
                y: {
                    formatter: value => `${value.toLocaleString()} requests`,
                },
            },
        });
        chart.render();

        Livewire.on('refreshChartData-{!! $chartId !!}-status', payload => {
            checkTheme();
            const data = Array.isArray(payload) ? payload[0] : payload;
            if (!data || !data.timeSeries) { return; }

            const ts = data.timeSeries;
            const points = (ts.categories || []).map((c, i) => ({ x: c, y: (ts.requests && ts.requests[i]) || 0 }));

            chart.updateOptions({
                colors: [accent()],
                grid: { borderColor: gridColor() },
                xaxis: {
                    type: 'datetime',
                    labels: { style: { colors: textColor }, datetimeUTC: false, format: timeFormat(data.range) },
                },
                tooltip: { x: { format: timeFormat(data.range) } },
            });
            chart.updateSeries([{ name: 'Requests', data: points }]);
        });
    })();
</script>
@endscript
