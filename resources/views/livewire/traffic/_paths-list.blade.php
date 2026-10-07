{{--
    Paginated "top paths" list. Each row shows the request path, a proportional
    request-volume bar, and right-aligned compact metrics (requests / bytes / p95).
    The path links to its live URL when the owning domain is known (new tab,
    rel="noopener noreferrer nofollow"); the domain shows next to the path so the
    same path of two apps stays apart. Sentinel's `__other__` overflow row reads
    as "Other paths". Expects `$paths` in scope; optional
    `$keyPrefix` to namespace wire:keys.

    @param iterable $paths      path rows: ['path', 'domain'?, 'requests', 'bytesOut', 'p95']
    @param ?string  $keyPrefix  wire:key prefix (default "analytics-path")
--}}
@php
    $paths = $paths ?? [];
    $keyPrefix = $keyPrefix ?? 'analytics-path';
    $maxRequests = max(1, (int) collect($paths)->max('requests'));
@endphp
@if (collect($paths)->isEmpty())
    <x-empty size="sm" title="No path data" description="No requests were recorded for the selected range."
        icon-name="unordered-list" />
@else
    <div x-data="{ page: 0, per: 10, total: {{ count($paths) }} }">
        <div class="flex items-center gap-3 border-b border-neutral-200 px-4 py-2 text-[11px] font-medium text-neutral-500 dark:border-white/[0.07] dark:text-fg-dim">
            <span class="min-w-0 flex-1">Path</span>
            <span class="hidden w-16 shrink-0 text-right sm:inline" title="Request volume relative to the busiest row in this list">Volume</span>
            <span class="w-16 shrink-0 text-right">Requests</span>
            <span class="hidden w-14 shrink-0 text-right sm:inline" title="HTTP 4xx client-error responses">4xx errors</span>
            <span class="w-14 shrink-0 text-right" title="HTTP 5xx server-error responses">5xx errors</span>
            <span class="hidden w-12 shrink-0 text-right lg:inline" title="Percentage of requests with a 4xx or 5xx response">Error %</span>
            <span class="hidden w-16 shrink-0 text-right sm:inline" title="Total response data sent">Bandwidth</span>
            <span class="hidden w-16 shrink-0 text-right md:inline" title="95% of requests completed within this response time">p95 latency</span>
        </div>
        @foreach ($paths as $path)
            @php
                $domain = $path['domain'] ?? null;
                $pathStr = (string) ($path['path'] ?? '');
                $isOther = $pathStr === '__other__';
                $href = $domain && ! $isOther ? 'https://'.$domain.$pathStr : null;
                $requests = (int) ($path['requests'] ?? 0);
                $s4xx = (int) ($path['s4xx'] ?? 0);
                $s5xx = (int) ($path['s5xx'] ?? 0);
                $errorRate = $requests > 0 ? round((($s4xx + $s5xx) / $requests) * 100, 1) : 0;
                $width = min(100, round(($requests / $maxRequests) * 100, 1));
            @endphp
            <div wire:key="{{ $keyPrefix }}-{{ md5($domain."\n".$pathStr) }}"
                x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                @if ($isOther)
                    <span class="min-w-0 flex-1 truncate text-[12px] text-neutral-500 dark:text-fg-dim"
                        title="All paths outside the top paths that Sentinel tracks">Other paths</span>
                @else
                    <span class="flex min-w-0 flex-1 items-baseline gap-1.5">
                        @if ($href)
                            <a href="{{ $href }}" target="_blank" rel="noopener noreferrer nofollow"
                                class="truncate font-mono text-[12px] text-black hover:underline dark:text-fg">{{ $pathStr }}</a>
                        @else
                            <span class="truncate font-mono text-[12px] text-black dark:text-fg">{{ $pathStr }}</span>
                        @endif
                        @if ($domain)
                            <span class="max-w-48 shrink-0 truncate text-[11px] text-neutral-400 dark:text-fg-faint" title="{{ $domain }}">{{ $domain }}</span>
                        @endif
                    </span>
                @endif
                <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                    <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $width }}%;"></div>
                </div>
                <span class="w-16 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                    title="{{ number_format($requests) }} requests">{{ compactNumber($requests) }}</span>
                <span class="hidden w-14 shrink-0 text-right text-[11px] font-medium tabular-nums text-pink-600 sm:inline dark:text-pink-400"
                    title="{{ number_format($s4xx) }} client-error responses">{{ compactNumber($s4xx) }} 4xx</span>
                <span class="w-14 shrink-0 text-right text-[11px] font-medium tabular-nums text-purple-600 dark:text-purple-400"
                    title="{{ number_format($s5xx) }} server-error responses">{{ compactNumber($s5xx) }} 5xx</span>
                <span class="hidden w-12 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 lg:inline dark:text-fg-faint"
                    title="Combined 4xx and 5xx response rate">{{ $errorRate }}%</span>
                <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes((int) ($path['bytesOut'] ?? 0)) }}</span>
                <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 md:inline dark:text-fg-faint"
                    title="p95 latency">{{ number_format((float) ($path['p95'] ?? 0), 1) }} ms</span>
            </div>
        @endforeach
        @include('livewire.traffic._pager')
    </div>
@endif
