<div>
    <div class="flex items-center gap-2">
        <h2>{{ __('metrics.title') }}</h2>
    </div>
    <div class="pb-4">{{ __('metrics.description') }}</div>
    <div>
        @if ($resource->getMorphClass() === 'App\Models\Application' && $resource->build_pack === 'dockercompose')
            <div class="alert alert-warning">{{ __('metrics.not_available_compose') }}</div>
        @elseif(!$resource->destination->server->isMetricsEnabled())
            <div class="alert alert-warning">{{ __('metrics.not_available_no_metrics') }}</div>
            <div>{{ __('metrics.go_to_settings_prefix') }}<a class="underline dark:text-white" href="{{ route('server.show', $resource->destination->server->uuid) }}" {{ wireNavigate() }}>{{ __('metrics.server_settings_link') }}</a>{{ __('metrics.go_to_settings_suffix') }}</div>
        @else
            @if (!str($resource->status)->contains('running'))
                <div class="alert alert-warning">{{ __('metrics.not_available_stopped') }}</div>
            @else
                <div>
                <x-forms.select label="{{ __('metrics.interval_label') }}" wire:change="setInterval" id="interval">
                <option value="5">{{ __('metrics.interval_5min') }}</option>
                <option value="10">{{ __('metrics.interval_10min') }}</option>
                <option value="30">{{ __('metrics.interval_30min') }}</option>
                <option value="60">{{ __('metrics.interval_1hour') }}</option>
                <option value="720">{{ __('metrics.interval_12hours') }}</option>
                <option value="10080">{{ __('metrics.interval_1week') }}</option>
                <option value="43200">{{ __('metrics.interval_30days') }}</option>
            </x-forms.select>
            <div @if ($poll) wire:poll.5000ms='pollData' @endif x-init="$wire.loadData()"
                class="pt-5">
                <h4>{{ __('metrics.cpu_usage') }}</h4>
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
                              name: "{!! __('metrics.cpu_percent') !!}",
                             data: []
                         }],
                         noData: {
                             text: '{!! __('metrics.loading') !!}',
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
                                     '<div class="apexcharts-tooltip-custom-value">{!! __('metrics.cpu_label') !!} <span class="apexcharts-tooltip-value-bold">' + value + '%</span></div>' +
                                     '<div class="apexcharts-tooltip-custom-title">' + timeString + '</div>' +
                                     '</div>';
                             }
                         },
                         legend: {
                             show: false
                         }
                    }
                     const serverCpuChart = new ApexCharts(document.getElementById(`{!! $chartId !!}-cpu`), optionsServerCpu);
                     serverCpuChart.render();
                     Livewire.on('refreshChartData-{!! $chartId !!}-cpu', (chartData) => {
                         checkTheme();
                          serverCpuChart.updateOptions({
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
                                 text: '{!! __('metrics.loading') !!}',
                                 style: {
                                     color: textColor,
                                 }
                             }
                         });
                     });
                </script>

                <h4>{{ __('metrics.memory_usage') }}</h4>
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
                             name: "{!! __('metrics.memory_mb') !!}",
                             data: []
                         }],
                         noData: {
                             text: '{!! __('metrics.loading') !!}',
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
                                     '<div class="apexcharts-tooltip-custom-value">{!! __('metrics.memory_label') !!} <span class="apexcharts-tooltip-value-bold">' + value + ' MB</span></div>' +
                                     '<div class="apexcharts-tooltip-custom-title">' + timeString + '</div>' +
                                     '</div>';
                             }
                         },
                         legend: {
                             show: false
                         }
                    }
                     const serverMemoryChart = new ApexCharts(document.getElementById(`{!! $chartId !!}-memory`),
                         optionsServerMemory);
                     serverMemoryChart.render();
                     Livewire.on('refreshChartData-{!! $chartId !!}-memory', (chartData) => {
                         checkTheme();
                          serverMemoryChart.updateOptions({
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
                                          return Math.round(value) + ' MB';
                                      }
                                  }
                              },
                             noData: {
                                 text: '{!! __('metrics.loading') !!}',
                                 style: {
                                     color: textColor,
                                 }
                             }
                         });
                     });
                </script>
            </div>
            </div>
        @endif
    @endif
    </div>
</div>
