@php
    // Transform colon format to human-readable format for UI display
    // running:healthy → Running (healthy)
    // running:unhealthy:excluded → Running (unhealthy, excluded)
    // exited:excluded → Exited (excluded)
    $isExcluded = str($complexStatus)->endsWith(':excluded');
    $parts = explode(':', $complexStatus);

    if ($isExcluded) {
        if (count($parts) === 3) {
            // Has health status: running:unhealthy:excluded → Running (unhealthy, excluded)
            $displayStatus = str($parts[0])->headline() . ' (' . $parts[1] . ', excluded)';
        } else {
            // No health status: exited:excluded → Exited (excluded)
            $displayStatus = str($parts[0])->headline() . ' (excluded)';
        }
    } elseif (count($parts) >= 2 && !str($complexStatus)->startsWith('Proxy')) {
        // Regular colon format: running:healthy → Running (healthy)
        $displayStatus = str($parts[0])->headline() . ' (' . $parts[1] . ')';
    } else {
        // No transformation needed (simple status or already in parentheses format)
        $displayStatus = str($complexStatus)->headline();
    }
@endphp
@if (str($displayStatus)->lower()->contains('running'))
    <x-status.running :status="$displayStatus" />
@elseif(str($displayStatus)->lower()->contains('starting'))
    <x-status.restarting :status="$displayStatus" />
@elseif(str($displayStatus)->lower()->contains('restarting'))
    <x-status.restarting :status="$displayStatus" />
@elseif(str($displayStatus)->lower()->contains('degraded'))
    <x-status.degraded :status="$displayStatus" />
@else
    <x-status.stopped :status="$displayStatus" />
@endif
@if (!str($complexStatus)->contains('exited') && $showRefreshButton)
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
