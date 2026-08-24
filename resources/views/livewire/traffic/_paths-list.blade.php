{{--
    Paginated "top paths" list. Each row shows the request path, a proportional
    request-volume bar, and right-aligned compact metrics (requests / bytes / p95).
    The path links to its live URL when the owning domain is known (new tab,
    rel="noopener noreferrer nofollow"). Expects `$paths` in scope; optional
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
        @foreach ($paths as $path)
            @php
                $domain = $path['domain'] ?? null;
                $pathStr = (string) ($path['path'] ?? '');
                $href = $domain ? 'https://'.$domain.$pathStr : null;
                $requests = (int) ($path['requests'] ?? 0);
                $width = min(100, round(($requests / $maxRequests) * 100, 1));
            @endphp
            <div wire:key="{{ $keyPrefix }}-{{ md5($pathStr) }}"
                x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                @if ($href)
                    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer nofollow"
                        class="min-w-0 flex-1 truncate font-mono text-[12px] text-black hover:underline dark:text-fg">{{ $pathStr }}</a>
                @else
                    <span class="min-w-0 flex-1 truncate font-mono text-[12px] text-black dark:text-fg">{{ $pathStr }}</span>
                @endif
                <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                    <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $width }}%;"></div>
                </div>
                <span class="w-12 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                    title="{{ number_format($requests) }} requests">{{ compactNumber($requests) }}</span>
                <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes((int) ($path['bytesOut'] ?? 0)) }}</span>
                <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 md:inline dark:text-fg-faint"
                    title="p95 latency">{{ number_format((float) ($path['p95'] ?? 0), 1) }} ms</span>
            </div>
        @endforeach
        @include('livewire.traffic._pager')
    </div>
@endif
