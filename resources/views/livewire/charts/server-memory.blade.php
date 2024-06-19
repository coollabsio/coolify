<div @if ($poll) wire:poll.5000ms='pollData' @endif>
    <h3>Memory</h3>
    <x-forms.select label="Interval" wire:change="setInterval" id="interval">
        <option value="5">5 minutes (live)</option>
        <option value="10">10 minutes (live)</option>
        <option value="30">30 minutes</option>
        <option value="60">1 hour</option>
        <option value="720">12 hours</option>
        <option value="10080">1 week</option>
        <option value="43200">30 days</option>
    </x-forms.select>
    <div wire:ignore id="{!! $chartId !!}"></div>

    <script>
        checkTheme();
        const optionsServerMemory = {
            stroke: {
                curve: 'straight',
            },
            chart: {
                height: '150px',
                id: '{!! $chartId !!}',
                type: 'area',
                toolbar: {
                    show: false,
                    tools: {
                        download: true,
                        selection: false,
                        zoom: false,
                        zoomin: false,
                        zoomout: false,
                        pan: false,
                        reset: false
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
                data: '{!! $data !!}'
            }],
            tooltip: {
                enabled: false,
            },
            legend: {
                show: false
            }
        }
        const serverMemoryChart = new ApexCharts(document.getElementById(`{!! $chartId !!}`), optionsServerMemory);
        serverMemoryChart.render();
        document.addEventListener('livewire:init', () => {
            Livewire.on('refreshChartData-{!! $chartId !!}', (chartData) => {
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
