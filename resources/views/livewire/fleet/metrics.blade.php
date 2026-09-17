<?php
$tabButtonBase = 'relative inline-flex h-7 items-center justify-center rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
$tabButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
$tabButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';

// Tiles read "Fleet …" across all servers, but just "CPU / Memory / …" when scoped to one.
$scoped = $serverUuid !== '';

$serverListboxOptions = array_merge(
    [['value' => '', 'label' => 'All servers']],
    collect($serverOptions)->map(fn ($name, $uuid) => ['value' => $uuid, 'label' => $name])->values()->all(),
);
?>
<div class="flex w-full min-w-0 flex-col gap-6">
    <x-slot:title>
        Metrics | Coolify
    </x-slot>

    {{-- Header --}}
    <div class="flex flex-col gap-4">
        <div class="min-w-0">
            <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Metrics</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Resource usage across every server, reported by Sentinel.
            </p>
        </div>

        @if ($servers->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full transition-opacity sm:w-52"
                    wire:loading.class="pointer-events-none opacity-60" wire:target="serverUuid">
                    <x-forms.listbox id="serverUuid" live :options="$serverListboxOptions" placeholder="All servers" />
                    <div class="absolute inset-0 hidden items-center justify-center rounded-lg bg-white/70 dark:bg-base/70"
                        wire:loading.flex wire:target="serverUuid">
                        <x-loading compact aria-label="Loading metrics" />
                    </div>
                </div>

                <div class="flex items-center gap-2 sm:ml-auto">
                    @if ($range === '24h')
                        <div x-data="{ live: $wire.entangle('live').live }"
                            x-init="
                                const stored = localStorage.getItem('metrics-live');
                                if (stored === '0') { live = false; }
                                if (stored === '1') { live = true; }
                                $watch('live', value => localStorage.setItem('metrics-live', value ? '1' : '0'));
                            ">
                            <button type="button" @click="live = !live" :aria-pressed="live ? 'true' : 'false'"
                                title="Toggle realtime refresh (updates every 60s)"
                                class="inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium ring-1 transition-colors"
                                :class="live
                                    ? 'bg-white text-black shadow-sm ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]'
                                    : 'text-neutral-500 ring-neutral-200 hover:text-black dark:text-fg-faint dark:ring-white/[0.08] dark:hover:text-fg'">
                                <span class="relative flex h-1.5 w-1.5 shrink-0" aria-hidden="true">
                                    <span x-show="live" class="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping"></span>
                                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full" :class="live ? 'bg-emerald-500' : 'bg-neutral-400 dark:bg-white/40'"></span>
                                </span>
                                <span>Live Refresh</span>
                            </button>
                        </div>
                    @endif
                    <div class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]">
                        @foreach (['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'] as $value => $label)
                            <button type="button" wire:click="setRange('{{ $value }}')"
                                wire:loading.attr="disabled" wire:target="setRange"
                                @class([$tabButtonBase, $range === $value ? $tabButtonActive : $tabButtonInactive])>
                                <span wire:loading.class="invisible" wire:target="setRange('{{ $value }}')">{{ $label }}</span>
                                <x-loading compact class="absolute" wire:loading wire:target="setRange('{{ $value }}')" aria-label="Loading metrics" />
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($this->isLivePollable())
        <div wire:poll.60s="loadData" class="hidden"></div>
    @endif

    @if ($servers->isEmpty())
        <x-empty size="sm" title="No server metrics yet"
            description="Enable metrics on a server to see fleet resource usage here."
            icon-name="graph">
            <x-slot:contents>
                <a class="button" href="{{ route('server.index') }}" {{ wireNavigate() }}>
                    View servers
                </a>
            </x-slot:contents>
        </x-empty>
    @else
        {{-- Overview KPIs --}}
        <x-application.settings-section id="metrics-overview-section" title="Overview"
            helper="{{ $scoped ? 'Current resource usage for the selected server.' : 'Current resource usage across the selected servers.' }}">
            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-neutral-200 sm:grid-cols-3 lg:grid-cols-6 dark:bg-white/[0.07]">
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Servers online</span>
                    <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ $kpis['serversOnline'] ?? 0 }}<span class="text-neutral-400 dark:text-fg-faint">/{{ $kpis['serversTotal'] ?? 0 }}</span></span>
                    @php
                        $sTotal = max(1, (int) ($kpis['serversTotal'] ?? 0));
                        $sOnline = (int) ($kpis['serversOnline'] ?? 0);
                        $sAttention = min($sOnline, (int) ($kpis['needsAttention'] ?? 0));
                        $sHealthy = max(0, $sOnline - $sAttention);
                        $sOffline = max(0, $sTotal - $sOnline);
                    @endphp
                    <div class="mt-auto pt-3">
                        <div class="flex h-9 flex-col justify-center gap-1.5">
                            <div class="flex h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08] [&>div]:transition-[width] [&>div]:duration-700 [&>div]:ease-out">
                                @if ($sHealthy > 0)<div class="bg-emerald-500" style="width: {{ $sHealthy / $sTotal * 100 }}%"></div>@endif
                                @if ($sAttention > 0)<div class="bg-orange-400 dark:bg-warning" style="width: {{ $sAttention / $sTotal * 100 }}%"></div>@endif
                                @if ($sOffline > 0)<div class="bg-red-500 dark:bg-red-400" style="width: {{ $sOffline / $sTotal * 100 }}%"></div>@endif
                            </div>
                            <div class="flex flex-wrap items-center gap-x-2 text-[10px] text-neutral-500 dark:text-fg-dim">
                                <span>{{ $sHealthy }} healthy</span>
                                @if ($sAttention > 0)<span class="text-orange-500 dark:text-warning">{{ $sAttention }} attention</span>@endif
                                @if ($sOffline > 0)<span class="text-red-500 dark:text-red-400">{{ $sOffline }} offline</span>@endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                        {{ $scoped ? 'CPU' : 'Fleet CPU' }}
                        @if ($kpis['cpuApproximate'] ?? false)
                            <span class="ml-1.5 cursor-help text-neutral-400 dark:text-fg-faint" title="Averaged across servers; not core-weighted">~</span>
                        @endif
                    </span>
                    <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ round($kpis['cpuAvg'] ?? 0) }}%</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-cpu',
                            'initial' => $chartData['cpuSpark'] ?? [],
                            'color' => '#3b82f6',
                            'event' => 'refreshChartData-'.$chartId,
                            'key' => 'cpuSpark',
                            'label' => $scoped ? 'CPU' : 'Fleet CPU',
                            'format' => 'percent',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">{{ $scoped ? 'Memory' : 'Fleet memory' }}</span>
                    <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ round($kpis['memPercent'] ?? 0) }}%</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-memory',
                            'initial' => $chartData['memSpark'] ?? [],
                            'color' => '#8b5cf6',
                            'event' => 'refreshChartData-'.$chartId,
                            'key' => 'memSpark',
                            'label' => 'Memory used',
                            'format' => 'bytes',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">{{ $scoped ? 'Disk' : 'Fleet disk' }}</span>
                    <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ round($kpis['diskPercent'] ?? 0) }}%</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-disk',
                            'initial' => $chartData['diskSpark'] ?? [],
                            'color' => '#ef4444',
                            'event' => 'refreshChartData-'.$chartId,
                            'key' => 'diskSpark',
                            'label' => $scoped ? 'Disk' : 'Fleet disk',
                            'format' => 'percent',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">{{ $scoped ? 'Network' : 'Fleet network' }}</span>
                    <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ formatBytes(($kpis['netRx'] ?? 0) + ($kpis['netTx'] ?? 0)) }}/s</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-network',
                            'initial' => $chartData['netSpark'] ?? [],
                            'color' => '#10b981',
                            'event' => 'refreshChartData-'.$chartId,
                            'key' => 'netSpark',
                            'label' => 'Network',
                            'format' => 'bytes',
                        ])
                    </div>
                </div>
                <div class="col-span-2 flex flex-col bg-[var(--coollabs-base)] px-4 py-3 sm:col-span-1">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Containers</span>
                    <span class="mt-1 text-xl font-semibold tabular-nums text-black dark:text-fg">{{ compactNumber($kpis['containers'] ?? 0) }}</span>
                    <div class="mt-auto pt-3">
                        <div class="flex h-9 flex-col justify-center">
                            @if (! empty($topContainers))
                                @php
                                    $hottest = $this->containerMeta($topContainers[0]['id']);
                                    $hottestValue = match ($containerMetric) {
                                        'memory' => formatBytes($topContainers[0]['memUsed'] ?? 0),
                                        'disk' => formatBytes($topContainers[0]['diskBytes'] ?? 0),
                                        'network' => formatBytes($topContainers[0]['net'] ?? 0).'/s',
                                        default => round($topContainers[0]['cpu'] ?? 0).'%',
                                    };
                                @endphp
                                <span class="truncate text-[13px] font-medium text-black dark:text-fg">{{ $hottest['name'] }}</span>
                                <span class="text-[10px] text-neutral-500 dark:text-fg-dim">hottest, {{ $hottestValue }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-application.settings-section>

        {{-- Trend charts --}}
        {{-- CPU is the primary triage signal, so it spans full width; the other four
             fleet series fall into a clean 2x2 below (no orphaned cell). --}}
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach (['cpu' => 'CPU', 'memory' => 'Memory used', 'network' => 'Network throughput', 'load' => 'Load average', 'disk' => 'Disk usage'] as $key => $label)
                <x-application.settings-section :title="$label" @class(['lg:col-span-2' => $key === 'cpu'])>
                    <div wire:ignore>
                        <div id="fleet-chart-{{ $key }}" class="min-h-[240px] w-full"></div>
                    </div>
                </x-application.settings-section>
            @endforeach
        </div>

        {{-- Servers table --}}
        <x-application.settings-section title="Servers" flush
            helper="Per-server resource pressure. Sorted by CPU, highest first.">
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
                    <div wire:key="fleet-server-{{ $row['uuid'] }}"
                        class="data-table-row fleet-servers-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
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
        <x-application.settings-section title="Hottest containers" flush
            helper="{{ $scoped ? 'Busiest containers on this server, ranked by the selected metric.' : 'Busiest containers across the fleet, ranked by the selected metric.' }}">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-loading compact class="text-neutral-400 dark:text-fg-faint"
                        wire:loading.flex wire:target="containerMetric" />
                    <div class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]">
                        @foreach (['cpu' => 'CPU', 'memory' => 'Memory', 'disk' => 'Disk', 'network' => 'Network'] as $m => $mlabel)
                            <button type="button" wire:click="$set('containerMetric', '{{ $m }}')"
                                wire:loading.attr="disabled" wire:target="containerMetric"
                                @class([$tabButtonBase, $containerMetric === $m ? $tabButtonActive : $tabButtonInactive])>
                                {{ $mlabel }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </x-slot:actions>
            @if (empty($topContainers))
                <div class="p-4">
                    <x-empty size="sm" title="No container metrics"
                        description="Container metrics need a Sentinel build with the bulk endpoints." />
                </div>
            @else
                @php
                    $metricOf = fn ($c) => match ($containerMetric) {
                        'memory' => $c['memUsed'] ?? 0,
                        'disk' => $c['diskBytes'] ?? 0,
                        'network' => $c['net'] ?? 0,
                        default => $c['cpu'] ?? 0,
                    };
                    // Bars are scaled to the leader so the row length reads as "share of the hottest".
                    $maxMetric = max(1, collect($topContainers)->map($metricOf)->max());
                @endphp
                <div class="data-table transition-opacity" wire:loading.class="pointer-events-none opacity-50"
                    wire:target="containerMetric">
                    <div class="data-table-header fleet-containers-table-grid">
                        <span class="text-center">#</span>
                        <span>Container</span>
                        <span>Server</span>
                        <span class="text-right">{{ ['cpu' => 'CPU', 'memory' => 'Memory', 'disk' => 'Disk', 'network' => 'Network'][$containerMetric] }}</span>
                    </div>
                    @foreach ($topContainers as $c)
                        @php
                            $meta = $this->containerMeta($c['id']);
                            $pct = min(100, round($metricOf($c) / $maxMetric * 100, 1));
                            $display = match ($containerMetric) {
                                'memory' => formatBytes($c['memUsed'] ?? 0),
                                'disk' => formatBytes($c['diskBytes'] ?? 0),
                                'network' => formatBytes($c['net'] ?? 0).'/s',
                                default => round($c['cpu'] ?? 0).'%',
                            };
                        @endphp
                        <div wire:key="fleet-container-{{ $c['id'] }}"
                            class="data-table-row fleet-containers-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                            <div class="self-center text-center text-[12px] tabular-nums text-neutral-400 dark:text-fg-faint">{{ $loop->iteration }}</div>
                            <div class="flex min-w-0 flex-col">
                                @if ($meta['link'])
                                    <a href="{{ $meta['link'] }}" class="truncate text-[13px] font-medium text-black hover:underline dark:text-fg" {{ wireNavigate() }}>{{ $meta['name'] }}</a>
                                @else
                                    <span class="truncate text-[13px] font-medium text-black dark:text-fg">{{ $meta['name'] }}</span>
                                @endif
                                @if ($meta['image'])
                                    <span class="truncate font-mono text-[11px] text-neutral-400 dark:text-fg-faint">{{ $meta['image'] }}</span>
                                @endif
                            </div>
                            <div class="min-w-0 self-center truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $c['server'] }}</div>
                            <div class="flex items-center justify-end gap-2.5 self-center">
                                <div class="hidden h-1.5 w-full max-w-[6rem] overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]"
                                    title="{{ $pct }}% of the busiest">
                                    <div class="h-full rounded-full bg-[var(--chart-status-3xx)] transition-[width] duration-700 ease-out" style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="w-20 shrink-0 whitespace-nowrap text-right text-[13px] tabular-nums text-black dark:text-fg">{{ $display }}</span>
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
                // Defer to the next frame so the wire:ignore chart containers are laid out
                // before ApexCharts measures them (a zero-size container renders blank).
                requestAnimationFrame(() => {
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

                const fmtLocalTime = ts => new Date(ts).toLocaleString(undefined, { hour12: false, timeZoneName: 'short' });
                const fmtUtcTime = ts => new Date(ts).toLocaleString(undefined, { hour12: false, timeZone: 'UTC', timeZoneName: 'short' });

                // The app strips the default ApexCharts tooltip background (utilities.css), so
                // every chart must render its own `apexcharts-tooltip-custom` markup.
                const customTooltip = formatter => ({ series, dataPointIndex, w }) => {
                    const timestamp = w.globals.seriesX[0]?.[dataPointIndex];
                    let rows = '';
                    series.forEach((s, i) => {
                        const value = s[dataPointIndex];
                        if (value === null || value === undefined) { return; }
                        rows += `<div class="apexcharts-tooltip-custom-value">${w.globals.seriesNames[i]}: <span class="apexcharts-tooltip-value-bold">${formatter(value)}</span></div>`;
                    });
                    return `<div class="apexcharts-tooltip-custom">${rows}
                        <div class="apexcharts-tooltip-custom-title">Your time: ${fmtLocalTime(timestamp)}</div>
                        <div class="apexcharts-tooltip-custom-title">UTC: ${fmtUtcTime(timestamp)}</div>
                    </div>`;
                };

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
                    tooltip: { shared: true, intersect: false, marker: { show: false }, custom: customTooltip(formatter) },
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
                });
            })();
        </script>
    @endscript
</div>
