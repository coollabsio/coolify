{{--
    Requests-by-device donut. Renders an ApexCharts donut from the device breakdown
    (Desktop / Mobile / Tablet / Bot …) and live-updates from the host component's
    chart payload. Expects `$chartId` in scope; reads `deviceLabels`/`deviceSeries`
    from the `refreshChartData-{chartId}-status` payload.

    @param array $labels   initial device labels (server-rendered first paint)
    @param array $series   initial device request counts
--}}
@php
    $labels = $labels ?? [];
    $series = $series ?? [];
    $deviceChartId = $chartId.'-device';
    $hasDeviceData = array_sum(array_map('intval', $series)) > 0;
@endphp
@if (! $hasDeviceData)
    <x-empty size="sm" title="No device data" description="No device data for the selected range." icon-name="network" />
@else
    <div wire:ignore id="{!! $deviceChartId !!}" class="min-h-[240px] w-full"></div>

    @script
    <script>
        (() => {
            requestAnimationFrame(() => {
            checkTheme();
            const el = document.getElementById('{!! $deviceChartId !!}');
            if (!el) { return; }

            const palette = ['#3b82f6', '#f59e0b', '#ec4899', '#8b5cf6', '#10b981', '#14b8a6', '#6b7280'];
            const legend = () => ({ position: 'bottom', labels: { colors: textColor } });
            const escapeHtml = value => String(value).replace(/[&<>'"]/g, character => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
            })[character]);

            const chart = new ApexCharts(el, {
                chart: { type: 'donut', height: 240, background: 'transparent', animations: { enabled: false } },
                series: @json(array_map('intval', $series)),
                labels: @json(array_values($labels)),
                colors: palette,
                stroke: { width: 2 },
                dataLabels: { enabled: false },
                legend: legend(),
                plotOptions: { pie: { donut: { size: '68%' } } },
                tooltip: {
                    fillSeriesColor: false,
                    custom: ({ series, seriesIndex, w }) => {
                        const label = escapeHtml(w.globals.labels[seriesIndex] ?? '');
                        const requests = Number(series[seriesIndex] ?? 0).toLocaleString();

                        return `<div class="apexcharts-tooltip-custom">
                            <div class="apexcharts-tooltip-custom-value">${label}: <span class="apexcharts-tooltip-value-bold">${requests} requests</span></div>
                        </div>`;
                    },
                },
                noData: { text: 'Loading devices…', style: { color: textColor } },
            });
            chart.render();

            Livewire.on('refreshChartData-{!! $chartId !!}-status', payload => {
                checkTheme();
                const data = Array.isArray(payload) ? payload[0] : payload;
                if (!data || !Array.isArray(data.deviceSeries)) { return; }
                chart.updateOptions({ labels: data.deviceLabels, legend: legend() });
                chart.updateSeries(data.deviceSeries);
            });
            });
        })();
    </script>
    @endscript
@endif
