<div>
    <div class="flex items-center gap-2 ">
        <h2>Metrics</h2>
    </div>
    <div class="pb-4">Basic metrics for your container.</div>
    @if ($resource->getMorphClass() === 'App\Models\Application' && $resource->build_pack === 'dockercompose')
        <div class="alert alert-warning">Metrics are not available for Docker Compose applications yet!</div>
    @elseif(!$resource->destination->server->isMetricsEnabled())
        <div class="alert alert-warning">Metrics are only available for servers with Sentinel & Metrics enabled!</div>
        <div> Go to <a class="underline dark:text-white"
                wire:navigate href="{{ route('server.show', $resource->destination->server->uuid) }}">Server settings</a> to
            enable
            it.</div>
    @else
        @if (!str($resource->status)->contains('running'))
            <div class="alert alert-warning">Metrics are only available when this resource is running!</div>
        @else
            <x-forms.select label="Interval" wire:change="setInterval" id="interval">
                <option value="5">5 minutes (live)</option>
                <option value="10">10 minutes (live)</option>
                <option value="30">30 minutes</option>
                <option value="60">1 hour</option>
                <option value="720">12 hours</option>
                <option value="10080">1 week</option>
                <option value="43200">30 days</option>
            </x-forms.select>
            <div @if ($poll) wire:poll.5000ms='pollData' @endif x-init="$wire.loadData()"
                class="pt-5">
                <h4>CPU (%)</h4>
                <div wire:ignore id="{!! $chartId !!}-cpu"></div>

                <script>
                    checkTheme();
                    const optionsServerCpu = {
                        stroke: {
                            curve: 'straight',
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
                                enabled: false,
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
                        colors: [baseColor],
                        xaxis: {
                            type: 'datetime',
                        },
                        series: [{
                            name: "CPU %",
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
                            }
                        },
                        legend: {
                            show: false
                        }
                    }
                    const serverCpuChart = new ApexCharts(document.getElementById(`{!! $chartId !!}-cpu`), optionsServerCpu);
                    serverCpuChart.render();
                    document.addEventListener('livewire:init', () => {
                        Livewire.on('refreshChartData-{!! $chartId !!}-cpu', (chartData) => {
                            checkTheme();
                            serverCpuChart.updateOptions({
                                series: [{
                                    data: chartData[0].seriesData,
                                }],
                                colors: [baseColor],
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
                        });
                    });
                </script>

                <h3>Memory (MB)</h3>
                <div wire:ignore id="{!! $chartId !!}-memory"></div>

                <script>
                    checkTheme();
                    const optionsServerMemory = {
                        stroke: {
                            curve: 'straight',
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
                                enabled: false,
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
                        colors: [baseColor],
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
                            name: "Memory (MB)",
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
                            }
                        },
                        legend: {
                            show: false
                        }
                    }
                    const serverMemoryChart = new ApexCharts(document.getElementById(`{!! $chartId !!}-memory`),
                        optionsServerMemory);
                    serverMemoryChart.render();
                    document.addEventListener('livewire:init', () => {
                        Livewire.on('refreshChartData-{!! $chartId !!}-memory', (chartData) => {
                            checkTheme();
                            serverMemoryChart.updateOptions({
                                series: [{
                                    data: chartData[0].seriesData,
                                }],
                                colors: [baseColor],
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
                        });
                    });
                </script>
            </div>
        @endif
    @endif
</div>
