<?php
$tabButtonBase = 'h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
$tabButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
$tabButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';

$dimensionLabels = [
    'referer' => 'Referrers',
    'browser' => 'Browsers',
    'os' => 'Operating systems',
];
$analyticsServerUuid = $application->destination?->server?->uuid;
?>
<div class="flex flex-col gap-6">
    @if (! $enabled)
        <x-application.settings-section id="analytics-section" title="Analytics"
            helper="Inspect traffic statistics reported by Sentinel.">
            @if ($analyticsServerUuid)
                <x-slot:actions>
                    <a class="button" href="{{ route('server.sentinel', ['server_uuid' => $analyticsServerUuid]) }}"
                        {{ wireNavigate() }}>
                        Server settings
                        <x-external-link />
                    </a>
                </x-slot:actions>
            @endif
            <x-empty size="sm" title="Traffic analytics is not enabled"
                description="Enable Sentinel traffic analytics for this server to start collecting request analytics."
                icon-name="network" />
        </x-application.settings-section>
    @elseif (! $overview)
        <x-application.settings-section id="analytics-section" title="Analytics"
            helper="Inspect traffic statistics reported by Sentinel.">
            <x-empty size="sm" title="No analytics data yet"
                description="We could not load traffic analytics for the selected range. Try a different range or check back shortly."
                icon-name="network" />
        </x-application.settings-section>
    @else
        @if ($this->isLivePollable())
            <div wire:poll.60s="loadData" class="hidden"></div>
        @endif

        <x-application.settings-section id="analytics-range-section" title="Analytics"
            helper="Inspect traffic statistics reported by Sentinel.">
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
                <div class="flex flex-col bg-white px-4 py-3 dark:bg-base">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Requests</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['requests'] ?? 0) }}</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-requests',
                            'initial' => $this->requestsSpark(),
                            'colorVar' => '--chart-status-3xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'requestsSpark',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-white px-4 py-3 dark:bg-base">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Unique visitors</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['uniqueVisitors'] ?? 0) }}</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-visitors',
                            'initial' => $this->uniquesSpark(),
                            'colorVar' => '--chart-status-2xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'uniquesSpark',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-white px-4 py-3 dark:bg-base">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Bandwidth</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ formatBytes($this->bandwidthBytes()) }}</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-bandwidth',
                            'initial' => $this->bandwidthSpark(),
                            'colorVar' => '--chart-spark-bandwidth',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'bandwidthSpark',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-white px-4 py-3 dark:bg-base">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Error rate</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ $this->errorRate() }}%</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-errors',
                            'initial' => $this->errorsSpark(),
                            'colorVar' => '--chart-status-5xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'errorsSpark',
                        ])
                    </div>
                </div>
                <div class="col-span-2 flex flex-col bg-white px-4 py-3 sm:col-span-1 dark:bg-base">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">p95 latency</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['latencyP95'] ?? 0, 1) }} ms</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-latency',
                            'initial' => $this->latencySpark(),
                            'colorVar' => '--chart-status-4xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'latencySpark',
                        ])
                    </div>
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="analytics-requests-section" title="Requests"
            helper="Total request volume over time for the selected range.">
            @include('livewire.traffic._requests-chart')
        </x-application.settings-section>

        <x-application.settings-section id="analytics-status-codes-section" title="Status codes"
            helper="Share of responses by HTTP status class for the selected range.">
            @include('livewire.traffic._status-codes')
        </x-application.settings-section>

        <x-application.settings-section id="analytics-paths-section" title="Top paths"
            helper="Most requested paths for the selected range." flush>
            @include('livewire.traffic._paths-list', ['paths' => $topPaths])
        </x-application.settings-section>

        <x-application.settings-section id="analytics-country-section" title="Countries"
            helper="Request volume by visitor country for the selected range." flush>
            @include('livewire.traffic._geo', [
                'countries' => data_get($breakdowns, 'country', []),
                'attribution' => $attribution,
            ])
        </x-application.settings-section>

        {{-- Requests by device type (donut) + HTTP versions / cache / status. --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-application.settings-section id="analytics-device-section" title="Requests by device type"
                helper="Share of requests by client device class for the selected range.">
                @php $deviceChart = $this->deviceChartData(); @endphp
                @include('livewire.traffic._device-chart', [
                    'labels' => $deviceChart['labels'],
                    'series' => $deviceChart['series'],
                ])
            </x-application.settings-section>

            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'protocol',
                'label' => 'Top HTTP versions',
                'rows' => data_get($breakdowns, 'protocol', []),
                'helper' => 'Request volume by negotiated HTTP protocol version.',
            ])
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'cache',
                'label' => 'Top cache statuses',
                'rows' => data_get($breakdowns, 'cache', []),
                'helper' => 'Reverse-proxy cache outcome (hit, miss, bypass, …) by request count.',
            ])
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'status',
                'label' => 'Top status codes',
                'rows' => data_get($breakdowns, 'status', []),
                'helper' => 'Most frequent HTTP response status codes for the selected range.',
            ])
        </div>

        {{-- Referrers / browsers / OS / AI agents / IPs, two per row. --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ($dimensionLabels as $dimension => $label)
                @include('livewire.traffic._breakdown-section', [
                    'dimension' => $dimension,
                    'label' => $label,
                    'rows' => data_get($breakdowns, $dimension, []),
                ])
            @endforeach
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'agent',
                'label' => 'AI agents & bots',
                'rows' => data_get($breakdowns, 'agent', []),
                'helper' => 'Bot and AI-crawler traffic (GPTBot, ClaudeBot, Googlebot, …) by request count.',
            ])
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'ip',
                'label' => 'Top IPs',
                'rows' => data_get($breakdowns, 'ip', []),
                'helper' => 'Busiest client IPs (real visitor IP, resolved behind Cloudflare / reverse proxies).',
            ])
        </div>

        {{-- User agents (full width — raw UA strings are long). --}}
        @include('livewire.traffic._breakdown-section', [
            'dimension' => 'useragent',
            'label' => 'Top user agents',
            'rows' => data_get($breakdowns, 'useragent', []),
            'helper' => 'Most frequent raw User-Agent strings for the selected range.',
        ])
    @endif
</div>
