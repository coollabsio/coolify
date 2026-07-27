<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Metrics | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="metrics" />

        <div class="application-settings-form flex w-full flex-col gap-6"
            @if ($server->isMetricsEnabled()) x-init="$wire.loadData()"
                @if ($poll) wire:poll.5000ms="pollData" @endif
            @endif>
            @if ($server->isMetricsEnabled())
                <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                    helper="Inspect recent CPU and memory usage reported by Sentinel.">
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="$poll ? 'Live updates' : 'Historical range'"
                                :type="$poll ? 'success' : 'neutral'" />
                            <x-forms.button canGate="update" :canResource="$server" wire:click="toggleMetrics">
                                Disable metrics
                            </x-forms.button>
                        </div>
                    </x-slot:actions>

                    <div class="max-w-xs">
                        <x-forms.listbox id="interval" label="Time range" onChange="setInterval" :options="[
                            ['value' => 5, 'label' => 'Last 5 minutes · live'],
                            ['value' => 10, 'label' => 'Last 10 minutes · live'],
                            ['value' => 30, 'label' => 'Last 30 minutes'],
                            ['value' => 60, 'label' => 'Last hour'],
                            ['value' => 720, 'label' => 'Last 12 hours'],
                            ['value' => 10080, 'label' => 'Last week'],
                            ['value' => 43200, 'label' => 'Last 30 days'],
                        ]" />
                    </div>
                    <p class="mt-3 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                        Five and ten minute ranges refresh automatically every five seconds.
                    </p>
                </x-application.settings-section>

                <x-application.settings-section id="server-cpu-metrics-section" title="CPU usage"
                    helper="Percentage of available CPU capacity used by this server.">
                    <div wire:ignore id="{!! $chartId !!}-cpu" class="min-h-[240px] w-full"></div>
                </x-application.settings-section>

                <x-application.settings-section id="server-memory-metrics-section" title="Memory usage"
                    helper="Percentage of physical memory currently used by this server.">
                    <div wire:ignore id="{!! $chartId !!}-memory" class="min-h-[240px] w-full"></div>
                </x-application.settings-section>

                @script
                    <script>
                        (() => {
                            checkTheme();

                            const formatPercent = value => {
                                const number = Number(value);
                                const precision = Math.abs(number) < 1 ? 2 : 1;

                                return `${Number(number.toFixed(precision))}%`;
                            };

                            const formatTimestamp = timestamp => {
                                const date = new Date(timestamp);

                                return `${date.toLocaleString(undefined, {
                                    timeZone: 'UTC',
                                    hour12: false
                                })} UTC`;
                            };

                            const chartOptions = (name, color, loadingText) => ({
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
                                        datetimeUTC: true,
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
                                        const value = series[seriesIndex][dataPointIndex];
                                        const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];

                                        return `<div class="apexcharts-tooltip-custom">
                                            <div class="apexcharts-tooltip-custom-value">${name}: <span class="apexcharts-tooltip-value-bold">${formatPercent(value)}</span></div>
                                            <div class="apexcharts-tooltip-custom-title">${formatTimestamp(timestamp)}</div>
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

                            cpuChart.render();
                            memoryChart.render();

                            Livewire.on('refreshChartData-{!! $chartId !!}-cpu', chartData => {
                                checkTheme();
                                cpuChart.updateOptions({
                                    colors: [cpuColor],
                                    series: [{
                                        name: 'CPU',
                                        data: chartData[0].seriesData,
                                    }],
                                    xaxis: {
                                        type: 'datetime',
                                        labels: {
                                            datetimeUTC: true,
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
                            });

                            Livewire.on('refreshChartData-{!! $chartId !!}-memory', chartData => {
                                checkTheme();
                                memoryChart.updateOptions({
                                    colors: [ramColor],
                                    series: [{
                                        name: 'Memory',
                                        data: chartData[0].seriesData,
                                    }],
                                    xaxis: {
                                        type: 'datetime',
                                        labels: {
                                            datetimeUTC: true,
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
                            });
                        })();
                    </script>
                @endscript
            @elseif ($server->isSentinelEnabled())
                <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                    helper="Inspect recent CPU and memory usage reported by Sentinel.">
                    <x-slot:actions>
                        <x-status-badge status="Disabled" type="neutral" />
                    </x-slot:actions>

                    <x-empty size="sm" title="Metrics are disabled"
                        description="Enable metrics to begin collecting CPU and memory history for this server.">
                        <x-slot:icon>
                            <x-reicon name="dashboard" class="size-8" />
                        </x-slot:icon>
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
                    <x-slot:actions>
                        <x-status-badge status="Unavailable" type="warning" />
                    </x-slot:actions>

                    <x-empty size="sm" title="Sentinel is required"
                        description="Enable Sentinel before collecting CPU and memory metrics for this server.">
                        <x-slot:icon>
                            <x-reicon name="dashboard" class="size-8" />
                        </x-slot:icon>
                        <x-slot:contents>
                            <a class="button"
                                href="{{ route('server.sentinel', ['server_uuid' => $server->uuid]) }}"
                                {{ wireNavigate() }}>
                                Configure Sentinel
                                <x-external-link />
                            </a>
                        </x-slot:contents>
                    </x-empty>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
