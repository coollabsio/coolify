{{-- Cluster server rows on the Servers page. Expects $nodes, $cluster (nullable), and $sentinelRelease. --}}
@php
    $serverGridClasses = 'grid grid-cols-[minmax(0,1fr)_auto] gap-3 px-4 md:min-w-[760px] md:grid-cols-[minmax(0,1fr)_9rem_6.5rem_10rem_9.5rem]';
@endphp
<div class="overflow-x-auto">
    <div
        class="{{ $serverGridClasses }} border-b border-neutral-200 bg-neutral-50 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
        <div>Server</div>
        <div class="hidden md:block">IP address</div>
        <div class="hidden md:block">Ingress</div>
        <div class="hidden md:block">Sentinel</div>
        <div>Status</div>
    </div>
    @foreach ($nodes as $node)
        @php
            $sentinelConnected = data_get(\Illuminate\Support\Facades\Cache::get($node->cacheKey()), 'status') === 'connected';
            [$serverStatus, $serverStatusType] = match (true) {
                ! $node->is_reachable => ['Unreachable', 'error'],
                ! $node->is_usable => ['Not ready', 'warning'],
                ! $sentinelConnected => ['Sentinel disconnected', 'warning'],
                default => ['Ready', 'success'],
            };
            $ingressState = $cluster ? $cluster->nodeIngressState($node) : ($node->is_ingress ? 'on' : 'off');
            $sentinelVersion = $node->runningSentinelVersion();
        @endphp
        <a wire:key="cluster-server-{{ $node->uuid }}" href="{{ route('node.show', ['node_uuid' => $node->uuid]) }}"
            {{ wireNavigate() }}
            class="{{ $serverGridClasses }} min-h-14 items-center border-b border-neutral-200 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
            <div class="flex min-w-0 items-center gap-3">
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim">
                    <x-reicon name="servers" class="size-4" />
                </div>
                <div class="min-w-0">
                    <p class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $node->name }}</p>
                    <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                        <span class="md:hidden">{{ $node->ip }}</span>
                        <span class="hidden md:inline">{{ $node->description }}</span>
                    </p>
                </div>
            </div>
            <div class="hidden min-w-0 items-center gap-1.5 truncate font-mono text-[12px] text-neutral-600 md:flex dark:text-fg-dim">
                <x-reicon name="network" class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" />
                <span class="truncate">{{ $node->ip ?: '-' }}</span>
            </div>
            <div class="hidden md:block">
                <x-status-badge :status="match ($ingressState) {
                    'active' => 'Active',
                    'pending' => 'Pending',
                    'on' => 'On',
                    default => 'Off',
                }" :type="match ($ingressState) {
                    'active', 'on' => 'success',
                    'pending' => 'warning',
                    default => 'neutral',
                }" />
            </div>
            <div class="hidden min-w-0 flex-col items-start gap-1 md:flex">
                <span class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ $sentinelVersion ?? '-' }}</span>
                @if ($node->needsSentinelUpgrade($sentinelRelease))
                    <x-status-badge status="Upgrade available" type="warning" />
                @endif
            </div>
            <div>
                <x-status-badge :status="$serverStatus" :type="$serverStatusType" />
            </div>
        </a>
    @endforeach
</div>
