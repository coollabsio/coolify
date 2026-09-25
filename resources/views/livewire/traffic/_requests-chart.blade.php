{{--
    Requests-over-time chart: a single area series of total requests per bucket for the
    selected range. Reads its accent color from the shared --chart-status-3xx design
    token. Updated via the `refreshChartData-{chartId}-status` event (the `timeSeries.requests`
    array, aligned with `timeSeries.categories`). Expects `$chartId` in scope.
--}}
@php
    $initialChartData = [
        'initialCategories' => array_column($series, 'bucket'),
        'initialRequests' => $this->requestsSpark(),
    ];
@endphp
<div wire:ignore class="relative w-full">
    <div id="{{ $chartId }}-requests" class="min-h-[220px] w-full"></div>

    {{-- No-data overlay: covers the empty chart frame when no requests fall in the range.
         Uses the shared x-empty component so it matches the other analytics empty states
         (e.g. Status codes). Toggled from the refresh listener below (kept mounted so the
         chart's listener survives live/range re-renders). --}}
    <div id="{{ $chartId }}-requests-empty" style="display: {{ $this->hasRequestSeries() ? 'none' : 'flex' }}"
        class="absolute inset-0 items-center justify-center bg-white dark:bg-base">
        <x-empty size="sm" title="No requests in this range"
            description="No request traffic was recorded for the selected filters and range. Try a wider range or check back later."
            icon-name="analytics" />
    </div>
</div>

@script
<script>
    (() => {
        requestAnimationFrame(() => {
        checkTheme();

        const chartId = @js($chartId);
        const initial = @js($initialChartData);
        const initialPoints = initial.initialCategories.map((category, index) => ({
            x: category,
            y: initial.initialRequests[index] || 0,
        }));
        const el = document.getElementById(`${chartId}-requests`);
        const emptyEl = document.getElementById(`${chartId}-requests-empty`);
        if (!el) { return; }
        const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        const accent = () => cssVar('--chart-status-3xx') || '#3b82f6';
        const gridColor = () => cssVar('--chart-geo-empty') || 'rgba(128,128,128,0.15)';

        // `24h` buckets are hourly, `7d`/`30d` daily — pick a matching axis/tooltip format.
        const timeFormat = range => (range === '24h' ? 'HH:mm' : 'dd MMM');
        const formatLocalTimestamp = timestamp => new Date(timestamp).toLocaleString(undefined, {
            hour12: false,
            timeZoneName: 'short',
        });
        const formatUtcTimestamp = timestamp => new Date(timestamp).toLocaleString(undefined, {
            hour12: false,
            timeZone: 'UTC',
            timeZoneName: 'short',
        });

        const chart = new ApexCharts(el, {
            chart: {
                height: 220,
                type: 'area',
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: { enabled: false },
                background: 'transparent',
            },
            series: [{ name: 'Requests', data: initialPoints }],
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
                    minWidth: 28,
                    maxWidth: 28,
                    style: { colors: textColor },
                    formatter: value => Math.round(value).toLocaleString(),
                },
            },
            grid: {
                borderColor: gridColor(),
                padding: { left: 6, right: 12, top: 12, bottom: 0 },
            },
            legend: { show: false },
            noData: {
                text: 'Loading requests…',
                style: { color: textColor },
            },
            tooltip: {
                shared: true,
                intersect: false,
                marker: { show: false },
                custom: ({ series, seriesIndex, dataPointIndex, w }) => {
                    const requests = series[seriesIndex][dataPointIndex];
                    const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];

                    return `<div class="apexcharts-tooltip-custom">
                        <div class="apexcharts-tooltip-custom-value">Requests: <span class="apexcharts-tooltip-value-bold">${requests.toLocaleString()}</span></div>
                        <div class="apexcharts-tooltip-custom-title">Your time: ${formatLocalTimestamp(timestamp)}</div>
                        <div class="apexcharts-tooltip-custom-title">UTC: ${formatUtcTimestamp(timestamp)}</div>
                    </div>`;
                },
            },
        });
        chart.render();

        Livewire.on(`refreshChartData-${chartId}-status`, payload => {
            checkTheme();
            const data = Array.isArray(payload) ? payload[0] : payload;
            if (!data || !data.timeSeries) { return; }

            const ts = data.timeSeries;
            const points = (ts.categories || []).map((c, i) => ({ x: c, y: (ts.requests && ts.requests[i]) || 0 }));

            // Show the no-data overlay when nothing in the range carries request volume.
            const hasData = points.some(p => (p.y || 0) > 0);
            if (emptyEl) { emptyEl.style.display = hasData ? 'none' : 'flex'; }

            chart.updateOptions({
                colors: [accent()],
                grid: {
                    borderColor: gridColor(),
                    padding: { left: 6, right: 12, top: 12, bottom: 0 },
                },
                xaxis: {
                    type: 'datetime',
                    labels: { style: { colors: textColor }, datetimeUTC: false, format: timeFormat(data.range) },
                },
                tooltip: { x: { format: timeFormat(data.range) } },
            });
            chart.updateSeries([{ name: 'Requests', data: points }]);
        });
        });
    })();
</script>
@endscript
