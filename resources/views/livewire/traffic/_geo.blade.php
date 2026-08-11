{{--
    Shared geo visualization for traffic analytics: an inline public-domain world
    choropleth (see _world-map.blade.php) plus a ranked country list. Driven by the
    `country` breakdown collection; each row is ['value' => ISO-A2, 'requests', 'bytesOut'].
    Colors come from the --chart-geo-* tokens (light + dark) so the map, bars, and
    legend share one source. Consumed by the server + application analytics views and
    the dashboard summary.

    @param iterable $countries  country-breakdown rows
    @param ?string  $attribution  optional Sentinel attribution note
--}}
@php
    $rows = collect($countries ?? [])
        ->map(fn ($r) => [
            'value' => strtoupper((string) data_get($r, 'value', '')),
            'requests' => (int) data_get($r, 'requests', 0),
            'bytesOut' => (int) data_get($r, 'bytesOut', 0),
        ])
        ->filter(fn ($r) => $r['requests'] > 0);

    // A row is "known" only when its code is a real, resolvable ISO-A2; everything
    // else (absent or invalid codes) collapses into one Unknown row.
    [$known, $unknown] = $rows->partition(
        fn ($r) => preg_match('/^[A-Z]{2}$/', $r['value']) && countryName($r['value']) !== 'Unknown'
    );

    // Quantile buckets (1..5) over known countries for the choropleth fills.
    $knownSorted = $known->sortBy('requests')->values();
    $bucketCount = $knownSorted->count();
    $bucketMap = [];
    foreach ($knownSorted as $i => $r) {
        $bucket = $bucketCount <= 1 ? 5 : (int) floor(($i / $bucketCount) * 5) + 1;
        $bucketMap[$r['value']] = min(5, max(1, $bucket));
    }

    $countryRows = $known->values();
    if ($unknown->isNotEmpty()) {
        $countryRows->push([
            'value' => '',
            'requests' => $unknown->sum('requests'),
            'bytesOut' => $unknown->sum('bytesOut'),
        ]);
    }
    $countryRows = $countryRows->sortByDesc('requests')->values();
    $maxRequests = max(1, (int) $countryRows->max('requests'));

    $hasData = $countryRows->isNotEmpty();
    // Stable id (one geo section per page): the map subtree is wire:ignore'd, so a random
    // id would desync from the re-rendered <style> after a live-poll and lose the fills.
    $mapId = 'traffic-geo-map';
@endphp

<div class="flex flex-col">
    @if (! $hasData)
        <x-empty size="sm" title="No data" description="No country data for the selected range."
            icon-name="network" />
    @else
        {{-- Bucket fills are server-rendered as scoped CSS so theme + range changes stay
             in sync with no JS; the map subtree is wire:ignore'd to skip morph churn on polls. --}}
        <style>
            [data-geo-map="{{ $mapId }}"] svg { width: 100%; height: auto; display: block; }
            [data-geo-map="{{ $mapId }}"] svg path {
                fill: var(--chart-geo-empty);
                stroke: var(--chart-geo-stroke);
                stroke-width: 0.4;
                stroke-linejoin: round;
            }
            @foreach ($bucketMap as $a2 => $bucket)
                [data-geo-map="{{ $mapId }}"] svg path#{{ $a2 }} { fill: var(--chart-geo-{{ $bucket }}); }
            @endforeach
        </style>

        <div class="border-b border-neutral-200 px-4 py-3 dark:border-white/[0.07]">
            <div data-geo-map="{{ $mapId }}" wire:ignore
                class="mx-auto max-w-[720px] overflow-hidden rounded-lg bg-neutral-50 dark:bg-white/[0.02]">
                @include('livewire.traffic._world-map')
            </div>
        </div>

        <div>
            @foreach ($countryRows as $row)
                @php
                    $isUnknown = $row['value'] === '';
                    $bucket = $isUnknown ? null : ($bucketMap[$row['value']] ?? 1);
                    $width = min(100, round(($row['requests'] / $maxRequests) * 100, 1));
                    $barColor = $isUnknown ? 'var(--chart-geo-empty)' : "var(--chart-geo-{$bucket})";
                @endphp
                <div wire:key="geo-country-{{ $mapId }}-{{ $loop->index }}"
                    class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                    <span class="shrink-0 text-[14px] leading-none" aria-hidden="true">{{ countryFlagEmoji($isUnknown ? null : $row['value']) }}</span>
                    <span class="min-w-0 flex-1 truncate text-[12px] text-black dark:text-fg">
                        {{ $isUnknown ? 'Unknown' : countryName($row['value']) }}
                    </span>
                    <div class="hidden h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                        <div class="h-full rounded-full" style="width: {{ $width }}%; background-color: {{ $barColor }};"></div>
                    </div>
                    <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ number_format($row['requests']) }} req</span>
                    <span class="hidden shrink-0 text-[12px] text-neutral-500 sm:inline dark:text-fg-dim">{{ formatBytes($row['bytesOut']) }}</span>
                </div>
            @endforeach

            @if (! empty($attribution))
                <p class="border-t border-neutral-200 px-4 py-2 text-[11px] text-neutral-400 dark:border-white/[0.07] dark:text-fg-faint">
                    {{ $attribution }}
                </p>
            @endif
        </div>
    @endif
</div>
