@props([
    'server',
    'proxyStatus' => null,
    'showSentinelStatus' => false,
])

@php
    $serverReady = $server->isFunctional();
    $proxyUpdateAvailable = $server->proxySet()
        && ($server->hasCurrentTraefikOutdatedInfo() || $server->hasPendingProxyConfiguration());
    $proxyNeedsAttention = $server->proxySet()
        && (! in_array($proxyStatus, ['running'], true) || $proxyUpdateAvailable);
    $sentinelNeedsAttention = $showSentinelStatus && ! $server->isSentinelLive();

    [$summaryLabel, $summaryType] = match (true) {
        ! $serverReady => ['Unavailable', 'error'],
        $proxyNeedsAttention || $sentinelNeedsAttention => ['Attention required', 'warning'],
        default => ['Ready', 'success'],
    };
@endphp

<div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false"
    @keydown.escape.window="open = false">
    <x-status-badge as="button" dynamic @click="open = !open" x-bind:aria-expanded="open" aria-haspopup="menu"
        class="cursor-pointer">
        <span @class([
            'size-1.5 shrink-0 rounded-full',
            'bg-success' => $summaryType === 'success',
            'bg-warning' => $summaryType === 'warning',
            'bg-error' => $summaryType === 'error',
        ])></span>
        <span class="truncate">{{ $summaryLabel }}</span>
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </x-status-badge>

    <div x-cloak x-show="open" x-transition.origin.top.right
        class="listbox-panel top-8! right-0! left-auto! z-[90]! w-64! min-w-64!" role="menu">
        <div class="flex items-center gap-1 px-3 py-2 text-[11px] font-medium text-neutral-400 dark:text-fg-faint">
            <span>System status</span>
            @if ($server->proxySet())
                <button type="button" wire:click="checkProxyStatus" wire:loading.attr="disabled"
                    wire:target="checkProxyStatus" aria-label="Refresh status" title="Refresh status"
                    class="inline-flex size-5 items-center justify-center rounded text-neutral-400 hover:bg-neutral-200 hover:text-neutral-700 disabled:cursor-wait dark:text-fg-faint dark:hover:bg-white/10 dark:hover:text-fg">
                    <x-reicon name="refresh" class="size-3" wire:loading.class="animate-spin" wire:target="checkProxyStatus" />
                </button>
            @endif
        </div>
        <div class="listbox-option cursor-default! gap-2.5!">
            <span @class([
                'size-1.5 shrink-0 rounded-full',
                'bg-success' => $serverReady,
                'bg-error' => ! $serverReady,
            ])></span>
            <span class="flex-1">Server</span>
            <span>{{ $serverReady ? 'Ready' : 'Unavailable' }}</span>
        </div>
        @if ($server->proxySet())
            <a href="{{ route('server.proxy', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
                class="listbox-option gap-2.5!" @click="open = false" role="menuitem">
                <span @class([
                    'size-1.5 shrink-0 rounded-full',
                    'bg-success' => $proxyStatus === 'running' && ! $proxyUpdateAvailable,
                    'bg-warning' => $proxyNeedsAttention && ($proxyUpdateAvailable || in_array($proxyStatus, ['starting', 'restarting', 'stopping'], true)),
                    'bg-error' => $proxyNeedsAttention && ! $proxyUpdateAvailable && ! in_array($proxyStatus, ['starting', 'restarting', 'stopping'], true),
                ])></span>
                <span class="flex-1">Proxy</span>
                <span>{{ str($proxyStatus ?: 'unknown')->headline() }}</span>
            </a>
        @endif
        @if ($showSentinelStatus)
            <a href="{{ route('server.sentinel', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
                class="listbox-option gap-2.5!" @click="open = false" role="menuitem">
                <span @class([
                    'size-1.5 shrink-0 rounded-full',
                    'bg-success' => ! $sentinelNeedsAttention,
                    'bg-warning' => $sentinelNeedsAttention,
                ])></span>
                <span class="flex-1">Sentinel</span>
                <span>{{ $server->isSentinelLive() ? 'In sync' : 'Out of sync' }}</span>
            </a>
        @endif
    </div>
</div>
