{{--
    "Top cities" settings-section: ranked, paginated city rows from the Sentinel
    `city` breakdown. Hovering a row that has coordinates rotates the globe of the
    Countries section to face it, so `$chartId` must be in scope.

    @param array $cities  output of trafficCityRows()
--}}
@php
    $cityRows = collect($cities ?? []);
    $maxCityRequests = max(1, (int) $cityRows->max('requests'));
    $globeId = ($chartId ?? 'traffic').'-globe';
@endphp
<x-application.settings-section id="analytics-city-section" title="Top cities"
    helper="Request volume by visitor city for the selected range." flush>
    @if ($cityRows->isEmpty())
        <x-empty size="sm" title="No data" description="No city data for the selected range." icon-name="network" />
    @else
        <div x-data="{
            page: 0,
            per: 10,
            total: {{ $cityRows->count() }},
            globe() {
                const canvas = document.getElementById('{!! $globeId !!}');

                return canvas ? canvas._trafficGlobe : null;
            },
        }">
            @foreach ($cityRows as $row)
                @php
                    $hasCoordinates = $row['lat'] !== null && $row['lon'] !== null;
                    $width = min(100, round(($row['requests'] / $maxCityRequests) * 100, 1));
                    $flagUrl = countryFlagUrl($row['country']);
                @endphp
                <div wire:key="analytics-city-{{ md5($row['key']) }}"
                    x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                    @if ($hasCoordinates) @mouseenter="globe()?.focus([{{ (float) $row['lat'] }}, {{ (float) $row['lon'] }}])" @mouseleave="globe()?.resume()" @endif
                    class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                    @if ($flagUrl)
                        <img src="{{ $flagUrl }}" srcset="{{ countryFlagUrl($row['country'], '48x36') }} 2x" alt=""
                            loading="lazy" width="20" height="15"
                            class="h-[15px] w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-black/5 dark:ring-white/10"
                            onerror="this.style.visibility='hidden'">
                    @else
                        <span class="w-5 shrink-0 text-center text-[14px] leading-none" aria-hidden="true">🌐</span>
                    @endif
                    <span class="min-w-0 flex-1 truncate text-[12px] text-black dark:text-fg" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                    <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                        <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $width }}%;"></div>
                    </div>
                    <span class="w-12 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                        title="{{ number_format($row['requests']) }} requests">{{ compactNumber($row['requests']) }}</span>
                    <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes($row['bytesOut']) }}</span>
                </div>
            @endforeach
            @include('livewire.traffic._pager')
        </div>
    @endif
</x-application.settings-section>
