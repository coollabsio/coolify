<div wire:poll.10000ms="refreshStatus">
    @php($displayStatus = $selectedResource?->status ?? $service->status)
    <x-status-summary :status="$displayStatus" :title="$selectedResource ? 'Resource status' : 'Service status'"
        :container-name="$selectedResource ? 'Container' : 'Containers'" />
    @if ($selectedResource)
        <x-application.restart-limit-warning :application="$selectedResource" />
    @endif
</div>
