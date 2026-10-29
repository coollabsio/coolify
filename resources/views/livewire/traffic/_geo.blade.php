{{--
    "Locations" settings-section for traffic analytics: a lightweight interactive
    3D dotted globe (WebGL, via cobe — see resources/js/traffic-globe.js) beside a
    scrollable ranked list. A Countries / Cities switch in the section header picks
    the list and the globe markers; the choice is saved per-browser in localStorage.
    Hovering a row rotates the globe to face it. The globe live-updates from the
    host's `refreshChartData-{chartId}-status` payload (`geo` key), so `$chartId`
    must be in scope.

    @param iterable $countries    country-breakdown rows: ['value' => ISO-A2, 'requests', 'bytesOut']
    @param array    $cities       output of trafficCityRows(); empty when there is no city data
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

    $cityRows = collect($cities ?? []);
    $maxCityRequests = max(1, (int) $cityRows->max('requests'));

    // Initial marker payload for the globe: one set for each view.
    $geoInit = trafficGeoMarkers($known->all(), $cityRows->all());

    $hasData = $countryRows->isNotEmpty();
    $globeId = ($chartId ?? 'traffic').'-globe';
    $viewButton = 'h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors';
    $viewButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
    $viewButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';
    $listClass = 'max-h-[320px] overflow-y-auto pr-1 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-neutral-300 dark:scrollbar-thumb-white/10';
    $rowClass = 'group flex min-h-10 items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.03]';
@endphp

<x-application.settings-section id="analytics-country-section" title="Locations"
    helper="Request volume by visitor country and city for the selected range." flush
    x-data="{
        // Storage can be blocked (sandboxed iframe, privacy mode, full quota); the switch then works without saving.
        view: (() => { try { return localStorage.getItem('traffic-geo-view') === 'cities' ? 'cities' : 'countries'; } catch (e) { return 'countries'; } })(),
        setView(next) {
            this.view = next;
            try { localStorage.setItem('traffic-geo-view', next); } catch (e) {}
        },
        globe() {
            const canvas = document.getElementById('{{ $globeId }}');

            return canvas ? canvas._trafficGlobe : null;
        },
    }">
    <x-slot:actions>
        @if ($hasData)
        <div wire:key="geo-view-{{ $globeId }}"
            class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]"
            role="group" aria-label="Location detail">
            @foreach (['countries' => 'Countries', 'cities' => 'Cities'] as $viewKey => $viewLabel)
                <button type="button" @click="setView('{{ $viewKey }}')"
                    :aria-pressed="view === '{{ $viewKey }}' ? 'true' : 'false'"
                    class="{{ $viewButton }}"
                    :class="view === '{{ $viewKey }}' ? '{{ $viewButtonActive }}' : '{{ $viewButtonInactive }}'">{{ $viewLabel }}</button>
            @endforeach
        </div>
        @endif
    </x-slot:actions>

    <div class="flex flex-col">
        @if (! $hasData)
            <x-empty size="sm" title="No data" description="No country data for the selected range."
                icon-name="network" />
        @else
            {{-- Keyed so the lazy-load morph inserts fresh nodes instead of reusing the
                 placeholder skeleton, which has the same shape. --}}
            <div wire:key="geo-body-{{ $globeId }}"
                class="grid grid-cols-1 items-start gap-4 border-b border-neutral-200 p-4 lg:grid-cols-[2fr_3fr] dark:border-white/[0.07]">
                {{-- Interactive dotted globe (40%). wire:ignore so live-poll morphs never tear
                     down the WebGL canvas. Mounted via Alpine init()/destroy(), which run each
                     time this element enters or leaves the DOM (a Livewire script directive
                     runs one time only, before the lazy-loaded canvas exists). --}}
                <div wire:ignore class="flex items-center justify-center"
                    x-data="{
                        controller: null,
                        refreshCleanup: null,
                        geo: @js($geoInit),
                        isDark() { return document.documentElement.classList.contains('dark'); },
                        // City markers in the Cities view; country markers when there are no cities.
                        markers() {
                            return this.view === 'cities' && this.geo.cities.length ? this.geo.cities : this.geo.countries;
                        },
                        init() {
                            const canvas = this.$refs.canvas;
                            if (typeof window.mountTrafficGlobe !== 'function') { return; }
                            this.controller = window.mountTrafficGlobe(canvas, this.markers(), this.isDark());
                            // Expose for row-hover focus() from the list rows.
                            canvas._trafficGlobe = this.controller;
                            this.$watch('view', () => this.controller && this.controller.update(this.markers(), this.isDark()));
                            this.refreshCleanup = Livewire.on('refreshChartData-{!! $chartId !!}-status', payload => {
                                const data = Array.isArray(payload) ? payload[0] : payload;
                                if (data && data.geo && Array.isArray(data.geo.countries)) {
                                    this.geo = data.geo;
                                    this.controller.update(this.markers(), this.isDark());
                                }
                            });
                        },
                        destroy() {
                            if (this.refreshCleanup) { this.refreshCleanup(); this.refreshCleanup = null; }
                            if (this.controller) { this.controller.destroy(); this.controller = null; }
                            if (this.$refs.canvas) { this.$refs.canvas._trafficGlobe = null; }
                        },
                    }">
                    <div class="relative aspect-square w-full max-w-[320px]">
                        <canvas id="{!! $globeId !!}" x-ref="canvas"
                            class="h-full w-full [touch-action:none]"
                            style="width: 100%; height: 100%;"></canvas>
                    </div>
                </div>

                {{-- Ranked, scrollable lists (60%). Hover a row to face it on the globe. --}}
                <div class="min-w-0">
                    <div x-show="view === 'countries'" class="{{ $listClass }}">
                        @foreach ($countryRows as $row)
                            @php
                                $isUnknown = $row['value'] === '';
                                $width = min(100, round(($row['requests'] / $maxRequests) * 100, 1));
                                $flagUrl = $isUnknown ? null : countryFlagUrl($row['value']);
                            @endphp
                            <div wire:key="geo-country-{{ $globeId }}-{{ $isUnknown ? 'unknown' : $row['value'] }}"
                                @if (! $isUnknown) @mouseenter="globe()?.focus('{{ $row['value'] }}')" @mouseleave="globe()?.resume()" @endif
                                class="{{ $rowClass }}">
                                @if ($flagUrl)
                                    <img src="{{ $flagUrl }}" srcset="{{ countryFlagUrl($row['value'], '48x36') }} 2x" alt=""
                                        loading="lazy" width="20" height="15"
                                        class="h-[15px] w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-black/5 dark:ring-white/10"
                                        onerror="this.style.visibility='hidden'">
                                @else
                                    <span class="w-5 shrink-0 text-center text-[14px] leading-none" aria-hidden="true">🌐</span>
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

                    <div x-show="view === 'cities'" x-cloak class="{{ $listClass }}">
                        @forelse ($cityRows as $row)
                            @php
                                $hasCoordinates = $row['lat'] !== null && $row['lon'] !== null;
                                $width = min(100, round(($row['requests'] / $maxCityRequests) * 100, 1));
                                $flagUrl = countryFlagUrl($row['country']);
                            @endphp
                            <div wire:key="geo-city-{{ $globeId }}-{{ md5($row['key']) }}"
                                @if ($hasCoordinates) @mouseenter="globe()?.focus([{{ (float) $row['lat'] }}, {{ (float) $row['lon'] }}])" @mouseleave="globe()?.resume()" @endif
                                class="{{ $rowClass }}">
                                @if ($flagUrl)
                                    <img src="{{ $flagUrl }}" srcset="{{ countryFlagUrl($row['country'], '48x36') }} 2x" alt=""
                                        loading="lazy" width="20" height="15"
                                        class="h-[15px] w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-black/5 dark:ring-white/10"
                                        onerror="this.style.visibility='hidden'">
                                @else
                                    <span class="w-5 shrink-0 text-center text-[14px] leading-none" aria-hidden="true">🌐</span>
                                @endif
                                <span class="flex min-w-0 flex-1 items-baseline gap-2" title="{{ $row['label'] }}">
                                    <span class="truncate text-[12px] text-black dark:text-fg">{{ $row['name'] }}</span>
                                    @if ($row['detail'] !== '')
                                        <span class="hidden shrink-0 text-[11px] text-neutral-400 md:inline dark:text-fg-faint">{{ $row['detail'] }}</span>
                                    @endif
                                </span>
                                <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                                    <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $width }}%;"></div>
                                </div>
                                <span class="w-12 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                                    title="{{ number_format($row['requests']) }} requests">{{ compactNumber($row['requests']) }}</span>
                                <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes($row['bytesOut']) }}</span>
                            </div>
                        @empty
                            <x-empty size="sm" title="No city data" description="No city data for the selected range."
                                icon-name="network" />
                        @endforelse
                    </div>
                </div>
            </div>

            @if (! empty($attribution))
                <p class="px-4 py-2 text-[11px] text-neutral-400 dark:text-fg-faint">
                    {{ $attribution }}
                </p>
            @endif
        @endif
    </div>
</x-application.settings-section>
