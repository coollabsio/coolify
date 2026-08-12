{{--
    Paginated "top paths" list. Each row shows the request path and links it to its
    live URL when the owning domain is known (new tab, rel="noopener noreferrer
    nofollow"). Expects `$paths` in scope; optional `$keyPrefix` to namespace wire:keys.

    @param iterable $paths      path rows: ['path', 'domain'?, 'requests', 'bytesOut', 'p95']
    @param ?string  $keyPrefix  wire:key prefix (default "analytics-path")
--}}
@php
    $paths = $paths ?? [];
    $keyPrefix = $keyPrefix ?? 'analytics-path';
@endphp
@if (empty($paths))
    <x-empty size="sm" title="No path data" description="No requests were recorded for the selected range."
        icon-name="unordered-list" />
@else
    <div x-data="{ page: 0, per: 10, total: {{ count($paths) }} }">
        @foreach ($paths as $path)
            @php
                $domain = $path['domain'] ?? null;
                $pathStr = (string) ($path['path'] ?? '');
                $href = $domain ? 'https://'.$domain.$pathStr : null;
            @endphp
            <div wire:key="{{ $keyPrefix }}-{{ $loop->index }}"
                x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per"
                class="flex min-h-11 items-center gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
                @if ($href)
                    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer nofollow"
                        class="min-w-0 flex-1 truncate font-mono text-[12px] text-black hover:underline dark:text-fg">{{ $pathStr }}</a>
                @else
                    <span class="min-w-0 flex-1 truncate font-mono text-[12px] text-black dark:text-fg">{{ $pathStr }}</span>
                @endif
                <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ number_format((int) ($path['requests'] ?? 0)) }} req</span>
                <span class="shrink-0 text-[12px] text-neutral-500 dark:text-fg-dim">{{ formatBytes((int) ($path['bytesOut'] ?? 0)) }}</span>
                <span class="hidden shrink-0 text-[12px] text-neutral-500 sm:inline dark:text-fg-dim">p95 {{ number_format((float) ($path['p95'] ?? 0), 1) }} ms</span>
            </div>
        @endforeach
        @include('livewire.traffic._pager')
    </div>
@endif
