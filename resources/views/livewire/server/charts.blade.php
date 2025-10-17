<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Metrics | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="metrics" />
        <div class="w-full">
            <h2>Metrics</h2>
            <div class="pb-4">Basic metrics for your server.</div>
            @if ($server->isMetricsEnabled())
                <div @if ($poll) wire:poll.5000ms='pollData' @endif x-data="{
                    destroy() {
                        // Remove Livewire event listeners
                        if (window.cpuChartHandler_{!! $chartId !!}) {
                            Livewire.off('refreshChartData-{!! $chartId !!}-cpu', window.cpuChartHandler_{!! $chartId !!});
                            window.cpuChartHandler_{!! $chartId !!} = null;
                        }
                        if (window.memoryChartHandler_{!! $chartId !!}) {
                            Livewire.off('refreshChartData-{!! $chartId !!}-memory', window.memoryChartHandler_{!! $chartId !!});
                            window.memoryChartHandler_{!! $chartId !!} = null;
                        }
                        // Destroy ApexCharts instances
                        if (window.serverCpuChart_{!! $chartId !!}) {
                            window.serverCpuChart_{!! $chartId !!}.destroy();
                            window.serverCpuChart_{!! $chartId !!} = null;
                        }
                        if (window.serverMemoryChart_{!! $chartId !!}) {
                            window.serverMemoryChart_{!! $chartId !!}.destroy();
                            window.serverMemoryChart_{!! $chartId !!} = null;
                        }
                    }
                }" x-init="$wire.loadData()">
                    <x-forms.select label="Interval" wire:change="setInterval" id="interval">
                        <option value="5">5 minutes (live)</option>
                        <option value="10">10 minutes (live)</option>
                        <option value="30">30 minutes</option>
                        <option value="60">1 hour</option>
                        <option value="720">12 hours</option>
                        <option value="10080">1 week</option>
                        <option value="43200">30 days</option>
                    </x-forms.select>
                    <h4 class="pt-4">CPU Usage</h4>
                    <div wire:ignore id="{!! $chartId !!}-cpu"></div>

                    <script>
                        checkTheme();
                        const optionsServerCpu = {
                            stroke: {
                                curve: 'straight',
                                width: 2,
                            },
                            chart: {
                                height: '150px',
                                id: '{!! $chartId !!}-cpu',
                                type: 'area',
                                toolbar: {
                                    show: true,
                                    tools: {
                                        download: false,
                                        selection: false,
                                        zoom: true,
                                        zoomin: false,
                                        zoomout: false,
                                        pan: false,
                                        reset: true
                                    },
                                },
                                animations: {
                                    enabled: true,
                                },
                            },
                            fill: {
                                type: 'gradient',
                            },
                            dataLabels: {
                                enabled: false,
                                offsetY: -10,
                                style: {
                                    colors: ['#FCD452'],
                                },
                                background: {
                                    enabled: false,
                                }
                            },
                             grid: {
                                 show: true,
                                 borderColor: '',
                             },
                             colors: [cpuColor],
                             xaxis: {
                                 type: 'datetime',
                             },
                             series: [{
                                 name: 'CPU %',
                                data: []
                            }],
                            noData: {
                                text: 'Loading...',
                                style: {
                                    color: textColor,
                                }
                            },
                             tooltip: {
                                 enabled: true,
                                 marker: {
                                     show: false,
                                 },
                                 custom: function({ series, seriesIndex, dataPointIndex, w }) {
                                     const value = series[seriesIndex][dataPointIndex];
                                     const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];
                                     const date = new Date(timestamp);
                                     const timeString = String(date.getUTCHours()).padStart(2, '0') + ':' +
                                         String(date.getUTCMinutes()).padStart(2, '0') + ':' +
                                         String(date.getUTCSeconds()).padStart(2, '0') + ', ' +
                                         date.getUTCFullYear() + '-' +
                                         String(date.getUTCMonth() + 1).padStart(2, '0') + '-' +
                                         String(date.getUTCDate()).padStart(2, '0');
                                     return '<div class="apexcharts-tooltip-custom">' +
                                         '<div class="apexcharts-tooltip-custom-value">CPU: <span class="apexcharts-tooltip-value-bold">' + value + '%</span></div>' +
                                         '<div class="apexcharts-tooltip-custom-title">' + timeString + '</div>' +
                                         '</div>';
                                 }
                             },
                            legend: {
                                show: false
                            }
                        }
                        window.serverCpuChart_{!! $chartId !!} = new ApexCharts(document.getElementById(`{!! $chartId !!}-cpu`),
                            optionsServerCpu);
                        window.serverCpuChart_{!! $chartId !!}.render();

                        const cpuChartHandler = (chartData) => {
                            checkTheme();
                            if (window.serverCpuChart_{!! $chartId !!}) {
                                window.serverCpuChart_{!! $chartId !!}.updateOptions({
                                     series: [{
                                         data: chartData[0].seriesData,
                                     }],
                                     colors: [cpuColor],
                                    xaxis: {
                                        type: 'datetime',
                                        labels: {
                                            show: true,
                                            style: {
                                                colors: textColor,
                                            }
                                        }
                                    },
                                     yaxis: {
                                         show: true,
                                         labels: {
                                             show: true,
                                             style: {
                                                 colors: textColor,
                                             },
                                             formatter: function(value) {
                                                 return Math.round(value) + ' %';
                                             }
                                         }
                                     },
                                    noData: {
                                        text: 'Loading...',
                                        style: {
                                            color: textColor,
                                        }
                                    }
                                });
                            }
                        };

                        document.addEventListener('livewire:init', () => {
                            Livewire.on('refreshChartData-{!! $chartId !!}-cpu', cpuChartHandler);
                        });
                        window.cpuChartHandler_{!! $chartId !!} = cpuChartHandler;
                    </script>

                    <div>
                        <h4>Memory Usage</h4>
                        <div wire:ignore id="{!! $chartId !!}-memory"></div>

                        <script>
                            checkTheme();
                            const optionsServerMemory = {
                                stroke: {
                                    curve: 'straight',
                                    width: 2,
                                },
                                chart: {
                                    height: '150px',
                                    id: '{!! $chartId !!}-memory',
                                    type: 'area',
                                    toolbar: {
                                        show: true,
                                        tools: {
                                            download: false,
                                            selection: false,
                                            zoom: true,
                                            zoomin: false,
                                            zoomout: false,
                                            pan: false,
                                            reset: true
                                        },
                                    },
                                    animations: {
                                        enabled: true,
                                    },
                                },
                                fill: {
                                    type: 'gradient',
                                },
                                dataLabels: {
                                    enabled: false,
                                    offsetY: -10,
                                    style: {
                                        colors: ['#FCD452'],
                                    },
                                    background: {
                                        enabled: false,
                                    }
                                },
                                 grid: {
                                     show: true,
                                     borderColor: '',
                                 },
                                 colors: [ramColor],
                                 xaxis: {
                                     type: 'datetime',
                                     labels: {
                                         show: true,
                                        style: {
                                            colors: textColor,
                                        }
                                    }
                                },
                                series: [{
                                    name: "Memory (%)",
                                    data: []
                                }],
                                noData: {
                                    text: 'Loading...',
                                    style: {
                                        color: textColor,
                                    }
                                },
                                 tooltip: {
                                     enabled: true,
                                     marker: {
                                         show: false,
                                     },
                                     custom: function({ series, seriesIndex, dataPointIndex, w }) {
                                         const value = series[seriesIndex][dataPointIndex];
                                         const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];
                                         const date = new Date(timestamp);
                                         const timeString = String(date.getUTCHours()).padStart(2, '0') + ':' +
                                             String(date.getUTCMinutes()).padStart(2, '0') + ':' +
                                             String(date.getUTCSeconds()).padStart(2, '0') + ', ' +
                                             date.getUTCFullYear() + '-' +
                                             String(date.getUTCMonth() + 1).padStart(2, '0') + '-' +
                                             String(date.getUTCDate()).padStart(2, '0');
                                         return '<div class="apexcharts-tooltip-custom">' +
                                             '<div class="apexcharts-tooltip-custom-value">Memory: <span class="apexcharts-tooltip-value-bold">' + value + '%</span></div>' +
                                             '<div class="apexcharts-tooltip-custom-title">' + timeString + '</div>' +
                                             '</div>';
                                     }
                                 },
                                legend: {
                                    show: false
                                }
                            }
                            window.serverMemoryChart_{!! $chartId !!} = new ApexCharts(document.getElementById(`{!! $chartId !!}-memory`),
                                optionsServerMemory);
                            window.serverMemoryChart_{!! $chartId !!}.render();

                            const memoryChartHandler = (chartData) => {
                                checkTheme();
                                if (window.serverMemoryChart_{!! $chartId !!}) {
                                    window.serverMemoryChart_{!! $chartId !!}.updateOptions({
                                         series: [{
                                             data: chartData[0].seriesData,
                                         }],
                                         colors: [ramColor],
                                        xaxis: {
                                            type: 'datetime',
                                            labels: {
                                                show: true,
                                                style: {
                                                    colors: textColor,
                                                }
                                            }
                                        },
                                         yaxis: {
                                             min: 0,
                                             show: true,
                                             labels: {
                                                 show: true,
                                                 style: {
                                                     colors: textColor,
                                                 },
                                                  formatter: function(value) {
                                                      return Math.round(value) + ' %';
                                                  }
                                             }
                                         },
                                        noData: {
                                            text: 'Loading...',
                                            style: {
                                                color: textColor,
                                            }
                                        }
                                    });
                                }
                            };

                            document.addEventListener('livewire:init', () => {
                                Livewire.on('refreshChartData-{!! $chartId !!}-memory', memoryChartHandler);
                            });
                            window.memoryChartHandler_{!! $chartId !!} = memoryChartHandler;
                        </script>

                    </div>
                </div>
            @else
                <div>Metrics are disabled for this server. Enable them in <a class="underline dark:text-white"
                        href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}">General</a> settings.</div>
            @endif
        </div>
    </div>
</div>
