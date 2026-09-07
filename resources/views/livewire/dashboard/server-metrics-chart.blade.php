<div
    class="absolute right-0 bottom-0 h-2/3 w-full rounded-b-xl [&_.apexcharts-svg]:overflow-hidden [&_.apexcharts-svg]:rounded-b-xl [&_.apexcharts-tooltip]:z-30!"
    x-data="{
        hiddenAt: null,
        refreshInterval: null,
        visibilityHandler: null,
        init() {
            this.visibilityHandler = () => {
                if (document.hidden) {
                    this.hiddenAt = Date.now();
                    return;
                }

                if (this.hiddenAt && Date.now() - this.hiddenAt >= 60000) {
                    $wire.loadData();
                }

                this.hiddenAt = null;
            };

            document.addEventListener('visibilitychange', this.visibilityHandler);
            this.refreshInterval = window.setInterval(() => {
                if (!document.hidden) {
                    $wire.loadData();
                }
            }, 60000);

            $wire.loadData();
        },
        destroy() {
            window.clearInterval(this.refreshInterval);
            document.removeEventListener('visibilitychange', this.visibilityHandler);
        },
    }">
    <div wire:ignore id="dashboard-server-metrics-{{ $server->uuid }}" class="h-full w-full"></div>

    @script
        <script>
            (() => {
                const chart = new ApexCharts(
                    document.getElementById('dashboard-server-metrics-{{ $server->uuid }}'), {
                        chart: {
                            height: '100%',
                            type: 'area',
                            toolbar: { show: false },
                            zoom: { enabled: false },
                            animations: { enabled: true },
                            sparkline: { enabled: true },
                            background: 'transparent',
                        },
                        series: [
                            { name: 'CPU', data: [] },
                            { name: 'Memory', data: [] },
                        ],
                        colors: [
                            'rgba(30, 144, 255, 0.52)',
                            'rgba(168, 85, 247, 0.42)',
                        ],
                        stroke: {
                            curve: 'smooth',
                            width: 1.5,
                        },
                        fill: {
                            type: 'gradient',
                            gradient: {
                                opacityFrom: 0.18,
                                opacityTo: 0.02,
                                stops: [0, 100],
                            },
                        },
                        dataLabels: { enabled: false },
                        grid: { show: false },
                        legend: { show: false },
                        markers: { size: 0 },
                        xaxis: {
                            type: 'datetime',
                            labels: { show: false },
                            axisBorder: { show: false },
                            axisTicks: { show: false },
                        },
                        yaxis: {
                            min: 0,
                            max: 100,
                            labels: { show: false },
                        },
                        tooltip: {
                            shared: true,
                            intersect: false,
                            marker: { show: false },
                            custom: ({ series, seriesIndex, dataPointIndex, w }) => {
                                const cpu = series[0][dataPointIndex];
                                const memory = series[1][dataPointIndex];
                                const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];
                                const formatPercent = value => Number.isFinite(value) ? `${Number(value.toFixed(1))}%` : '—';
                                const formatTimestamp = timestamp => `${new Date(timestamp).toLocaleString(undefined, {
                                    timeZone: 'UTC',
                                    hour12: false,
                                })} UTC`;

                                return `<div class="apexcharts-tooltip-custom">
                                    <div class="apexcharts-tooltip-custom-value">CPU: <span class="apexcharts-tooltip-value-bold">${formatPercent(cpu)}</span></div>
                                    <div class="apexcharts-tooltip-custom-value">Memory: <span class="apexcharts-tooltip-value-bold">${formatPercent(memory)}</span></div>
                                    <div class="apexcharts-tooltip-custom-title">${formatTimestamp(timestamp)}</div>
                                </div>`;
                            },
                        },
                    });

                chart.render();

                Livewire.on('dashboard-server-metrics-{{ $server->uuid }}', chartData => {
                    chart.updateSeries([
                        {
                            name: 'CPU',
                            data: chartData[0].cpu ?? [],
                        },
                        {
                            name: 'Memory',
                            data: chartData[0].memory ?? [],
                        },
                    ]);
                });
            })();
        </script>
    @endscript
</div>
