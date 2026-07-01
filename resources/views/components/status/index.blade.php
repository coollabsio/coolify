@props([
    'title' => null,
    'lastDeploymentLink' => null,
    'resource' => null,
    'showRefreshButton' => true,
])
@php
    $stoppedAfterRestartLimit = $resource && method_exists($resource, 'stoppedAfterRestartLimit') && $resource->stoppedAfterRestartLimit();
@endphp
<div class="flex flex-wrap items-center gap-1">
    @if (str($resource->status)->startsWith('running'))
        <x-status.running :status="$resource->status" :title="$title" :lastDeploymentLink="$lastDeploymentLink" />
    @elseif(str($resource->status)->startsWith('degraded'))
        <x-status.degraded :status="$resource->status" :title="$title" :lastDeploymentLink="$lastDeploymentLink" />
    @elseif(str($resource->status)->startsWith('restarting') || str($resource->status)->startsWith('starting'))
        <x-status.restarting :status="$resource->status" :title="$title" :lastDeploymentLink="$lastDeploymentLink" />
    @else
        <x-status.stopped :status="$resource->status" />
    @endif
    @if (isset($resource->restart_count) && $resource->restart_count > 0 && (!str($resource->status)->startsWith('exited') || $stoppedAfterRestartLimit))
        <x-status-badge status="{{ $resource->restart_count }}x restarts" type="warning"
            title="Container has restarted {{ $resource->restart_count }} time{{ $resource->restart_count > 1 ? 's' : '' }}. Last restart: {{ $resource->last_restart_at?->diffForHumans() }}" />
    @endif
    @if ($stoppedAfterRestartLimit)
        <x-status-badge status="Stopped after reaching restart limit ({{ $resource->restart_count }}/{{ $resource->max_restart_count }})."
            type="warning"
            title="Container has crashed and Coolify stopped it after {{ $resource->restart_count }} restart attempts." />
    @endif
    @if (!str($resource->status)->contains('exited') && $showRefreshButton)
        <x-status-badge as="button" wire:target="manualCheckStatus" wire:loading.attr="disabled"
            wire:click='manualCheckStatus' status="Refresh" type="neutral" title="Refresh Status"
            aria-label="Refresh status"
            class="min-w-[4.5rem] justify-center cursor-pointer border-transparent hover:bg-neutral-200 disabled:cursor-wait disabled:opacity-70 dark:hover:bg-coolgray-300" />
    @endif
</div>
