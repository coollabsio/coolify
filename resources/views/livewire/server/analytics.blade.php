<?php
$tabButtonBase = 'h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
$tabButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
$tabButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';

$dimensionLabels = [
    'referer' => 'Referrers',
    'browser' => 'Browsers',
    'os' => 'Operating systems',
    'device' => 'Devices',
];
?>
<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Analytics | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-[1180px] min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="analytics" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if (! $enabled)
                <x-application.settings-section id="analytics-section" title="Analytics"
                    helper="Inspect traffic statistics reported by Sentinel across all applications on this server.">
                    <x-slot:actions>
                        <a class="button" href="{{ route('server.sentinel', ['server_uuid' => $server->uuid]) }}"
                            {{ wireNavigate() }}>
                            Server settings
                            <x-external-link />
                        </a>
                    </x-slot:actions>

                    <div class="flex flex-col gap-4 px-4 py-4">
                        <div class="flex flex-col gap-1">
                            <h3 class="text-[13px] font-semibold text-black dark:text-fg">
                                Turn on traffic analytics for this server
                            </h3>
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                See request volume, status codes, top paths, and visitor geography for every
                                application on this server. Here's exactly what enabling does:
                            </p>
                        </div>

                        @include('livewire.traffic._enable-benefits')

                        @if ($this->isEligibleForTrafficAnalytics())
                            <div>
                                <button type="button" wire:click="enableTrafficAnalytics" class="button"
                                    wire:loading.attr="disabled" wire:target="enableTrafficAnalytics">
                                    <span wire:loading.remove wire:target="enableTrafficAnalytics">Enable traffic analytics</span>
                                    <span wire:loading wire:target="enableTrafficAnalytics">Enabling…</span>
                                </button>
                            </div>
                        @else
                            <p class="text-[12px] font-medium text-amber-600 dark:text-amber-400">
                                Traffic analytics is not available on Swarm or Build-pack servers.
                            </p>
                        @endif
                    </div>
                </x-application.settings-section>
            @elseif (! $overview)
                <x-application.settings-section id="analytics-section" title="Analytics"
                    helper="Inspect traffic statistics reported by Sentinel across all applications on this server.">
                    <x-empty size="sm" title="No analytics data yet"
                        description="We could not load traffic analytics for the selected range. Try a different range or check back shortly."
                        icon-name="network" />
                </x-application.settings-section>
            @else
                @if ($this->isLivePollable())
                    <div wire:poll.60s="loadData" class="hidden"></div>
                @endif

                <x-application.settings-section id="analytics-range-section" title="Analytics"
                    helper="Inspect traffic statistics reported by Sentinel across all applications on this server.">
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            @include('livewire.traffic._live-toggle')
                            <div class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]">
                            <button type="button" wire:click="setRange('24h')"
                                @class([$tabButtonBase, $range === '24h' ? $tabButtonActive : $tabButtonInactive])>
                                24 hours
                            </button>
                            <button type="button" wire:click="setRange('7d')"
                                @class([$tabButtonBase, $range === '7d' ? $tabButtonActive : $tabButtonInactive])>
                                7 days
                            </button>
                            <button type="button" wire:click="setRange('30d')"
                                @class([$tabButtonBase, $range === '30d' ? $tabButtonActive : $tabButtonInactive])>
                                30 days
                            </button>
                            </div>
                        </div>
                    </x-slot:actions>

                    <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-neutral-200 sm:grid-cols-3 lg:grid-cols-5 dark:bg-white/[0.07]">
                        <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                            <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Requests</span>
                            <span class="text-xl font-semibold text-black dark:text-fg">{{ number_format($overview['requests'] ?? 0) }}</span>
                        </div>
                        <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                            <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Unique visitors</span>
                            <span class="text-xl font-semibold text-black dark:text-fg">{{ number_format($overview['uniqueVisitors'] ?? 0) }}</span>
                        </div>
                        <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                            <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Bandwidth</span>
                            <span class="text-xl font-semibold text-black dark:text-fg">{{ formatBytes($this->bandwidthBytes()) }}</span>
                        </div>
                        <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                            <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Error rate</span>
                            <span class="text-xl font-semibold text-black dark:text-fg">{{ $this->errorRate() }}%</span>
                        </div>
                        <div class="flex flex-col gap-1 bg-white px-4 py-3 dark:bg-base">
                            <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">p95 latency</span>
                            <span class="text-xl font-semibold text-black dark:text-fg">{{ number_format($overview['latencyP95'] ?? 0, 1) }} ms</span>
                        </div>
                    </div>
                </x-application.settings-section>

                <x-application.settings-section id="analytics-status-section" title="Status codes"
                    helper="Distribution of response status codes for the selected range.">
                    <div wire:ignore id="{!! $chartId !!}-status" class="min-h-[220px] w-full"></div>

                    @script
                    <script>
                        (() => {
                            checkTheme();

                            const cssVar = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
                            const statusColors = () => [
                                cssVar('--chart-status-2xx'),
                                cssVar('--chart-status-3xx'),
                                cssVar('--chart-status-4xx'),
                                cssVar('--chart-status-5xx'),
                            ];

                            const statusChart = new ApexCharts(document.getElementById('{!! $chartId !!}-status'), {
                                chart: {
                                    height: 220,
                                    type: 'donut',
                                    toolbar: {
                                        show: false
                                    },
                                    background: 'transparent',
                                },
                                series: [0, 0, 0, 0],
                                labels: ['2xx', '3xx', '4xx', '5xx'],
                                colors: statusColors(),
                                stroke: {
                                    width: 2,
                                },
                                dataLabels: {
                                    enabled: false,
                                },
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        colors: textColor,
                                    },
                                },
                                noData: {
                                    text: 'Loading status codes…',
                                    style: {
                                        color: textColor,
                                    },
                                },
                                tooltip: {
                                    y: {
                                        formatter: value => `${value.toLocaleString()} requests`,
                                    },
                                },
                            });

                            statusChart.render();

                            Livewire.on('refreshChartData-{!! $chartId !!}-status', chartData => {
                                checkTheme();
                                statusChart.updateOptions({
                                    colors: statusColors(),
                                    series: chartData[0].seriesData,
                                    legend: {
                                        position: 'bottom',
                                        labels: {
                                            colors: textColor,
                                        },
                                    },
                                });
                            });
                        })();
                    </script>
                    @endscript
                </x-application.settings-section>

                <x-application.settings-section id="analytics-leaderboard-section" title="Top applications"
                    helper="Applications on this server ranked by request volume for the selected range." flush>
                    @forelse ($leaderboard as $row)
                        <div wire:key="analytics-leaderboard-{{ $row['uuid'] }}"
                            class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                            <span class="min-w-0 flex-1 truncate text-[12px] text-black dark:text-fg">{{ $row['name'] }}</span>
                            <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ number_format($row['requests']) }} req</span>
                            <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ formatBytes($row['bandwidth']) }}</span>
                        </div>
                    @empty
                        <x-empty size="sm" title="No application data" description="No per-application requests were recorded for the selected range."
                            icon-name="unordered-list" />
                    @endforelse
                </x-application.settings-section>

                <x-application.settings-section id="analytics-paths-section" title="Top paths"
                    helper="Most requested paths for the selected range." flush>
                    @forelse ($topPaths as $path)
                        <div wire:key="analytics-path-{{ $loop->index }}"
                            class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                            <span class="min-w-0 flex-1 truncate font-mono text-[12px] text-black dark:text-fg">{{ $path['path'] }}</span>
                            <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ number_format($path['requests']) }} req</span>
                            <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ formatBytes($path['bytesOut']) }}</span>
                            <span class="hidden shrink-0 text-[12px] text-neutral-500 sm:inline dark:text-fg-dim">p95 {{ number_format($path['p95'], 1) }} ms</span>
                        </div>
                    @empty
                        <x-empty size="sm" title="No path data" description="No requests were recorded for the selected range."
                            icon-name="unordered-list" />
                    @endforelse
                </x-application.settings-section>

                <x-application.settings-section id="analytics-country-section" title="Countries"
                    helper="Request volume by visitor country for the selected range." flush>
                    @include('livewire.traffic._geo', [
                        'countries' => data_get($breakdowns, 'country', []),
                        'attribution' => $attribution,
                    ])
                </x-application.settings-section>

                @foreach ($dimensionLabels as $dimension => $label)
                    <x-application.settings-section id="analytics-{{ $dimension }}-section" title="{{ $label }}"
                        helper="Top {{ strtolower($label) }} by request count for the selected range." flush>
                        @forelse (data_get($breakdowns, $dimension, []) as $row)
                            <div wire:key="analytics-{{ $dimension }}-{{ $loop->index }}"
                                class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                                <span class="min-w-0 flex-1 truncate text-[12px] text-black dark:text-fg">{{ $row['value'] ?: 'Unknown' }}</span>
                                <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ number_format($row['requests']) }} req</span>
                                <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ formatBytes($row['bytesOut']) }}</span>
                            </div>
                        @empty
                            <x-empty size="sm" title="No data" description="No {{ strtolower($label) }} data for the selected range."
                                icon-name="network" />
                        @endforelse
                    </x-application.settings-section>
                @endforeach
            @endif
        </div>
    </div>
</div>
