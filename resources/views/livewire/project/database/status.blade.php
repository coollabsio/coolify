<div wire:poll.10000ms="refreshStatus">
    <x-status-summary :status="$database->status" title="Database status" />
    <x-application.restart-limit-warning :application="$database" />
</div>
