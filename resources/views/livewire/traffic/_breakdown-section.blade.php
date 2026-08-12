{{--
    One breakdown dimension rendered as a paginated settings-section. Handles the
    per-dimension presentation: referrers get a DuckDuckGo favicon + an openable
    external link (rel="noopener noreferrer nofollow", new tab), devices are mapped
    to friendly capitalized labels, and Sentinel's `__other__` overflow bucket reads
    as "Other". Expects `$dimension`, `$label`, `$rows` in scope; optional `$helper`.

    @param string   $dimension  Sentinel dimension key (referer, device, agent, …)
    @param string   $label      Section title
    @param iterable $rows       breakdown rows: ['value', 'requests', 'bytesOut']
    @param ?string  $helper     optional section helper text
--}}
@php
    $rows = $rows ?? [];
    $helper = $helper ?? 'Top '.strtolower($label).' by request count for the selected range.';
@endphp
<x-application.settings-section id="analytics-{{ $dimension }}-section" :title="$label" :helper="$helper" flush>
    @if (empty($rows))
        <x-empty size="sm" title="No data"
            description="No {{ strtolower($label) }} data for the selected range." icon-name="network" />
    @else
        <div x-data="{ page: 0, per: 10, total: {{ count($rows) }} }">
            @foreach ($rows as $row)
                @php
                    $value = (string) ($row['value'] ?? '');
                    $isOther = $value === '__other__';
                    $host = ! $isOther && $dimension === 'referer' ? refererHost($value) : null;
                    $display = $isOther
                        ? 'Other'
                        : match ($dimension) {
                            'device' => deviceLabel($value),
                            'referer' => $host ?? 'Direct / none',
                            default => $value !== '' ? $value : 'Unknown',
                        };
                @endphp
                <div wire:key="analytics-{{ $dimension }}-{{ $loop->index }}"
                    x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                    class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                    @if ($dimension === 'referer' && $host)
                        <img src="{{ refererFaviconUrl($host) }}" alt="" loading="lazy" width="16" height="16"
                            class="size-4 shrink-0 rounded-sm" onerror="this.style.visibility='hidden'">
                        <a href="https://{{ $host }}" target="_blank" rel="noopener noreferrer nofollow"
                            class="min-w-0 flex-1 truncate text-[12px] text-black hover:underline dark:text-fg">{{ $display }}</a>
                    @else
                        <span class="min-w-0 flex-1 truncate text-[12px] text-black dark:text-fg" title="{{ $display }}">{{ $display }}</span>
                    @endif
                    <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ number_format((int) ($row['requests'] ?? 0)) }} req</span>
                    <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ formatBytes((int) ($row['bytesOut'] ?? 0)) }}</span>
                </div>
            @endforeach
            @include('livewire.traffic._pager')
        </div>
    @endif
</x-application.settings-section>
