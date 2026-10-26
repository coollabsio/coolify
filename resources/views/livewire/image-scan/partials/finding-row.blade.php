@php
    /** @var \App\Services\ImageScan\Finding $finding */
    $finding = $row['finding'];
    $url = \App\Services\ImageScan\ResourceScans::advisoryUrl($finding->id);
    $aliases = array_values(array_filter($finding->aliases, fn (string $alias) => $alias !== $finding->id && \App\Services\ImageScan\ResourceScans::isAdvisoryId($alias)));
    $likelihood = \App\Services\ImageScan\ResourceScans::exploitLikelihood($finding->epss);
    $ignore = $row['ignore'];
    $muted = 'text-[11px] text-neutral-500 dark:text-fg-faint';
    $hasDetails = $finding->summary !== '' || $likelihood !== null || $aliases !== [] || $ignore !== null;
@endphp

<div wire:key="finding-{{ $nested ? 'nested-' : '' }}{{ $row['key'] }}" x-data="{ open: false }"
    class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.06]">
    <div @class([
        'data-table-row image-scan-findings-grid',
        'is-nested' => $nested,
        'has-image' => ! $nested && $showsImage,
    ])>
        <div class="flex min-w-0 items-center gap-1.5">
            @unless ($nested)
                <button type="button" class="icon-button" x-on:click="open = !open" :aria-expanded="open ? 'true' : 'false'"
                    aria-label="Show details of {{ $finding->id }}">
                    <x-reicon name="chevron-down" class="size-3.5 transition-transform duration-150"
                        ::class="open ? 'rotate-0' : '-rotate-90'" />
                </button>
            @endunless
            @if ($url !== null)
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                    class="truncate font-mono text-[12px] font-medium text-neutral-950 underline-offset-4 hover:underline dark:text-fg">{{ $finding->id }}</a>
            @else
                <span class="truncate font-mono text-[12px] font-medium text-neutral-950 dark:text-fg">{{ $finding->id }}</span>
            @endif
        </div>
        @unless ($nested)
            <span class="image-scan-secondary min-w-0 truncate text-[12px] text-neutral-700 dark:text-fg-dim" title="{{ $finding->package }}">{{ $finding->package }}</span>
            @if ($showsImage)
                <span class="image-scan-secondary min-w-0 truncate {{ $muted }}" title="{{ $row['image'] }}">{{ $row['image'] }}</span>
            @endif
        @endunless
        <span class="image-scan-secondary min-w-0 truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
            {{ $finding->version }}@if ($finding->fixedIn) → {{ $finding->fixedIn }}@endif
        </span>
        <span class="image-scan-severity-cell flex min-w-0 flex-wrap items-center gap-1">
            <x-image-scan.severity-badge :severity="strtolower($finding->severity)" />
            @if ($finding->kev)
                <span data-tooltip="Attackers are using this vulnerability, according to CISA.">
                    <x-status-badge type="error" label="Known exploited" />
                </span>
            @endif
        </span>
        <span class="flex justify-end">
            @if ($ignore !== null)
                <span class="{{ $muted }}" data-tooltip="{{ $ignore->reason }}">
                    Ignored{{ $ignore->expires_at ? ' until '.$ignore->expires_at->toFormattedDateString() : '' }}
                </span>
            @elseif ($canUpdate)
                <button type="button" class="button"
                    x-on:click="$dispatch('open-ignore-modal', {
                        target: {{ \Illuminate\Support\Js::from($finding->id) }},
                        kind: 'vulnerability',
                        owner: {{ \Illuminate\Support\Js::from($row['owner']) }},
                    })">Ignore</button>
            @endif
        </span>
    </div>
    @if ($hasDetails)
    <div @if (! $nested) x-show="open" x-cloak x-transition.opacity.duration.150ms @endif
        @class(['flex flex-col gap-1 px-4 pb-3 text-[12px] text-neutral-600 dark:text-fg-dim', 'pl-12' => ! $nested])>
        @if ($finding->summary !== '')
            <p class="max-w-3xl">{{ $finding->summary }}</p>
        @endif
        @if ($likelihood !== null)
            <p class="{{ $muted }}">Exploit likelihood: {{ $likelihood }}</p>
        @endif
        @if ($aliases !== [])
            <p class="{{ $muted }}">
                Also known as
                @foreach ($aliases as $alias)
                    @php $aliasUrl = \App\Services\ImageScan\ResourceScans::advisoryUrl($alias); @endphp
                    @if ($aliasUrl !== null)
                        <a href="{{ $aliasUrl }}" target="_blank" rel="noopener noreferrer" class="font-mono underline-offset-4 hover:underline">{{ $alias }}</a>{{ $loop->last ? '' : ',' }}
                    @else
                        <span class="font-mono">{{ $alias }}</span>{{ $loop->last ? '' : ',' }}
                    @endif
                @endforeach
            </p>
        @endif
        @if ($ignore !== null)
            <p class="{{ $muted }}">Reason: {{ $ignore->reason }}</p>
        @endif
    </div>
    @endif
</div>
