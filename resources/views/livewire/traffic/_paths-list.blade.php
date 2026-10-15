{{--
    Sortable, paginated "top paths" list. Each row shows the request path, a
    proportional request-volume bar, and right-aligned compact metrics. Select a
    column header to sort by it. The path links to its live URL when the owning
    domain is known (new tab, rel="noopener noreferrer nofollow"); the domain shows
    next to the path so the same path of two apps stays apart. Sentinel's
    `__other__` overflow row reads as "Other paths" and stays last. Expects
    `$paths` in scope; optional `$keyPrefix` to namespace wire:keys.

    @param iterable $paths      path rows: ['path', 'domain'?, 'requests', 'bytesOut', 's4xx', 's5xx', 'p95']
    @param ?string  $keyPrefix  wire:key prefix (default "analytics-path")
--}}
@php
    $paths = $paths ?? [];
    $keyPrefix = $keyPrefix ?? 'analytics-path';
    $maxRequests = max(1, (int) collect($paths)->max('requests'));
    $muted = 'text-neutral-300 dark:text-fg-faint';
@endphp
@if (collect($paths)->isEmpty())
    <x-empty size="sm" title="No path data" description="No requests were recorded for the selected range."
        icon-name="unordered-list" />
@else
    <div x-data="trafficTable()">
        <div class="flex items-center gap-3 px-4 py-2 text-[11px] font-medium text-neutral-500 dark:text-fg-dim">
            @include('livewire.traffic._sort-header', ['column' => 'name', 'label' => 'Path', 'text' => true, 'class' => 'min-w-0 flex-1'])
            <span class="hidden w-16 shrink-0 sm:inline" aria-hidden="true"></span>
            @include('livewire.traffic._sort-header', ['column' => 'requests', 'label' => 'Requests', 'class' => 'w-16 shrink-0'])
            @include('livewire.traffic._sort-header', ['column' => 's4xx', 'label' => '4xx', 'class' => 'hidden w-12 shrink-0 sm:inline-flex', 'title' => 'HTTP 4xx client-error responses'])
            @include('livewire.traffic._sort-header', ['column' => 's5xx', 'label' => '5xx', 'class' => 'w-12 shrink-0', 'title' => 'HTTP 5xx server-error responses'])
            @include('livewire.traffic._sort-header', ['column' => 'errorRate', 'label' => 'Error %', 'class' => 'hidden w-14 shrink-0 lg:inline-flex', 'title' => 'Percentage of requests with a 4xx or 5xx response'])
            @include('livewire.traffic._sort-header', ['column' => 'bytesOut', 'label' => 'Bandwidth', 'class' => 'hidden w-16 shrink-0 sm:inline-flex', 'title' => 'Total response data sent'])
            @include('livewire.traffic._sort-header', ['column' => 'p95', 'label' => 'p95', 'class' => 'hidden w-16 shrink-0 md:inline-flex', 'title' => '95% of requests completed within this response time'])
        </div>
        <div class="flex flex-col">
            @foreach ($paths as $path)
                @php
                    $domain = $path['domain'] ?? null;
                    $pathStr = (string) ($path['path'] ?? '');
                    $isOther = $pathStr === '__other__';
                    $href = $domain && ! $isOther ? 'https://'.$domain.$pathStr : null;
                    $requests = (int) ($path['requests'] ?? 0);
                    $s4xx = (int) ($path['s4xx'] ?? 0);
                    $s5xx = (int) ($path['s5xx'] ?? 0);
                    $bytesOut = (int) ($path['bytesOut'] ?? 0);
                    $p95 = (float) ($path['p95'] ?? 0);
                    $errorRate = $requests > 0 ? round((($s4xx + $s5xx) / $requests) * 100, 1) : 0;
                    $width = min(100, round(($requests / $maxRequests) * 100, 1));
                    $sort = ['name' => $pathStr.' '.$domain, 'requests' => $requests, 's4xx' => $s4xx, 's5xx' => $s5xx, 'errorRate' => $errorRate, 'bytesOut' => $bytesOut, 'p95' => $p95];
                @endphp
                <div wire:key="{{ $keyPrefix }}-{{ md5($domain."\n".$pathStr) }}"
                    data-row="{{ json_encode($sort) }}" @if ($isOther) data-pinned @endif
                    x-show="shown($el)"
                    class="flex min-h-11 items-center gap-3 border-t border-neutral-200 px-4 py-2 transition-colors hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.03]">
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
                    <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]"
                        title="Request volume relative to the busiest row in this list">
                        <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $width }}%;"></div>
                    </div>
                    <span class="w-16 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                        title="{{ number_format($requests) }} requests">{{ compactNumber($requests) }}</span>
                    <span @class(['hidden w-12 shrink-0 text-right text-[12px] tabular-nums sm:inline', $s4xx > 0 ? 'font-medium text-pink-600 dark:text-pink-400' : $muted])
                        title="{{ number_format($s4xx) }} client-error responses">{{ compactNumber($s4xx) }}</span>
                    <span @class(['w-12 shrink-0 text-right text-[12px] tabular-nums', $s5xx > 0 ? 'font-medium text-purple-600 dark:text-purple-400' : $muted])
                        title="{{ number_format($s5xx) }} server-error responses">{{ compactNumber($s5xx) }}</span>
                    <span @class(['hidden w-14 shrink-0 text-right text-[11px] tabular-nums lg:inline', $errorRate > 0 ? 'text-neutral-500 dark:text-fg-dim' : $muted])
                        title="Combined 4xx and 5xx response rate">{{ $errorRate }}%</span>
                    <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes($bytesOut) }}</span>
                    <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 md:inline dark:text-fg-faint"
                        title="p95 latency">{{ number_format($p95, 1) }} ms</span>
                </div>
            @endforeach
        </div>
        @include('livewire.traffic._pager')
    </div>
@endif
