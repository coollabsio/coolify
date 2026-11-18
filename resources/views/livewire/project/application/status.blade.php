<div wire:poll.10000ms="refreshStatus" class="flex items-center gap-1">
    <x-status-summary :status="$application->status" :healthcheck-url="$healthcheckUrl"
        :container-name="$application->statusContainerName()" />
    <x-application.restart-limit-warning :application="$application" />
</div>
