{{--
    Shared geo visualization for traffic analytics: a lightweight interactive 3D
    dotted globe (WebGL, via cobe — see resources/js/traffic-globe.js) with a
    request-volume marker per country, beside a scrollable ranked country list.
    Hovering a country row rotates the globe to face it. Driven by the `country`
    breakdown; each row is ['value' => ISO-A2, 'requests', 'bytesOut']. The globe
    live-updates from the host's `refreshChartData-{chartId}-status` payload
    (`geo` key), so `$chartId` must be in scope.

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
        <div class="grid grid-cols-1 items-stretch gap-4 border-b border-neutral-200 p-4 lg:grid-cols-[2fr_3fr] dark:border-white/[0.07]">
            {{-- Interactive dotted globe (40%). wire:ignore so live-poll morphs never tear
                 down the WebGL canvas. --}}
            <div wire:ignore class="flex items-center justify-center">
                <div class="relative aspect-square w-full max-w-[320px]">
                    <canvas id="{!! $globeId !!}"
                        class="h-full w-full [touch-action:none]"
                        style="width: 100%; height: 100%;"></canvas>
                </div>
            </div>

            {{-- Ranked, scrollable country list (60%). Hover a row to face it on the globe. --}}
            <div class="min-w-0" x-data="{
                focus(code) {
                    const g = document.getElementById('{!! $globeId !!}');
                    if (g && g._trafficGlobe) { g._trafficGlobe.focus(code); }
                },
                blur() {
                    const g = document.getElementById('{!! $globeId !!}');
                    if (g && g._trafficGlobe) { g._trafficGlobe.resume(); }
                },
            }">
                <div class="max-h-[320px] overflow-y-auto pr-1 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-neutral-300 dark:scrollbar-thumb-white/10">
                    @foreach ($countryRows as $row)
                        @php
                            $isUnknown = $row['value'] === '';
                            $width = min(100, round(($row['requests'] / $maxRequests) * 100, 1));
                        @endphp
                        <div wire:key="geo-country-{{ $globeId }}-{{ $loop->index }}"
                            @if (! $isUnknown) @mouseenter="focus('{{ $row['value'] }}')" @mouseleave="blur()" @endif
                            class="group flex min-h-10 items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.03]">
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
                            <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                                <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $width }}%;"></div>
                            </div>
                            <span class="w-12 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                                title="{{ number_format($row['requests']) }} requests">{{ compactNumber($row['requests']) }}</span>
                            <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes($row['bytesOut']) }}</span>
                        </div>
                    @endforeach
                </div>
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
                const controller = window.mountTrafficGlobe(canvas, @json($geoInit), isDark());
                // Expose for row-hover focus() from the Alpine list scope.
                canvas._trafficGlobe = controller;

                Livewire.on('refreshChartData-{!! $chartId !!}-status', payload => {
                    const data = Array.isArray(payload) ? payload[0] : payload;
                    if (data && Array.isArray(data.geo)) {
                        controller.update(data.geo, isDark());
                    }
                });

                // Tear down the WebGL context when navigating away (wire:navigate).
                document.addEventListener('livewire:navigating', () => {
                    if (canvas._trafficGlobe) { canvas._trafficGlobe.destroy(); canvas._trafficGlobe = null; }
                }, { once: true });
            })();
        </script>
        @endscript
    @endif
</div>
