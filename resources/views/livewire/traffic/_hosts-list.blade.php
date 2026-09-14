{{--
    Paginated "top hosts" list — request volume grouped by served hostname (an app's
    primary domain), with a proportional bar and right-aligned compact metrics.
    Links each host to its live origin. Expects `$hosts` in scope.

    @param iterable $hosts      host rows: ['host', 'requests', 'bandwidth']
    @param ?string  $keyPrefix  wire:key prefix (default "analytics-host")
--}}
@php
    $hosts = $hosts ?? [];
    $keyPrefix = $keyPrefix ?? 'analytics-host';
    $maxRequests = max(1, (int) collect($hosts)->max('requests'));
@endphp
@if (empty($hosts))
    <x-empty size="sm" title="No host data" description="No hostnames were recorded for the selected range."
        icon-name="unordered-list" />
@else
    <div x-data="{ page: 0, per: 10, total: {{ count($hosts) }} }">
        @foreach ($hosts as $row)
            @php
                $host = (string) ($row['host'] ?? '');
                $known = $host !== '';
                $requests = (int) ($row['requests'] ?? 0);
                $width = min(100, round(($requests / $maxRequests) * 100, 1));
            @endphp
            <div wire:key="{{ $keyPrefix }}-{{ $loop->index }}"
                x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                @if ($known)
                    <a href="https://{{ $host }}" target="_blank" rel="noopener noreferrer nofollow"
                        class="min-w-0 flex-1 truncate font-mono text-[12px] text-black hover:underline dark:text-fg">{{ $host }}</a>
                @else
                    <span class="min-w-0 flex-1 truncate text-[12px] text-neutral-500 dark:text-fg-dim">Unknown host</span>
                @endif
                <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
                    <div class="h-full rounded-full bg-[var(--chart-status-3xx)]" style="width: {{ $width }}%;"></div>
                </div>
                <span class="w-12 shrink-0 text-right text-[12px] font-medium tabular-nums text-black dark:text-fg"
                    title="{{ number_format($requests) }} requests">{{ compactNumber($requests) }}</span>
                <span class="hidden w-16 shrink-0 text-right text-[11px] tabular-nums text-neutral-400 sm:inline dark:text-fg-faint">{{ formatBytes((int) ($row['bandwidth'] ?? 0)) }}</span>
            </div>
        @endforeach
        @include('livewire.traffic._pager')
    </div>
@endif
