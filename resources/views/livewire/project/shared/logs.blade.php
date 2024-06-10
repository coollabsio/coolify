<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Logs | Coolify
    </x-slot>
    <livewire:project.shared.configuration-checker :resource="$resource" />
    @if ($type === 'application')
        <h1>Logs</h1>
        <livewire:project.application.heading :application="$resource" />
        <div class="pt-4">
            <h2>Logs</h2>
            <div class="subtitle">Here you can see the logs of the application.</div>
            <div class="pt-2" wire:loading wire:target="loadContainers">
                Loading containers...
            </div>
            @forelse ($servers as $server)
                <div class="py-2">
                    <h2 wire:loading.remove x-init="$wire.loadContainers({{ $server->id }})">Server: {{ $server->name }}</h2>
                    <div wire:loading.remove wire:target="loadContainers">
                        @forelse (data_get($server,'containers',[]) as $container)
                            <livewire:project.shared.get-logs :server="$server" :resource="$resource" :container="data_get($container, 'Names')" />
                        @empty
                            <div class="pt-2">No containers are not running on server: {{ $server->name }}</div>
                        @endforelse
                    </div>
                </div>
            @empty
                <div>No functional server found for the application.</div>
            @endforelse
        </div>
    @elseif ($type === 'database')
        <h1>Logs</h1>
        <livewire:project.database.heading :database="$resource" />
        <div class="pt-4">
            @forelse ($containers as $container)
                @if ($loop->first)
                    <h2 class="pb-4">Logs</h2>
                @endif
                @if (data_get($servers, '0'))
                    <livewire:project.shared.get-logs :server="data_get($servers, '0')" :resource="$resource" :container="$container" />
                @else
                    <div> No functional server found for the database.</div>
                @endif
            @empty
                <div class="pt-2">No containers are not running.</div>
            @endforelse
        </div>
    @elseif ($type === 'service')
        <div>
            @forelse ($containers as $container)
                @if ($loop->first)
                    <h2 class="pb-4">Logs</h2>
                @endif
                @if (data_get($servers, '0'))
                    <livewire:project.shared.get-logs :server="data_get($servers, '0')" :resource="$resource" :container="$container" />
                @else
                    <div> No functional server found for the service.</div>
                @endif
            @empty
                <div class="pt-2">No containers are not running.</div>
            @endforelse
        </div>
    @endif
    {{-- <section x-data="apex_app" class="container p-5 mx-auto my-20 bg-white drop-shadow-xl rounded-xl">
        <div class="w-full" x-ref="chart"></div>
    </section>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        document.addEventListener("alpine:init", () => {
            Alpine.data("apex_app", () => ({
                data: @js($cpu),
                init() {
                    let chart = new ApexCharts(this.$refs.chart, this.options);
                    chart.render();
                    this.$watch("data", () => {
                        chart.updateOptions(this.options);
                    });
                },

                get options() {
                    return {
                        colors: [function({
                            value,
                            seriesIndex,
                            w
                        }) {
                            if (value < 55) {
                                return '#7E36AF'
                            } else {
                                return '#D9534F'
                            }
                        }, function({
                            value,
                            seriesIndex,
                            w
                        }) {
                            if (value < 111) {
                                return '#7E36AF'
                            } else {
                                return '#D9534F'
                            }
                        }],

                        xaxis: {
                            type: 'datetime'
                        },
                        dataLabels: {
                            enabled: false
                        },
                        series: [{
                            name: "Series name",
                            data: this.data
                        }],
                        tooltip: {
                            enabled: true
                        },
                        chart: {
                            stroke: {
                            curve: 'smooth',
                        },
                            height: 500,
                            width: "100%",
                            type: "line",
                            toolbar: {
                                show: true
                            },
                            animations: {
                                initialAnimation: {
                                    enabled: false
                                }
                            }
                        },
                    };
                }
            }));
        });
    </script> --}}
</div>
