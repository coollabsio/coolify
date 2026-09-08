<div wire:poll.10000ms="refreshStatus">
    <x-status-summary :status="$application->status" />
    <x-application.restart-limit-warning :application="$application" />
</div>
