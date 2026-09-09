@php
    $displayStatus = formatContainerStatus($complexStatus);
@endphp
<div class="flex flex-wrap items-center gap-1">
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
        <x-status-badge as="button" wire:target="manualCheckStatus" wire:loading.attr="disabled"
            wire:click='manualCheckStatus' status="Refresh" type="neutral" title="Refresh Status"
            aria-label="Refresh status"
            class="min-w-[4.5rem] justify-center cursor-pointer border-transparent hover:bg-neutral-200 disabled:cursor-wait disabled:opacity-70 dark:hover:bg-coolgray-300" />
    @endif
</div>
