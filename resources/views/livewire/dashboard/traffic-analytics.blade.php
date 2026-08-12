<?php
$tabButtonBase = 'h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
$tabButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
$tabButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';

$approxBadge = fn (string $tooltip) => '<span title="'.e($tooltip).'" class="ml-1.5 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-medium tracking-wide text-amber-700 uppercase dark:bg-amber-500/10 dark:text-amber-400">~ approximate</span>';

$spark = 'refreshChartData-'.$chartId.'-status';
?>
<section class="mb-0! min-w-0">
    <div class="mb-3 flex items-end justify-between gap-4">
        <div>
            <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                Traffic analytics
            </h2>
            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                Team-wide request volume across traffic-enabled servers
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if ($servers->isNotEmpty() && $overview)
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
            @endif
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="inline-flex h-7 items-center gap-1 rounded-md px-2.5 text-[12px] font-medium text-neutral-500 ring-1 ring-neutral-200 transition-colors hover:text-black dark:text-fg-faint dark:ring-white/[0.08] dark:hover:text-fg">
                Open analytics
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </div>
    </div>

    @if (! empty($eligibleDisabledServers))
        <div x-data="{ dismissed: localStorage.getItem('traffic-nudge-{{ $nudgeKey }}') === '1' }" x-show="!dismissed" x-cloak
            class="mb-3 flex items-start gap-3 rounded-xl border border-neutral-200 bg-white px-4 py-3 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
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
            description="Enable Sentinel traffic analytics on a server to see a team-wide summary here."
            icon-name="network">
            <x-slot:contents>
                <a class="button" href="{{ route('server.index') }}" {{ wireNavigate() }}>
                    View servers
                </a>
            </x-slot:contents>
        </x-empty>
    @elseif (! $overview)
        <x-empty size="sm" title="No analytics data yet"
            description="We could not load traffic analytics for the selected range. Try a different range or check back shortly."
            icon-name="network" />
    @else
        {{-- Sparkline KPI cards. Each links through to the full analytics page. --}}
        <div class="grid grid-cols-1 gap-px overflow-hidden rounded-xl bg-neutral-200 sm:grid-cols-2 lg:grid-cols-4 dark:bg-white/[0.07]">
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col gap-2 bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-base dark:hover:bg-white/[0.03]">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Requests</span>
                <span class="text-xl font-semibold text-black dark:text-fg">{{ number_format($overview['requests'] ?? 0) }}</span>
                @include('livewire.traffic._sparkline', [
                    'id' => $chartId.'-spark-requests',
                    'initial' => $this->requestsSpark(),
                    'colorVar' => '--chart-status-3xx',
                    'event' => $spark,
                    'key' => 'requestsSpark',
                ])
            </a>
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col gap-2 bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-base dark:hover:bg-white/[0.03]">
                <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                    Unique visitors
                    @if ($uniquesApproximate)
                        {!! $approxBadge('Summed across '.$servers->count().' servers; visitors seen on multiple servers may be double-counted.') !!}
                    @endif
                </span>
                <span class="text-xl font-semibold text-black dark:text-fg">{{ number_format($overview['uniqueVisitors'] ?? 0) }}</span>
                <span class="mt-auto h-9"></span>
            </a>
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col gap-2 bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-base dark:hover:bg-white/[0.03]">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Bandwidth</span>
                <span class="text-xl font-semibold text-black dark:text-fg">{{ formatBytes($this->bandwidthBytes()) }}</span>
                <span class="mt-auto h-9"></span>
            </a>
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col gap-2 bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-base dark:hover:bg-white/[0.03]">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Error rate</span>
                <span class="text-xl font-semibold text-black dark:text-fg">{{ $this->errorRate() }}%</span>
                @include('livewire.traffic._sparkline', [
                    'id' => $chartId.'-spark-errors',
                    'initial' => $this->errorsSpark(),
                    'colorVar' => '--chart-status-5xx',
                    'event' => $spark,
                    'key' => 'errorsSpark',
                ])
            </a>
        </div>
    @endif
</section>
