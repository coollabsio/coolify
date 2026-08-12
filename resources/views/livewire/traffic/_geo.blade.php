{{--
    Shared geo visualization for traffic analytics: a lightweight interactive 3D
    dotted globe (WebGL, via cobe — see resources/js/traffic-globe.js) with a
    request-volume marker per country, plus a ranked, paginated country list.
    Driven by the `country` breakdown; each row is ['value' => ISO-A2, 'requests',
    'bytesOut']. The globe live-updates from the host's
    `refreshChartData-{chartId}-status` payload (`geo` key), so `$chartId` must be
    in scope. Bars/flags in the list reuse the --chart-geo-* tokens.

    @param iterable $countries    country-breakdown rows
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

    // Quantile buckets (1..5) over known countries for the list bars.
    $knownSorted = $known->sortBy('requests')->values();
    $bucketCount = $knownSorted->count();
    $bucketMap = [];
    foreach ($knownSorted as $i => $r) {
        $bucket = $bucketCount <= 1 ? 5 : (int) floor(($i / $bucketCount) * 5) + 1;
        $bucketMap[$r['value']] = min(5, max(1, $bucket));
    }

    // Initial marker payload for the globe, keyed by ISO-A2 request volume.
    $geoInit = $known->map(fn ($r) => ['code' => $r['value'], 'requests' => $r['requests']])->values()->all();

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
    $globeId = ($chartId ?? 'traffic').'-globe';
@endphp

<div class="flex flex-col">
    @if (! $hasData)
        <x-empty size="sm" title="No data" description="No country data for the selected range."
            icon-name="network" />
    @else
        <div class="grid grid-cols-1 gap-4 border-b border-neutral-200 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] dark:border-white/[0.07]">
            {{-- Interactive dotted globe. wire:ignore so live-poll morphs never tear down the WebGL canvas. --}}
            <div wire:ignore class="relative mx-auto flex aspect-square w-full max-w-[360px] items-center justify-center">
                <canvas id="{!! $globeId !!}"
                    class="h-full w-full [contain:layout_paint_size] [touch-action:none]"
                    style="width: 100%; height: 100%;"></canvas>
            </div>

            {{-- Ranked country list (updates via morph on range/live refresh). --}}
            <div class="min-w-0 self-center" x-data="{ page: 0, per: 10, total: {{ $countryRows->count() }} }">
                @foreach ($countryRows as $row)
                    @php
                        $isUnknown = $row['value'] === '';
                        $bucket = $isUnknown ? null : ($bucketMap[$row['value']] ?? 1);
                        $width = min(100, round(($row['requests'] / $maxRequests) * 100, 1));
                        $barColor = $isUnknown ? 'var(--chart-geo-empty)' : "var(--chart-geo-{$bucket})";
                    @endphp
                    <div wire:key="geo-country-{{ $globeId }}-{{ $loop->index }}"
                        x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                        class="flex min-h-11 items-center gap-3 border-b border-neutral-200 py-2 last:border-b-0 dark:border-white/[0.07]">
                        @php $flagUrl = $isUnknown ? null : countryFlagUrl($row['value']); @endphp
                        @if ($flagUrl)
                            <img src="{{ $flagUrl }}" srcset="{{ countryFlagUrl($row['value'], '48x36') }} 2x" alt=""
                                loading="lazy" width="20" height="15"
                                class="h-[15px] w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-black/5 dark:ring-white/10"
                                onerror="this.style.visibility='hidden'">
                        @else
                            <span class="shrink-0 text-[14px] leading-none" aria-hidden="true">🌐</span>
                        @endif
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

                @include('livewire.traffic._pager')
            </div>
        </div>

        @if (! empty($attribution))
            <p class="px-4 py-2 text-[11px] text-neutral-400 dark:text-fg-faint">
                {{ $attribution }}
            </p>
        @endif

        @script
        <script>
            (() => {
                const canvas = document.getElementById('{!! $globeId !!}');
                if (!canvas || typeof window.mountTrafficGlobe !== 'function') { return; }

                const isDark = () => document.documentElement.classList.contains('dark');
                let controller = window.mountTrafficGlobe(canvas, @json($geoInit), isDark());

                Livewire.on('refreshChartData-{!! $chartId !!}-status', payload => {
                    const data = Array.isArray(payload) ? payload[0] : payload;
                    if (data && Array.isArray(data.geo)) {
                        controller.update(data.geo, isDark());
                    }
                });

                // Tear down the WebGL context when navigating away (wire:navigate).
                document.addEventListener('livewire:navigating', () => {
                    if (controller) { controller.destroy(); controller = null; }
                }, { once: true });
            })();
        </script>
        @endscript
    @endif
</div>
