<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Metrics | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="metrics" />

        <div class="application-settings-form flex w-full flex-col gap-6"
            @if ($server->isMetricsEnabled()) x-init="$wire.loadData()" @endif>
            @if ($server->isMetricsEnabled())
                @if ($live)
                    <div wire:key="metrics-poll-{{ $this->pollSeconds() }}" wire:poll.{{ $this->pollSeconds() }}s="loadData" class="hidden"></div>
                @endif
                <form wire:submit.prevent="saveMetricsSettings" class="contents">
                    <x-unsaved-bar action="saveMetricsSettings"
                        targets="sentinelMetricsRefreshRateSeconds,sentinelMetricsHistoryDays,sentinelPushIntervalSeconds" />

                    <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                        helper="Inspect recent CPU and memory usage reported by Sentinel.">
                        <x-slot:actions>
                            <div class="flex items-center gap-2">
                                <x-live-toggle :active="$live" storageKey="server-metrics-live" />
                                <x-forms.button canGate="update" :canResource="$server" wire:click="toggleMetrics">
                                    Disable metrics
                                </x-forms.button>
                            </div>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                                id="sentinelMetricsRefreshRateSeconds" label="Collection rate" required
                                helper="Seconds between metric samples." />
                            <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                                id="sentinelMetricsHistoryDays" label="History retention" required
                                helper="Days of CPU and memory history to retain." />
                            <x-forms.input canGate="update" :canResource="$server" type="number" min="10"
                                id="sentinelPushIntervalSeconds" label="Push interval" required
                                helper="Seconds between health reports sent to Coolify." />
                        </div>

                        <div class="mt-4 max-w-xs">
                            <x-forms.listbox id="interval" label="Time range" onChange="setInterval" :options="[
                                ['value' => 5, 'label' => 'Last 5 minutes'],
                                ['value' => 10, 'label' => 'Last 10 minutes'],
                                ['value' => 30, 'label' => 'Last 30 minutes'],
                                ['value' => 60, 'label' => 'Last hour'],
                                ['value' => 720, 'label' => 'Last 12 hours'],
                                ['value' => 10080, 'label' => 'Last week'],
                                ['value' => 43200, 'label' => 'Last 30 days'],
                            ]" />
                        </div>
                        <p class="mt-3 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                            While live, ranges up to one hour refresh every five seconds. Longer ranges refresh every minute.
                        </p>
                    </x-application.settings-section>
                </form>

                {{-- CPU is the primary signal, so it spans full width; the other four
                     charts fall into a 2x2 below (mirrors the fleet /metrics layout). --}}
                <div class="grid gap-6 lg:grid-cols-2">
                    <x-application.settings-section id="server-cpu-metrics-section" title="CPU usage" class="lg:col-span-2"
                        helper="Percentage of available CPU capacity used by this server.">
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
            @elseif ($server->isSentinelEnabled())
                <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                    helper="Inspect recent CPU and memory usage reported by Sentinel.">
                    <x-empty size="sm" title="Metrics are disabled"
                        description="Enable metrics to begin collecting CPU and memory history for this server."
                        icon-name="dashboard">
                        <x-slot:contents>
                            <x-forms.button canGate="update" :canResource="$server" isHighlighted
                                wire:click="toggleMetrics">
                                Enable metrics
                            </x-forms.button>
                        </x-slot:contents>
                    </x-empty>
                </x-application.settings-section>
            @else
                <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                    helper="Inspect recent CPU and memory usage reported by Sentinel.">
                    <x-empty size="sm" title="Metrics unavailable"
                        description="Sentinel metrics are unavailable on build and Swarm servers."
                        icon-name="dashboard">
                        <x-slot:contents>
                            <a class="button"
                                href="{{ route('server.sentinel', ['server_uuid' => $server->uuid]) }}"
                                {{ wireNavigate() }}>
                                View Sentinel
                                <x-external-link />
                            </a>
                        </x-slot:contents>
                    </x-empty>
                </x-application.settings-section>
            @endif

        </div>
    </div>
</div>
