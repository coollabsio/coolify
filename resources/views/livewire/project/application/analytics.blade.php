<?php
$rangeListboxOptions = [
    ['value' => '24h', 'label' => 'Last 24 hours'],
    ['value' => '7d', 'label' => 'Last 7 days'],
    ['value' => '30d', 'label' => 'Last 30 days'],
];

$dimensionLabels = [
    'referer' => 'Referrers',
    'browser' => 'Browsers',
    'os' => 'Operating systems',
];
// Shared by the application and service analytics tabs. A resource with several Sentinel
// keys (compose services, previews) is merged, so latency and uniques become approximate.
$approxBadge = fn (string $tooltip) => '<span title="'.e($tooltip).'" class="ml-1.5 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-medium tracking-wide text-amber-700 uppercase dark:bg-amber-500/10 dark:text-amber-400">~ approximate</span>';
?>
<div class="flex flex-col gap-6">
    @if (! $enabled)
        <x-application.settings-section id="analytics-section" title="Analytics"
            helper="Inspect traffic statistics reported by Sentinel.">
            @if ($analyticsServerUuid)
                <x-slot:actions>
                    <a class="button" href="{{ route('server.analytics', ['server_uuid' => $analyticsServerUuid]) }}"
                        {{ wireNavigate() }}>
                        Server analytics
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
        @if ($live)
            <div wire:poll.60s="loadData" class="hidden"></div>
        @endif

        <x-application.settings-section id="analytics-range-section" title="Analytics"
            helper="Inspect traffic statistics reported by Sentinel.">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <div class="relative w-full transition-opacity sm:w-44"
                        wire:loading.class="pointer-events-none opacity-60" wire:target="setRange">
                        <x-forms.listbox id="range" onChange="setRange" :onChangeArgs="[]" :options="$rangeListboxOptions" />
                        <div class="absolute inset-0 hidden items-center justify-center rounded-lg bg-white/70 dark:bg-base/70"
                            wire:loading.flex wire:target="setRange">
                            <x-loading compact aria-label="Loading analytics" />
                        </div>
                    </div>
                    <x-live-toggle :active="$live" storageKey="traffic-live" title="Turn live refresh on or off (updates every 60 seconds)" />
                </div>
            </x-slot:actions>

            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-neutral-200 sm:grid-cols-3 lg:grid-cols-5 dark:bg-white/[0.07]">
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
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
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                        Unique visitors
                        @if ($uniquesApproximate)
                            {!! $approxBadge('Summed across services; visitors of more than one service may be double-counted.') !!}
                        @endif
                    </span>
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
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
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
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
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
                <div class="col-span-2 flex flex-col bg-[var(--coollabs-base)] px-4 py-3 sm:col-span-1">
                    <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                        p95 latency
                        @if ($latencyApproximate)
                            {!! $approxBadge('Highest p95 latency across services; not a true merged percentile.') !!}
                        @endif
                    </span>
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

        <x-application.settings-section id="analytics-requests-section" title="Requests" flush
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

        @include('livewire.traffic._geo', [
            'countries' => data_get($breakdowns, 'country', []),
            'cities' => trafficCityRows(data_get($breakdowns, 'city', [])),
            'attribution' => $attribution,
        ])

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
            @php($ipModeEnum = \App\Enums\TrafficIpMode::tryFrom($ipMode) ?? \App\Enums\TrafficIpMode::Full)
            @if ($ipModeEnum !== \App\Enums\TrafficIpMode::Off)
                @include('livewire.traffic._breakdown-section', [
                    'dimension' => 'ip',
                    'label' => $ipModeEnum->breakdownLabel(),
                    'rows' => data_get($breakdowns, 'ip', []),
                    'helper' => $ipModeEnum->breakdownHelper(),
                ])
            @endif
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
