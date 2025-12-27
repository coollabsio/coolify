@props([
    'title' => null,
    'lastDeploymentLink' => null,
    'resource' => null,
])
@if (str($resource->status)->startsWith('running'))
    <x-status.running :status="$resource->status" :title="$title" :lastDeploymentLink="$lastDeploymentLink" />
@elseif(str($resource->status)->startsWith('degraded'))
    <x-status.degraded :status="$resource->status" :title="$title" :lastDeploymentLink="$lastDeploymentLink" />
@elseif(str($resource->status)->startsWith('restarting') || str($resource->status)->startsWith('starting'))
    <x-status.restarting :status="$resource->status" :title="$title" :lastDeploymentLink="$lastDeploymentLink" />
@else
    <x-status.stopped :status="$resource->status" />
@endif
@if (isset($resource->restart_count) && $resource->restart_count > 0 && !str($resource->status)->startsWith('exited'))
    <div class="flex items-center pl-2">
        <span class="text-xs dark:text-warning" title="Container has restarted {{ $resource->restart_count }} time{{ $resource->restart_count > 1 ? 's' : '' }}. Last restart: {{ $resource->last_restart_at?->diffForHumans() }}">
            ({{ $resource->restart_count }}x restarts)
        </span>
    </div>
@endif
@if (!str($resource->status)->contains('exited') && $showRefreshButton)
    <button wire:loading.remove.delay.shortest wire:target="manualCheckStatus" title="Refresh Status" wire:click='manualCheckStatus'
        class="mx-1 dark:hover:fill-white fill-black dark:fill-warning">
        <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M12 2a10.016 10.016 0 0 0-7 2.877V3a1 1 0 1 0-2 0v4.5a1 1 0 0 0 1 1h4.5a1 1 0 0 0 0-2H6.218A7.98 7.98 0 0 1 20 12a1 1 0 0 0 2 0A10.012 10.012 0 0 0 12 2zm7.989 13.5h-4.5a1 1 0 0 0 0 2h2.293A7.98 7.98 0 0 1 4 12a1 1 0 0 0-2 0a9.986 9.986 0 0 0 16.989 7.133V21a1 1 0 0 0 2 0v-4.5a1 1 0 0 0-1-1z" />
        </svg>
    </button>
    <button wire:loading.delay.shortest wire:target="manualCheckStatus" title="Refreshing Status" wire:click='manualCheckStatus'
        class="mx-1 dark:hover:fill-white fill-black dark:fill-warning">
        <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M12 2a10.016 10.016 0 0 0-7 2.877V3a1 1 0 1 0-2 0v4.5a1 1 0 0 0 1 1h4.5a1 1 0 0 0 0-2H6.218A7.98 7.98 0 0 1 20 12a1 1 0 0 0 2 0A10.012 10.012 0 0 0 12 2zm7.989 13.5h-4.5a1 1 0 0 0 0 2h2.293A7.98 7.98 0 0 1 4 12a1 1 0 0 0-2 0a9.986 9.986 0 0 0 16.989 7.133V21a1 1 0 0 0 2 0v-4.5a1 1 0 0 0-1-1z" />
        </svg>
    </button>
@endif
