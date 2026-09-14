<?php
$tabButtonBase = 'relative inline-flex h-7 items-center justify-center rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
$tabButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
$tabButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';

$approxBadge = fn (string $tooltip) => '<span title="'.e($tooltip).'" class="ml-1.5 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-medium tracking-wide text-amber-700 uppercase dark:bg-amber-500/10 dark:text-amber-400">~ approximate</span>';

$spark = 'refreshChartData-'.$chartId.'-status';
?>
<div class="contents">
@if ($servers->isNotEmpty() && $overview)
<section class="mb-0! min-w-0">
    <div class="mb-3 flex items-end justify-between gap-4">
        <div>
            <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                Traffic analytics
            </h2>
            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                Team-wide request volume across servers with traffic analytics enabled
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if ($servers->isNotEmpty() && $overview)
                <div class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]">
                    <button type="button" wire:click="setRange('24h')"
                        wire:loading.attr="disabled" wire:target="setRange"
                        @class([$tabButtonBase, $range === '24h' ? $tabButtonActive : $tabButtonInactive])>
                        <span wire:loading.class="invisible" wire:target="setRange('24h')">24 hours</span>
                        <x-loading compact class="absolute" wire:loading wire:target="setRange('24h')" aria-label="Loading analytics" />
                    </button>
                    <button type="button" wire:click="setRange('7d')"
                        wire:loading.attr="disabled" wire:target="setRange"
                        @class([$tabButtonBase, $range === '7d' ? $tabButtonActive : $tabButtonInactive])>
                        <span wire:loading.class="invisible" wire:target="setRange('7d')">7 days</span>
                        <x-loading compact class="absolute" wire:loading wire:target="setRange('7d')" aria-label="Loading analytics" />
                    </button>
                    <button type="button" wire:click="setRange('30d')"
                        wire:loading.attr="disabled" wire:target="setRange"
                        @class([$tabButtonBase, $range === '30d' ? $tabButtonActive : $tabButtonInactive])>
                        <span wire:loading.class="invisible" wire:target="setRange('30d')">30 days</span>
                        <x-loading compact class="absolute" wire:loading wire:target="setRange('30d')" aria-label="Loading analytics" />
                    </button>
                </div>
            @endif
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 bg-white px-2.5 text-[12px] font-medium text-neutral-600 transition-[color,background-color,transform] duration-100 ease-out hover:bg-neutral-100 hover:text-black active:scale-[0.97] dark:border-white/[0.08] dark:bg-white/[0.06] dark:text-fg-dim dark:hover:bg-white/[0.1] dark:hover:text-fg">
                Open analytics
                <x-reicon name="arrow-right" class="size-3 opacity-70 transition-transform duration-150 ease-out group-hover:translate-x-0.5" />
            </a>
        </div>
    </div>

    {{-- Sparkline KPI cards. Each links through to the full analytics page. --}}
        <div class="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-neutral-200 bg-neutral-200 sm:grid-cols-2 lg:grid-cols-4 dark:border-white/[0.08] dark:bg-white/[0.07]">
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-[color-mix(in_srgb,var(--color-app)_95%,white)] dark:hover:bg-[color-mix(in_srgb,var(--color-app)_93%,white)]">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Requests</span>
                <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['requests'] ?? 0) }}</span>
                <div class="mt-auto pt-3">
                    @include('livewire.traffic._sparkline', [
                        'id' => $chartId.'-spark-requests',
                        'initial' => $this->requestsSpark(),
                        'colorVar' => '--chart-status-3xx',
                        'event' => $spark,
                        'key' => 'requestsSpark',
                    ])
                </div>
            </a>
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-[color-mix(in_srgb,var(--color-app)_95%,white)] dark:hover:bg-[color-mix(in_srgb,var(--color-app)_93%,white)]">
                <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                    Unique visitors
                    @if ($uniquesApproximate)
                        {!! $approxBadge('Summed across '.$servers->count().' servers; visitors seen on multiple servers may be double-counted.') !!}
                    @endif
                </span>
                <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['uniqueVisitors'] ?? 0) }}</span>
                <div class="mt-auto pt-3">
                    @include('livewire.traffic._sparkline', [
                        'id' => $chartId.'-spark-visitors',
                        'initial' => $this->uniquesSpark(),
                        'colorVar' => '--chart-status-2xx',
                        'event' => $spark,
                        'key' => 'uniquesSpark',
                    ])
                </div>
            </a>
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-[color-mix(in_srgb,var(--color-app)_95%,white)] dark:hover:bg-[color-mix(in_srgb,var(--color-app)_93%,white)]">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Bandwidth</span>
                <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ formatBytes($this->bandwidthBytes()) }}</span>
                <div class="mt-auto pt-3">
                    @include('livewire.traffic._sparkline', [
                        'id' => $chartId.'-spark-bandwidth',
                        'initial' => $this->bandwidthSpark(),
                        'colorVar' => '--chart-spark-bandwidth',
                        'event' => $spark,
                        'key' => 'bandwidthSpark',
                    ])
                </div>
            </a>
            <a href="{{ route('analytics') }}" {{ wireNavigate() }}
                class="group flex flex-col bg-white px-4 py-3 transition-colors hover:bg-neutral-50 dark:bg-[color-mix(in_srgb,var(--color-app)_95%,white)] dark:hover:bg-[color-mix(in_srgb,var(--color-app)_93%,white)]">
                <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">Error rate</span>
                <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ $this->errorRate() }}%</span>
                <div class="mt-auto pt-3">
                    @include('livewire.traffic._sparkline', [
                        'id' => $chartId.'-spark-errors',
                        'initial' => $this->errorsSpark(),
                        'colorVar' => '--chart-status-5xx',
                        'event' => $spark,
                        'key' => 'errorsSpark',
                    ])
                </div>
            </a>
        </div>
</section>
@endif
</div>
