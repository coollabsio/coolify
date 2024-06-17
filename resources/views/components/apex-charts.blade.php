<div wire:ignore id="{!! $chartId !!}"></div>

<script>
    const options = {
        chart: {
            height: '150px',
            id: '{!! $chartId !!}',
            type: 'area',
            stroke: {
                curve: 'straight',
            },
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

        grid: {
            show: true,
            borderColor: '',
        },
        colors: ['red'],
        xaxis: {
            type: 'datetime',
            labels: {
                show: true,
                style: {
                    colors: '#ffffff',
                }
            }
        },
        yaxis: {
            show: true,
            labels: {
                show: false,
            }
        },
        series: [{
            name: '{!! $seriesName !!}',
            data: '{!! $seriesData !!}'
        }],
        noData: {
            text: 'Loading...'
        },
        tooltip: {
            enabled: false
        },
        legend: {
            show: false
        }
    }
    const chart = new ApexCharts(document.getElementById(`{!! $chartId !!}`), options);
    chart.render();
    document.addEventListener('livewire:init', () => {
        Livewire.on('refreshChartData-{!! $chartId !!}', (chartData) => {
            chart.updateOptions({
                series: [{
                    data: chartData[0].seriesData,
                }],

            });

        });
    });
</script>
