<div wire:poll.10000ms="refreshStatus">
    <x-status-summary :status="$service->status" title="Service status" container-name="Containers" />
</div>
