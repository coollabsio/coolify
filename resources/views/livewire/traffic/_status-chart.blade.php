{{--
    Status-class chart. Renders a stacked area chart of per-bucket 2xx/3xx/4xx/5xx
    counts over time when Sentinel exposes the /traffic/series endpoint, and falls
    back to a status donut when it does not (older Sentinel returns 404, so the host
    component dispatches `hasSeries: false`). Colors read from the shared --chart-status-*
    design tokens. Expects `$chartId` in scope.
--}}
<div wire:ignore id="{!! $chartId !!}-status" class="min-h-[220px] w-full"></div>

@script
<script>
    (() => {
        checkTheme();

        const el = document.getElementById('{!! $chartId !!}-status');
        const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        const statusColors = () => [
            cssVar('--chart-status-2xx'),
            cssVar('--chart-status-3xx'),
            cssVar('--chart-status-4xx'),
            cssVar('--chart-status-5xx'),
        ];
        const gridColor = () => cssVar('--chart-geo-empty') || 'rgba(128,128,128,0.15)';

        const legend = () => ({
            position: 'bottom',
            labels: {
                colors: textColor,
            },
        });

        const buildDonut = () => new ApexCharts(el, {
            chart: {
                height: 220,
                type: 'donut',
                toolbar: { show: false },
                background: 'transparent',
            },
            series: [0, 0, 0, 0],
            labels: ['2xx', '3xx', '4xx', '5xx'],
            colors: statusColors(),
            stroke: { width: 2 },
            dataLabels: { enabled: false },
            legend: legend(),
            noData: {
                text: 'Loading status codes…',
                style: { color: textColor },
            },
            tooltip: {
                y: {
                    formatter: value => `${value.toLocaleString()} requests`,
                },
            },
        });

        // `24h` buckets are hourly, `7d`/`30d` daily — pick a matching axis/tooltip format.
        const timeFormat = range => (range === '24h' ? 'HH:mm' : 'dd MMM');

        const buildArea = () => new ApexCharts(el, {
            chart: {
                height: 220,
                type: 'area',
                stacked: true,
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: { enabled: false },
                background: 'transparent',
            },
            series: [],
            colors: statusColors(),
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
            legend: legend(),
            noData: {
                text: 'Loading status codes…',
                style: { color: textColor },
            },
            tooltip: {
                y: {
                    formatter: value => `${value.toLocaleString()} requests`,
                },
            },
        });

        let chart = buildDonut();
        let mode = 'donut';
        chart.render();

        Livewire.on('refreshChartData-{!! $chartId !!}-status', payload => {
            checkTheme();
            const data = Array.isArray(payload) ? payload[0] : payload;
            const wantArea = !!(data && data.hasSeries && data.timeSeries);
            const nextMode = wantArea ? 'area' : 'donut';

            if (nextMode !== mode) {
                chart.destroy();
                chart = nextMode === 'area' ? buildArea() : buildDonut();
                chart.render();
                mode = nextMode;
            }

            if (nextMode === 'area') {
                chart.updateOptions({
                    colors: statusColors(),
                    grid: { borderColor: gridColor() },
                    legend: legend(),
                    xaxis: {
                        type: 'datetime',
                        categories: data.timeSeries.categories,
                        labels: { style: { colors: textColor }, datetimeUTC: false, format: timeFormat(data.range) },
                    },
                    tooltip: { x: { format: timeFormat(data.range) } },
                });
                chart.updateSeries([
                    { name: '2xx', data: data.timeSeries.s2xx },
                    { name: '3xx', data: data.timeSeries.s3xx },
                    { name: '4xx', data: data.timeSeries.s4xx },
                    { name: '5xx', data: data.timeSeries.s5xx },
                ]);
            } else {
                chart.updateOptions({ colors: statusColors(), legend: legend() });
                chart.updateSeries(data.seriesData);
            }
        });
    })();
</script>
@endscript
