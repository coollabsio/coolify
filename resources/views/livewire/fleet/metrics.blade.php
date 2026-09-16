<div class="flex flex-col gap-6">
    {{-- Header + controls --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold text-black dark:text-fg">Metrics</h1>
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">Resource usage across your servers.</p>
        </div>
        @if ($servers->isNotEmpty())
            <div class="flex items-center gap-2">
                @if ($lastUpdatedAt)
                    <span class="hidden text-[11px] text-neutral-400 sm:inline dark:text-fg-dim">
                        Updated {{ \Illuminate\Support\Carbon::parse($lastUpdatedAt)->diffForHumans() }}
                    </span>
                @endif
                <x-forms.listbox id="serverUuid" :live="true" :options="array_merge(
                    [['value' => '', 'label' => 'All servers']],
                    collect($serverOptions)->map(fn ($name, $uuid) => ['value' => $uuid, 'label' => $name])->values()->all()
                )" />
                <div class="flex rounded-md bg-[var(--coollabs-recessed)] p-0.5">
                    @foreach (['24h', '7d', '30d'] as $r)
                        <button type="button" wire:click="setRange('{{ $r }}')"
                            class="{{ $range === $r ? 'bg-[var(--coollabs-base)] text-black dark:text-fg' : 'text-neutral-500' }} rounded px-2.5 py-1 text-[12px] font-medium">{{ $r }}</button>
                    @endforeach
                </div>
                <button type="button" wire:click="refresh" title="Refresh"
                    class="flex size-8 items-center justify-center rounded-md bg-[var(--coollabs-recessed)] text-neutral-500 hover:text-black dark:hover:text-fg">
                    <x-reicon name="refresh" class="size-4" />
                </button>
            </div>
        @endif
    </div>

    @if ($servers->isEmpty())
        <x-empty title="No server metrics yet" description="Enable metrics on a server to see fleet resource usage here.">
            <x-slot:icon>
                <x-reicon name="cpu" class="size-8" />
            </x-slot:icon>
        </x-empty>
    @else
        {{-- KPI tiles --}}
        <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-neutral-200 sm:grid-cols-3 lg:grid-cols-6 dark:bg-white/[0.07]">
            <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Servers online</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ $kpis['serversOnline'] ?? 0 }}/{{ $kpis['serversTotal'] ?? 0 }}</span>
                @if (($kpis['needsAttention'] ?? 0) > 0)
                    <span class="mt-auto pt-3 text-[11px] text-orange-500 dark:text-warning">{{ $kpis['needsAttention'] }} needs attention</span>
                @endif
            </div>
            <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Fleet CPU @if ($kpis['cpuApproximate'] ?? false)<span title="Averaged across servers; not core-weighted">~</span>@endif</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ round($kpis['cpuAvg'] ?? 0) }}%</span>
                <span class="mt-auto pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">@if ($kpis['cpuBusiest'] ?? null){{ $kpis['cpuBusiest']['name'] }}: {{ round($kpis['cpuBusiest']['percent']) }}%@endif</span>
            </div>
            <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Fleet memory</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ round($kpis['memPercent'] ?? 0) }}%</span>
                <span class="mt-auto pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">{{ formatBytes($kpis['memUsed'] ?? 0) }} / {{ formatBytes($kpis['memTotal'] ?? 0) }}</span>
            </div>
            <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Fleet disk</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ round($kpis['diskPercent'] ?? 0) }}%</span>
                <span class="mt-auto pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">{{ formatBytes($kpis['diskUsed'] ?? 0) }} / {{ formatBytes($kpis['diskTotal'] ?? 0) }}</span>
            </div>
            <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Fleet network</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ formatBytes(($kpis['netRx'] ?? 0) + ($kpis['netTx'] ?? 0)) }}/s</span>
                <span class="mt-auto pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">rx {{ formatBytes($kpis['netRx'] ?? 0) }}/s tx {{ formatBytes($kpis['netTx'] ?? 0) }}/s</span>
            </div>
            <div class="col-span-2 flex flex-col bg-[var(--coollabs-base)] px-4 py-3 sm:col-span-1">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Containers</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ compactNumber($kpis['containers'] ?? 0) }}</span>
            </div>
        </div>

        {{-- Trend charts --}}
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach (['cpu' => 'CPU %', 'memory' => 'Memory used', 'network' => 'Network', 'load' => 'Load average', 'disk' => 'Disk %'] as $key => $label)
                <x-application.settings-section :title="$label">
                    <div wire:ignore>
                        <div id="fleet-chart-{{ $key }}" class="min-h-[240px]"></div>
                    </div>
                </x-application.settings-section>
            @endforeach
        </div>

        {{-- Servers table --}}
        <x-application.settings-section title="Servers" flush>
            <table class="data-table">
                <thead>
                    <tr class="data-table-header">
                        <th>Server</th><th>CPU</th><th>Memory</th><th>Disk</th><th>Load</th><th>Network</th><th>Containers</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (collect($serverRows)->sortByDesc('cpu') as $row)
                        <tr class="data-table-row">
                            <td>
                                <a href="{{ route('server.metrics', ['server_uuid' => $row['uuid']]) }}" class="hover:underline">{{ $row['name'] }}</a>
                                @unless ($row['online'])
                                    <x-status-badge status="Offline" type="warning" />
                                @endunless
                            </td>
                            <td>@include('livewire.fleet._usage-cell', ['percent' => $row['cpu'], 'kind' => 'cpu'])</td>
                            <td>@include('livewire.fleet._usage-cell', ['percent' => ($row['memTotal'] ?? 0) ? round($row['memUsed'] / $row['memTotal'] * 100, 1) : null, 'kind' => 'memory'])</td>
                            <td>@include('livewire.fleet._usage-cell', ['percent' => $row['diskPercent'], 'kind' => 'disk'])</td>
                            <td class="tabular-nums">{{ $row['load1'] === null ? '-' : number_format($row['load1'], 2) }}</td>
                            <td class="tabular-nums">{{ $row['netRx'] === null ? '-' : formatBytes($row['netRx'] + $row['netTx']).'/s' }}</td>
                            <td class="tabular-nums">{{ $row['containers'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-application.settings-section>

        {{-- Hottest containers --}}
        <x-application.settings-section title="Hottest containers" flush>
            <x-slot:actions>
                <div class="flex rounded-md bg-[var(--coollabs-recessed)] p-0.5">
                    @foreach (['cpu' => 'CPU', 'memory' => 'Memory', 'disk' => 'Disk', 'network' => 'Network'] as $m => $mlabel)
                        <button type="button" wire:click="$set('containerMetric', '{{ $m }}')"
                            class="{{ $containerMetric === $m ? 'bg-[var(--coollabs-base)] text-black dark:text-fg' : 'text-neutral-500' }} rounded px-2.5 py-1 text-[12px] font-medium">{{ $mlabel }}</button>
                    @endforeach
                </div>
            </x-slot:actions>
            @if (empty($topContainers))
                <x-empty size="sm" title="No container metrics" description="Container metrics need a Sentinel build with the bulk endpoints." />
            @else
                <table class="data-table">
                    <thead><tr class="data-table-header"><th>Container</th><th>Server</th><th>{{ ucfirst($containerMetric) }}</th></tr></thead>
                    <tbody>
                        @foreach ($topContainers as $c)
                            <tr class="data-table-row">
                                <td>@if ($c['link'])<a href="{{ $c['link'] }}" class="hover:underline">{{ $c['name'] }}</a>@else{{ $c['name'] }}@endif</td>
                                <td class="text-neutral-500 dark:text-fg-dim">{{ $c['server'] }}</td>
                                <td class="tabular-nums">
                                    @switch($containerMetric)
                                        @case('memory'){{ formatBytes($c['memUsed'] ?? 0) }}@break
                                        @case('disk'){{ formatBytes($c['diskBytes'] ?? 0) }}@break
                                        @case('network'){{ formatBytes($c['net'] ?? 0) }}/s@break
                                        @default{{ round($c['cpu'] ?? 0) }}%
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-application.settings-section>
    @endif

    @script
        <script>
            (() => {
                if (typeof ApexCharts === 'undefined') { return; }
                if (typeof checkTheme === 'function') { checkTheme(); }
                const axisColor = (typeof textColor !== 'undefined') ? textColor : 'rgba(128,128,128,0.7)';

                const fmtPercent = v => `${Number(Number(v).toFixed(1))}%`;
                const fmtNumber = v => Number(v).toLocaleString();
                const fmtBytes = v => {
                    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                    let amount = Number(v) || 0;
                    let unit = 0;
                    while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit++; }
                    return `${Number(amount.toFixed(unit === 0 ? 0 : 2))} ${units[unit]}`;
                };
                const fmtRate = v => `${fmtBytes(v)}/s`;

                const baseOptions = (colors, formatter) => ({
                    chart: { height: 240, type: 'area', toolbar: { show: false }, zoom: { enabled: false }, background: 'transparent' },
                    series: [],
                    colors,
                    stroke: { curve: 'smooth', width: 2 },
                    fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.02, stops: [0, 90, 100] } },
                    dataLabels: { enabled: false },
                    grid: { borderColor: 'rgba(128, 128, 128, 0.14)', strokeDashArray: 4 },
                    legend: { show: false },
                    xaxis: { type: 'datetime', labels: { datetimeUTC: true, style: { colors: axisColor } } },
                    yaxis: { min: 0, max: max => max > 0 ? max * 1.2 : 1, forceNiceScale: true, tickAmount: 4, labels: { style: { colors: axisColor }, formatter } },
                    noData: { text: 'No data for this range', style: { color: axisColor } },
                    tooltip: { x: { format: 'yyyy-MM-dd HH:mm' } },
                });

                const specs = {
                    cpu: { colors: ['#3b82f6'], formatter: fmtPercent },
                    memory: { colors: ['#8b5cf6'], formatter: fmtBytes },
                    network: { colors: ['#10b981', '#f59e0b'], formatter: fmtRate },
                    load: { colors: ['#14b8a6'], formatter: fmtNumber },
                    disk: { colors: ['#ef4444'], formatter: fmtPercent },
                };

                const charts = {};
                for (const [key, spec] of Object.entries(specs)) {
                    const el = document.getElementById(`fleet-chart-${key}`);
                    if (! el) { continue; }
                    const chart = new ApexCharts(el, baseOptions(spec.colors, spec.formatter));
                    chart.render();
                    charts[key] = chart;
                }

                Livewire.on('refreshChartData-{!! $chartId !!}', payload => {
                    if (typeof checkTheme === 'function') { checkTheme(); }
                    const data = Array.isArray(payload) ? payload[0] : payload;
                    if (! data) { return; }

                    charts.cpu?.updateSeries([{ name: 'CPU', data: data.cpu || [] }]);
                    charts.memory?.updateSeries([{ name: 'Memory', data: data.memory || [] }]);
                    charts.disk?.updateSeries([{ name: 'Disk', data: data.disk || [] }]);
                    charts.load?.updateSeries([{ name: 'Load', data: data.load || [] }]);
                    charts.network?.updateSeries([
                        { name: 'RX', data: data.networkRx || [] },
                        { name: 'TX', data: data.networkTx || [] },
                    ]);
                });
            })();
        </script>
    @endscript
</div>
