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
                            class="{{ $range === $r ? 'bg-[var(--coollabs-base)] text-black shadow-sm dark:text-fg' : 'text-neutral-500 hover:text-black dark:hover:text-fg' }} rounded px-2.5 py-1 text-[12px] font-medium transition-colors">{{ $r }}</button>
                    @endforeach
                </div>
                <button type="button" wire:click="refresh" title="Refresh"
                    class="flex size-8 items-center justify-center rounded-md bg-[var(--coollabs-recessed)] text-neutral-500 transition-colors hover:text-black dark:hover:text-fg">
                    <x-reicon name="refresh" class="size-4" wire:loading.class="animate-spin" wire:target="refresh" />
                </button>
            </div>
        @endif
    </div>

    @if ($servers->isEmpty())
        <x-empty title="No server metrics yet" description="Enable metrics on a server to see fleet resource usage here.">
            <x-slot:icon>
                <x-reicon name="graph" class="size-8" />
            </x-slot:icon>
        </x-empty>
    @else
        {{-- KPI tiles --}}
        <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-neutral-200 sm:grid-cols-3 lg:grid-cols-6 dark:bg-white/[0.07]">
            <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Servers online</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ $kpis['serversOnline'] ?? 0 }}/{{ $kpis['serversTotal'] ?? 0 }}</span>
                @if (($kpis['needsAttention'] ?? 0) > 0)
                    <span class="mt-auto pt-3 text-[11px] font-medium text-orange-500 dark:text-warning">{{ $kpis['needsAttention'] }} need attention</span>
                @else
                    <span class="mt-auto pt-3 text-[11px] text-emerald-600 dark:text-emerald-400">All healthy</span>
                @endif
            </div>
            <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Fleet CPU @if ($kpis['cpuApproximate'] ?? false)<span class="cursor-help" title="Averaged across servers; not core-weighted">~</span>@endif</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ round($kpis['cpuAvg'] ?? 0) }}%</span>
                <span class="mt-auto pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">@if ($kpis['cpuBusiest'] ?? null)Busiest: {{ $kpis['cpuBusiest']['name'] }} {{ round($kpis['cpuBusiest']['percent']) }}%@endif</span>
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
                <span class="mt-auto pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">rx {{ formatBytes($kpis['netRx'] ?? 0) }}/s · tx {{ formatBytes($kpis['netTx'] ?? 0) }}/s</span>
            </div>
            <div class="col-span-2 flex flex-col bg-[var(--coollabs-base)] px-4 py-3 sm:col-span-1">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Containers</span>
                <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ compactNumber($kpis['containers'] ?? 0) }}</span>
                <span class="mt-auto pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">running</span>
            </div>
        </div>

        {{-- Trend charts --}}
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach (['cpu' => 'CPU', 'memory' => 'Memory used', 'network' => 'Network throughput', 'load' => 'Load average', 'disk' => 'Disk usage'] as $key => $label)
                <x-application.settings-section :title="$label">
                    <div wire:ignore>
                        <div id="fleet-chart-{{ $key }}" class="min-h-[240px] w-full"></div>
                    </div>
                </x-application.settings-section>
            @endforeach
        </div>

        {{-- Servers table --}}
        <x-application.settings-section title="Servers" flush>
            <div class="data-table">
                <div class="data-table-header fleet-servers-table-grid">
                    <span>Server</span>
                    <span>CPU</span>
                    <span>Memory</span>
                    <span>Disk</span>
                    <span>Load</span>
                    <span>Network</span>
                    <span class="text-right">Containers</span>
                </div>
                @foreach (collect($serverRows)->sortByDesc(fn ($r) => $r['cpu'] ?? -1) as $row)
                    <div class="data-table-row fleet-servers-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                        <div class="flex min-w-0 items-center gap-2">
                            <a href="{{ route('server.metrics', ['server_uuid' => $row['uuid']]) }}"
                                class="min-w-0 truncate text-[13px] font-medium text-black hover:underline dark:text-fg" {{ wireNavigate() }}>{{ $row['name'] }}</a>
                            @unless ($row['online'])
                                <span class="table-badge table-badge-warning shrink-0">Offline</span>
                            @endunless
                        </div>
                        <div>@include('livewire.fleet._usage-cell', ['percent' => $row['cpu'], 'kind' => 'cpu'])</div>
                        <div>@include('livewire.fleet._usage-cell', ['percent' => ($row['memTotal'] ?? 0) ? round($row['memUsed'] / $row['memTotal'] * 100, 1) : null, 'kind' => 'memory'])</div>
                        <div>@include('livewire.fleet._usage-cell', ['percent' => $row['diskPercent'], 'kind' => 'disk'])</div>
                        <div class="text-[13px] tabular-nums text-neutral-600 dark:text-fg-dim">{{ $row['load1'] === null ? '-' : number_format($row['load1'], 2) }}</div>
                        <div class="text-[13px] tabular-nums text-neutral-600 dark:text-fg-dim">{{ $row['netRx'] === null ? '-' : formatBytes($row['netRx'] + $row['netTx']).'/s' }}</div>
                        <div class="text-right text-[13px] tabular-nums text-neutral-600 dark:text-fg-dim">{{ $row['containers'] ?? '-' }}</div>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>

        {{-- Hottest containers --}}
        <x-application.settings-section title="Hottest containers" flush>
            <x-slot:actions>
                <div class="flex rounded-md bg-[var(--coollabs-recessed)] p-0.5">
                    @foreach (['cpu' => 'CPU', 'memory' => 'Memory', 'disk' => 'Disk', 'network' => 'Network'] as $m => $mlabel)
                        <button type="button" wire:click="$set('containerMetric', '{{ $m }}')"
                            class="{{ $containerMetric === $m ? 'bg-[var(--coollabs-base)] text-black shadow-sm dark:text-fg' : 'text-neutral-500 hover:text-black dark:hover:text-fg' }} rounded px-2.5 py-1 text-[12px] font-medium transition-colors">{{ $mlabel }}</button>
                    @endforeach
                </div>
            </x-slot:actions>
            @if (empty($topContainers))
                <div class="p-4">
                    <x-empty size="sm" title="No container metrics" description="Container metrics need a Sentinel build with the bulk endpoints." />
                </div>
            @else
                <div class="data-table">
                    <div class="data-table-header fleet-containers-table-grid">
                        <span>Container</span>
                        <span>Server</span>
                        <span class="text-right">{{ ucfirst($containerMetric) }}</span>
                    </div>
                    @foreach ($topContainers as $c)
                        <div class="data-table-row fleet-containers-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                            <div class="min-w-0 truncate text-[13px] font-medium text-black dark:text-fg">
                                @if ($c['link'])
                                    <a href="{{ $c['link'] }}" class="hover:underline" {{ wireNavigate() }}>{{ $c['name'] }}</a>
                                @else
                                    {{ $c['name'] }}
                                @endif
                            </div>
                            <div class="min-w-0 truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $c['server'] }}</div>
                            <div class="text-right text-[13px] tabular-nums text-black dark:text-fg">
                                @switch($containerMetric)
                                    @case('memory'){{ formatBytes($c['memUsed'] ?? 0) }}@break
                                    @case('disk'){{ formatBytes($c['diskBytes'] ?? 0) }}@break
                                    @case('network'){{ formatBytes($c['net'] ?? 0) }}/s@break
                                    @default{{ round($c['cpu'] ?? 0) }}%
                                @endswitch
                            </div>
                        </div>
                    @endforeach
                </div>
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
                const fmtNumber = v => Number(Number(v).toFixed(2)).toLocaleString();
                const fmtBytes = v => {
                    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                    let amount = Number(v) || 0;
                    let unit = 0;
                    while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit++; }
                    return `${Number(amount.toFixed(unit === 0 ? 0 : 2))} ${units[unit]}`;
                };
                const fmtRate = v => `${fmtBytes(v)}/s`;

                const baseOptions = (series, colors, formatter) => ({
                    chart: { height: 240, type: 'area', toolbar: { show: false }, zoom: { enabled: false }, background: 'transparent', animations: { enabled: true } },
                    series,
                    colors,
                    stroke: { curve: 'smooth', width: 2 },
                    fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.02, stops: [0, 90, 100] } },
                    dataLabels: { enabled: false },
                    grid: { borderColor: 'rgba(128, 128, 128, 0.14)', strokeDashArray: 4 },
                    legend: { show: series.length > 1, labels: { colors: axisColor } },
                    xaxis: { type: 'datetime', labels: { datetimeUTC: true, style: { colors: axisColor } } },
                    yaxis: { min: 0, max: max => max > 0 ? max * 1.2 : 1, forceNiceScale: true, tickAmount: 4, labels: { style: { colors: axisColor }, formatter } },
                    noData: { text: 'No data for this range', style: { color: axisColor } },
                    tooltip: { x: { format: 'yyyy-MM-dd HH:mm' } },
                });

                const initial = @js($chartData);

                const build = data => ({
                    cpu: { series: [{ name: 'CPU %', data: data.cpu || [] }], colors: ['#3b82f6'], formatter: fmtPercent },
                    memory: { series: [{ name: 'Memory', data: data.memory || [] }], colors: ['#8b5cf6'], formatter: fmtBytes },
                    network: { series: [{ name: 'RX', data: data.networkRx || [] }, { name: 'TX', data: data.networkTx || [] }], colors: ['#10b981', '#f59e0b'], formatter: fmtRate },
                    load: { series: [{ name: 'Load', data: data.load || [] }], colors: ['#14b8a6'], formatter: fmtNumber },
                    disk: { series: [{ name: 'Disk %', data: data.disk || [] }], colors: ['#ef4444'], formatter: fmtPercent },
                });

                const charts = {};
                const specs = build(initial || {});
                for (const [key, spec] of Object.entries(specs)) {
                    const el = document.getElementById(`fleet-chart-${key}`);
                    if (! el) { continue; }
                    const chart = new ApexCharts(el, baseOptions(spec.series, spec.colors, spec.formatter));
                    chart.render();
                    charts[key] = chart;
                }

                Livewire.on('refreshChartData-{!! $chartId !!}', payload => {
                    if (typeof checkTheme === 'function') { checkTheme(); }
                    const data = Array.isArray(payload) ? payload[0] : payload;
                    if (! data) { return; }
                    const next = build(data);
                    for (const [key, chart] of Object.entries(charts)) {
                        chart.updateSeries(next[key].series);
                    }
                });
            })();
        </script>
    @endscript
</div>
