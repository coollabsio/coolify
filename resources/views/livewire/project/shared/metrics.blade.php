<div class="flex flex-col gap-6"
    @if (
        $resource->getMorphClass() !== 'App\Models\Application' ||
            ($resource->build_pack !== 'dockercompose' &&
                $resource->destination->server->isMetricsEnabled() &&
                str($resource->status)->contains('running'))) x-init="$wire.loadData()"
        @if ($poll) wire:poll.5000ms="pollData" @endif
    @endif>
    @if ($resource->getMorphClass() === 'App\Models\Application' && $resource->build_pack === 'dockercompose')
        <x-application.settings-section id="metrics-overview-section" title="Metrics"
            helper="Inspect CPU and memory usage for this application.">
            <x-empty size="sm" title="Metrics unavailable"
                description="Container metrics are not currently available for Docker Compose applications.">
                <x-slot:icon>
                    <x-reicon name="dashboard" class="size-8" />
                </x-slot:icon>
            </x-empty>
        </x-application.settings-section>
    @elseif (!$resource->destination->server->isMetricsEnabled())
        <x-application.settings-section id="metrics-overview-section" title="Metrics"
            helper="Inspect CPU and memory usage for this application.">
            <x-slot:actions>
                <a class="button"
                    href="{{ route('server.metrics', ['server_uuid' => $resource->destination->server->uuid]) }}"
                    {{ wireNavigate() }}>
                    Server metrics
                    <x-external-link />
                </a>
            </x-slot:actions>
            <x-callout type="info" title="Metrics are not enabled">
                Enable Sentinel and metrics for this server before collecting application usage data.
            </x-callout>
        </x-application.settings-section>
    @elseif (!str($resource->status)->contains('running'))
        <x-application.settings-section id="metrics-overview-section" title="Metrics"
            helper="Inspect CPU and memory usage for this application.">
            <x-slot:actions>
                <x-status-badge status="Not running" type="neutral" />
            </x-slot:actions>
            <x-empty size="sm" title="Container is not running"
                description="Start the application to begin collecting CPU and memory metrics.">
                <x-slot:icon>
                    <x-reicon name="dashboard" class="size-8" />
                </x-slot:icon>
            </x-empty>
        </x-application.settings-section>
    @else
        <x-application.settings-section id="metrics-overview-section" title="Metrics"
            helper="Inspect recent CPU and memory usage reported by Sentinel.">
            <x-slot:actions>
                <x-status-badge :status="$poll ? 'Live updates' : 'Historical range'"
                    :type="$poll ? 'success' : 'neutral'" />
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

        <x-application.settings-section id="cpu-metrics-section" title="CPU usage"
            helper="Percentage of available CPU capacity used by the application container.">
            <div wire:ignore id="{!! $chartId !!}-cpu" class="min-h-[240px] w-full"></div>

            @script
            <script>
                (() => {
                    checkTheme();

                    const cpuChart = new ApexCharts(document.getElementById('{!! $chartId !!}-cpu'), {
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
                            name: 'CPU',
                            data: [],
                        }],
                        colors: [cpuColor],
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
                            labels: {
                                style: {
                                    colors: textColor,
                                },
                                formatter: value => {
                                    const precision = Math.abs(value) < 1 ? 2 : 1;

                                    return `${Number(value.toFixed(precision))}%`;
                                },
                            },
                        },
                        noData: {
                            text: 'Loading CPU metrics…',
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
                            custom: ({ series, seriesIndex, dataPointIndex, w }) => {
                                const value = series[seriesIndex][dataPointIndex];
                                const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];
                                const date = new Date(timestamp);

                                return `<div class="apexcharts-tooltip-custom">
                                    <div class="apexcharts-tooltip-custom-value">CPU: <span class="apexcharts-tooltip-value-bold">${value}%</span></div>
                                    <div class="apexcharts-tooltip-custom-title">${date.toLocaleString(undefined, { timeZone: 'UTC', hour12: false })} UTC</div>
                                </div>`;
                            },
                        },
                    });

                    cpuChart.render();

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
                                labels: {
                                    style: {
                                        colors: textColor,
                                    },
                                    formatter: value => {
                                        const precision = Math.abs(value) < 1 ? 2 : 1;

                                        return `${Number(value.toFixed(precision))}%`;
                                    },
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
                })();
            </script>
            @endscript
        </x-application.settings-section>

        <x-application.settings-section id="memory-metrics-section" title="Memory usage"
            helper="Memory consumed by the application container, measured in megabytes.">
            <div wire:ignore id="{!! $chartId !!}-memory" class="min-h-[240px] w-full"></div>

            @script
            <script>
                (() => {
                    checkTheme();

                    const memoryChart = new ApexCharts(document.getElementById('{!! $chartId !!}-memory'), {
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
                            name: 'Memory',
                            data: [],
                        }],
                        colors: [ramColor],
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
                            labels: {
                                style: {
                                    colors: textColor,
                                },
                                formatter: value => `${Math.round(value)} MB`,
                            },
                        },
                        noData: {
                            text: 'Loading memory metrics…',
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
                            custom: ({ series, seriesIndex, dataPointIndex, w }) => {
                                const value = series[seriesIndex][dataPointIndex];
                                const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];
                                const date = new Date(timestamp);

                                return `<div class="apexcharts-tooltip-custom">
                                    <div class="apexcharts-tooltip-custom-value">Memory: <span class="apexcharts-tooltip-value-bold">${value} MB</span></div>
                                    <div class="apexcharts-tooltip-custom-title">${date.toLocaleString(undefined, { timeZone: 'UTC', hour12: false })} UTC</div>
                                </div>`;
                            },
                        },
                    });

                    memoryChart.render();

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
                                labels: {
                                    style: {
                                        colors: textColor,
                                    },
                                    formatter: value => `${Math.round(value)} MB`,
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
        </x-application.settings-section>
    @endif
</div>
