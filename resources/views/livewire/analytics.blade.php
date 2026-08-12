<?php
$tabButtonBase = 'h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
$tabButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
$tabButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';

$dimensionLabels = [
    'referer' => 'Referrers',
    'browser' => 'Browsers',
    'os' => 'Operating systems',
];

$approxBadge = fn (string $tooltip) => '<span title="'.e($tooltip).'" class="ml-1.5 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-medium tracking-wide text-amber-700 uppercase dark:bg-amber-500/10 dark:text-amber-400">~ approximate</span>';

$serverListboxOptions = array_merge(
    [['value' => '', 'label' => 'All servers']],
    collect($serverOptions)->map(fn ($name, $uuid) => ['value' => $uuid, 'label' => $name])->values()->all(),
);
$appListboxOptions = array_merge(
    [['value' => '', 'label' => 'All applications']],
    $appGroupedOptions,
);
?>
<div class="flex w-full min-w-0 flex-col gap-6">
    <x-slot:title>
        Analytics | Coolify
    </x-slot>

    {{-- Header --}}
    <div class="flex flex-col gap-4">
        <div class="flex items-center gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                <x-reicon name="analytics" class="size-5" />
            </span>
            <div class="min-w-0">
                <h1 class="text-[16px]! leading-5! font-semibold! text-black dark:text-fg">Analytics</h1>
                <p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-faint">
                    Request traffic across every application and server, reported by Sentinel.
                </p>
            </div>
        </div>

        @if ($servers->isNotEmpty() && $overview)
            <div class="flex flex-wrap items-center gap-2">
                <div class="w-full sm:w-52">
                    <x-forms.listbox id="serverUuid" live :options="$serverListboxOptions" placeholder="All servers" />
                </div>
                {{-- Re-key on the server filter so the application listbox re-initializes with the
                     newly-scoped options (and reset value) instead of showing stale Alpine state. --}}
                <div class="w-full sm:w-52" wire:key="app-filter-{{ $serverUuid }}">
                    <x-forms.listbox id="appUuid" live :options="$appListboxOptions" placeholder="All applications" />
                </div>

                <div class="flex items-center gap-2 sm:ml-auto">
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
            </div>
        @endif
    </div>

    {{-- Nudge: enabled-eligible servers that haven't turned traffic analytics on yet. --}}
    @if (! empty($eligibleDisabledServers))
        <div x-data="{ dismissed: localStorage.getItem('traffic-nudge-{{ $nudgeKey }}') === '1' }" x-show="!dismissed" x-cloak
            class="flex items-start gap-3 rounded-xl border border-neutral-200 bg-white px-4 py-3 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
            <div class="min-w-0 flex-1">
                <p class="text-[12px] font-semibold text-black dark:text-fg">
                    {{ count($eligibleDisabledServers) === 1 ? '1 server can start collecting traffic analytics' : count($eligibleDisabledServers).' servers can start collecting traffic analytics' }}
                </p>
                <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">
                    Enabling regenerates the proxy config and restarts the proxy + Sentinel (a brief blip).
                    Works with Traefik &amp; Caddy.
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if (count($eligibleDisabledServers) === 1)
                    <a class="button" href="{{ route('server.sentinel', ['server_uuid' => $eligibleDisabledServers[0]['uuid']]) }}" {{ wireNavigate() }}>
                        Enable on {{ \Illuminate\Support\Str::limit($eligibleDisabledServers[0]['name'], 16) }}
                    </a>
                @else
                    <a class="button" href="{{ route('server.index') }}" {{ wireNavigate() }}>
                        View servers
                    </a>
                @endif
                <button type="button" title="Dismiss"
                    @click="dismissed = true; localStorage.setItem('traffic-nudge-{{ $nudgeKey }}', '1')"
                    class="flex h-6 w-6 items-center justify-center rounded-md text-neutral-400 transition-colors hover:text-black dark:text-fg-faint dark:hover:text-fg">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6" />
                    </svg>
                </button>
            </div>
        </div>
    @endif

    @if ($servers->isEmpty())
        <x-empty size="sm" title="Traffic analytics is not enabled"
            description="Enable Sentinel traffic analytics on a server to see request analytics here."
            icon-name="analytics">
            <x-slot:contents>
                <a class="button" href="{{ route('server.index') }}" {{ wireNavigate() }}>
                    View servers
                </a>
            </x-slot:contents>
        </x-empty>
    @elseif (! $overview)
        <x-empty size="sm" title="No analytics data yet"
            description="We could not load traffic analytics for the selected filters and range. Try a different range or check back shortly."
            icon-name="analytics" />
    @else
        @if ($this->isLivePollable())
            <div wire:poll.60s="loadData" class="hidden"></div>
        @endif

        {{-- KPIs --}}
        <x-application.settings-section id="analytics-overview-section" title="Overview"
            helper="Aggregate request volume for the selected filters and range.">
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
                    <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                        Unique visitors
                        @if ($uniquesApproximate)
                            {!! $approxBadge('Summed across servers; visitors seen on multiple servers may be double-counted.') !!}
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
                    <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                        p95 latency
                        @if ($latencyApproximate)
                            {!! $approxBadge('Highest p95 latency across servers; not a true cross-server percentile.') !!}
                        @endif
                    </span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['latencyP95'] ?? 0, 1) }} ms</span>
                    <div class="mt-auto pt-3"><div class="h-9"></div></div>
                </div>
            </div>
        </x-application.settings-section>

        {{-- Traffic over time (stacked area), or a status donut when Sentinel lacks /traffic/series. --}}
        <x-application.settings-section id="analytics-status-section" title="Traffic"
            helper="Request volume by status class over time for the selected range.">
            @include('livewire.traffic._status-chart')
        </x-application.settings-section>

        {{-- Top applications + Top hosts side by side; Top paths spans full width below.
             When filtered to one app, only Top paths applies. --}}
        <div @class(['grid grid-cols-1 gap-6', 'lg:grid-cols-2' => $appUuid === ''])>
            @if ($appUuid === '')
                <x-application.settings-section id="analytics-hosts-section" title="Top hosts"
                    helper="Served hostnames ranked by request volume." flush>
                    @include('livewire.traffic._hosts-list', ['hosts' => $topHosts])
                </x-application.settings-section>

                <x-application.settings-section id="analytics-apps-section" title="Top applications"
                    helper="Applications ranked by request volume. Open one for its analytics." flush>
                    @if (empty($topApps))
                        <x-empty size="sm" title="No application data"
                            description="No per-application requests were recorded for the selected range."
                            icon-name="unordered-list" />
                    @else
                        @php $maxAppRequests = max(1, (int) collect($topApps)->max('requests')); @endphp
                        <div x-data="{ page: 0, per: 10, total: {{ count($topApps) }} }">
                            @foreach ($topApps as $row)
                                @php
                                    $appHref = $row['link'] ?? null;
                                    $appWidth = min(100, round(($row['requests'] / $maxAppRequests) * 100, 1));
                                @endphp
                                <{{ $appHref ? 'a' : 'div' }} wire:key="analytics-app-{{ $row['uuid'] }}"
                                    @if ($appHref) href="{{ $appHref }}" {{ wireNavigate() }} @endif
                                    x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                                    @class([
                                        'flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]',
                                        'transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.03]' => $appHref,
                                    ])>
                                    <span class="flex min-w-0 flex-1 items-baseline gap-1.5">
                                        <span class="truncate text-[12px] text-black dark:text-fg">{{ $row['name'] }}</span>
                                        @if (! empty($row['domain']))
                                            <span class="hidden truncate text-[11px] text-neutral-400 sm:inline dark:text-fg-faint">{{ $row['domain'] }}</span>
                                        @endif
                                    </span>
                                    <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                                        <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $appWidth }}%;"></div>
                                    </div>
                                    <span class="w-12 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                                        title="{{ number_format($row['requests']) }} requests">{{ compactNumber($row['requests']) }}</span>
                                    <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes($row['bandwidth']) }}</span>
                                    @if ($appHref)
                                        <svg class="size-3.5 shrink-0 text-neutral-300 dark:text-fg-faint" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                        </svg>
                                    @endif
                                </{{ $appHref ? 'a' : 'div' }}>
                            @endforeach
                            @include('livewire.traffic._pager')
                        </div>
                    @endif
                </x-application.settings-section>
            @endif
        </div>

        {{-- Top paths (full width). --}}
        <x-application.settings-section id="analytics-paths-section" title="Top paths"
            helper="Most requested paths for the selected range." flush>
            @include('livewire.traffic._paths-list', ['paths' => $topPaths])
        </x-application.settings-section>

        {{-- Countries --}}
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
