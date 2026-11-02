                {{-- CPU is the primary signal, so it spans full width; the other four
                     charts fall into a 2x2 below (mirrors the fleet /metrics layout). --}}
                <div class="grid gap-6 lg:grid-cols-2">
                    <x-application.settings-section id="server-cpu-metrics-section" title="CPU usage" class="lg:col-span-2"
                        helper="Average usage of all CPU cores on this server. 100% means that all cores are fully busy. For example, on a server with 8 cores, one fully busy core shows as 12.5%.">
                        <x-slot:actions>
                            <div wire:ignore id="{!! $chartId !!}-cpu-legend" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"></div>
                        </x-slot:actions>
                        <div wire:ignore id="{!! $chartId !!}-cpu" class="min-h-[240px] w-full"></div>
                    </x-application.settings-section>

                    <x-application.settings-section id="server-memory-metrics-section" title="Memory usage"
                        helper="Percentage of physical memory currently used by this server.">
                        <x-slot:actions>
                            <div wire:ignore id="{!! $chartId !!}-memory-legend" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"></div>
                        </x-slot:actions>
                        <div wire:ignore id="{!! $chartId !!}-memory" class="min-h-[240px] w-full"></div>
                    </x-application.settings-section>

                    <x-application.settings-section id="server-network-metrics-section" title="Network throughput"
                        helper="Receive and transmit rate across the server's network interfaces.">
                        <x-slot:actions>
                            <div wire:ignore id="{!! $chartId !!}-network-legend" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"></div>
                        </x-slot:actions>
                        <div wire:ignore id="{!! $chartId !!}-network" class="min-h-[240px] w-full"></div>
                    </x-application.settings-section>

                    <x-application.settings-section id="server-load-metrics-section" title="Load average"
                        helper="One minute load average reported by the server.">
                        <x-slot:actions>
                            <div wire:ignore id="{!! $chartId !!}-load-legend" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"></div>
                        </x-slot:actions>
                        <div wire:ignore id="{!! $chartId !!}-load" class="min-h-[240px] w-full"></div>
                    </x-application.settings-section>

                    <x-application.settings-section id="server-disk-metrics-section" title="Disk usage"
                        helper="Percentage of the root filesystem used by this server.">
                        <x-slot:actions>
                            <div wire:ignore id="{!! $chartId !!}-disk-legend" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"></div>
                        </x-slot:actions>
                        <div wire:ignore id="{!! $chartId !!}-disk" class="min-h-[240px] w-full"></div>
                    </x-application.settings-section>
                </div>

                @script
                    <script>
                        (() => {
                            checkTheme();

                            const formatPercent = value => {
                                const number = Number(value);
                                const precision = Math.abs(number) < 1 ? 2 : 1;

                                return `${Number(number.toFixed(precision))}%`;
                            };

                            const formatNumber = value => Number(Number(value).toFixed(2)).toLocaleString();
                            const formatBytes = value => {
                                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                                let amount = Number(value) || 0;
                                let unit = 0;
                                while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit++; }
                                return `${Number(amount.toFixed(unit === 0 ? 0 : 2))} ${units[unit]}`;
                            };
                            const formatRate = value => `${formatBytes(value)}/s`;


                            const chartOptions = (name, color, loadingText, formatter = formatPercent) => ({
                                chart: {
                                    height: 240,
                                    type: 'area',
                                    toolbar: {
                                        show: false
                                    },
                                    zoom: {
                                        enabled: false
                                    },
                                    animations: {
                                        enabled: true
                                    },
                                    background: 'transparent',
                                },
                                series: [{
                                    name,
                                    data: [],
                                }],
                                colors: [color],
                                stroke: {
                                    curve: 'smooth',
                                    width: 2,
                                },
                                fill: {
                                    type: 'gradient',
                                    gradient: {
                                        opacityFrom: 0.28,
                                        opacityTo: 0.02,
                                        stops: [0, 90, 100],
                                    },
                                },
                                dataLabels: {
                                    enabled: false,
                                },
                                grid: {
                                    borderColor: 'rgba(128, 128, 128, 0.14)',
                                    strokeDashArray: 4,
                                },
                                legend: {
                                    show: false,
                                },
                                xaxis: {
                                    type: 'datetime',
                                    labels: {
                                        datetimeUTC: false,
                                        style: {
                                            colors: textColor,
                                        },
                                    },
                                },
                                yaxis: {
                                    min: 0,
                                    max: max => max > 0 ? max * 1.2 : 1,
                                    forceNiceScale: true,
                                    tickAmount: 4,
                                    labels: {
                                        style: {
                                            colors: textColor,
                                        },
                                        formatter,
                                    },
                                },
                                noData: {
                                    text: loadingText,
                                    style: {
                                        color: textColor,
                                    },
                                },
                                tooltip: {
                                    shared: false,
                                    intersect: false,
                                    followCursor: false,
                                    fixed: {
                                        enabled: false,
                                    },
                                    marker: {
                                        show: false,
                                    },
                                    custom: ({
                                        series,
                                        seriesIndex,
                                        dataPointIndex,
                                        w
                                    }) => {
                                        const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];
                                        const values = series.map((values, index) => values[dataPointIndex] === undefined ? '' :
                                            `<div class="apexcharts-tooltip-custom-value">${w.globals.seriesNames?.[index] ?? name}: <span class="apexcharts-tooltip-value-bold">${formatter(values[dataPointIndex])}</span></div>`);

                                        return `<div class="apexcharts-tooltip-custom">
                                            ${values.join('')}
                                            ${window.chartTooltipTime(timestamp)}
                                        </div>`;
                                    },
                                },
                            });

                            const cpuChart = new ApexCharts(
                                document.getElementById('{!! $chartId !!}-cpu'),
                                chartOptions('CPU', cpuColor, 'Loading CPU metrics…'),
                            );
                            const memoryChart = new ApexCharts(
                                document.getElementById('{!! $chartId !!}-memory'),
                                chartOptions('Memory', ramColor, 'Loading memory metrics…'),
                            );

                            const diskChart = new ApexCharts(
                                document.getElementById('{!! $chartId !!}-disk'),
                                chartOptions('Disk', '#ef4444', 'Loading disk metrics…', formatPercent),
                            );
                            const networkChart = new ApexCharts(
                                document.getElementById('{!! $chartId !!}-network'),
                                {
                                    ...chartOptions('Network', '#10b981', 'Loading network metrics…', formatRate),
                                    colors: ['#10b981', '#f59e0b'],
                                },
                            );
                            const loadChart = new ApexCharts(
                                document.getElementById('{!! $chartId !!}-load'),
                                chartOptions('Load', '#14b8a6', 'Loading load metrics…', formatNumber),
                            );

                            const renderLegend = (key, items) => renderChartLegend(
                                document.getElementById(`{!! $chartId !!}-${key}-legend`), items);

                            // Defer to the next frame so the 2x2 grid cells are laid out
                            // before ApexCharts measures them (a zero-width cell renders blank).
                            requestAnimationFrame(() => {
                                cpuChart.render();
                                memoryChart.render();
                                diskChart.render();
                                networkChart.render();
                                loadChart.render();
                                window.syncChartTooltips('{!! $chartId !!}-metrics', [cpuChart, memoryChart, networkChart, loadChart, diskChart]);
                            });

                            Livewire.on('refreshChartData-{!! $chartId !!}-metrics', chartData => {
                                checkTheme();
                                const data = Array.isArray(chartData) ? chartData[0] : chartData;

                                cpuChart.updateOptions({
                                    colors: [cpuColor],
                                    series: [{
                                        name: 'CPU',
                                        data: data.cpuSeries,
                                    }],
                                    xaxis: {
                                        type: 'datetime',
                                        labels: {
                                            datetimeUTC: false,
                                            style: {
                                                colors: textColor,
                                            },
                                        },
                                    },
                                    yaxis: {
                                        min: 0,
                                        max: max => max > 0 ? max * 1.2 : 1,
                                        forceNiceScale: true,
                                        tickAmount: 4,
                                        labels: {
                                            style: {
                                                colors: textColor,
                                            },
                                            formatter: formatPercent,
                                        },
                                    },
                                    noData: {
                                        text: 'No CPU metrics available',
                                        style: {
                                            color: textColor,
                                        },
                                    },
                                });
                                memoryChart.updateOptions({
                                    colors: [ramColor],
                                    series: [{
                                        name: 'Memory',
                                        data: data.memorySeries,
                                    }],
                                    xaxis: {
                                        type: 'datetime',
                                        labels: {
                                            datetimeUTC: false,
                                            style: {
                                                colors: textColor,
                                            },
                                        },
                                    },
                                    yaxis: {
                                        min: 0,
                                        max: max => max > 0 ? max * 1.2 : 1,
                                        forceNiceScale: true,
                                        tickAmount: 4,
                                        labels: {
                                            style: {
                                                colors: textColor,
                                            },
                                            formatter: formatPercent,
                                        },
                                    },
                                    noData: {
                                        text: 'No memory metrics available',
                                        style: {
                                            color: textColor,
                                        },
                                    },
                                });
                                diskChart.updateOptions({
                                    series: [{ name: 'Disk', data: data.diskSeries || [] }],
                                    noData: { text: 'No disk metrics available' },
                                });
                                networkChart.updateOptions({
                                    series: [
                                        { name: 'RX', data: data.networkRxSeries || [] },
                                        { name: 'TX', data: data.networkTxSeries || [] },
                                    ],
                                    noData: { text: 'No network metrics available' },
                                });
                                loadChart.updateOptions({
                                    series: [{ name: 'Load', data: data.loadSeries || [] }],
                                    noData: { text: 'No load metrics available' },
                                });

                                renderLegend('cpu', [{ name: 'CPU', color: cpuColor, data: data.cpuSeries, formatter: formatPercent }]);
                                renderLegend('memory', [{ name: 'Memory', color: ramColor, data: data.memorySeries, formatter: formatPercent }]);
                                renderLegend('network', [
                                    { name: 'RX', color: '#10b981', data: data.networkRxSeries, formatter: formatRate },
                                    { name: 'TX', color: '#f59e0b', data: data.networkTxSeries, formatter: formatRate },
                                ]);
                                renderLegend('load', [{ name: 'Load', color: '#14b8a6', data: data.loadSeries, formatter: formatNumber }]);
                                renderLegend('disk', [{ name: 'Disk', color: '#ef4444', data: data.diskSeries, formatter: formatPercent }]);
                            });
                        })();
                    </script>
                @endscript
