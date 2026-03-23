<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming back!
    </div>

    @if ($canDelete)
        @if (! $queueWorkersAvailable)
            <x-callout type="warning" title="Queue Worker Not Running" class="mb-4">
                <div class="mb-3">
                    Destructive cleanup jobs require active queue workers. Start Horizon/queue workers first, then retry.
                </div>
                <div class="flex gap-2">
                    <x-forms.button wire:click="startQueueWorkers" wire:loading.attr="disabled" wire:target="startQueueWorkers" class="bg-warning">
                        <x-loading wire:loading wire:target="startQueueWorkers" />
                        Start Queue Workers
                    </x-forms.button>
                    <x-forms.button wire:click="refreshQueueWorkersStatus" wire:loading.attr="disabled" wire:target="refreshQueueWorkersStatus" class="bg-gray-600">
                        <x-loading wire:loading wire:target="refreshQueueWorkersStatus" />
                        Recheck
                    </x-forms.button>
                </div>
                <details class="mt-3 rounded border border-warning/40 bg-black/20 p-3 text-xs">
                    <summary class="cursor-pointer font-semibold text-warning">Manual setup (step-by-step)</summary>
                    <div class="mt-3 space-y-2 text-white/90">
                        <div>1) Go to the Coolify server terminal and open the app directory.</div>
                        <pre class="overflow-x-auto rounded bg-black/40 p-2">cd /data/coolify/source</pre>
                        <div>2) Start the worker service (if using Docker Compose):</div>
                        <pre class="overflow-x-auto rounded bg-black/40 p-2">docker compose up -d coolify-realtime coolify-worker</pre>
                        <div>3) Or start Horizon manually from the app container:</div>
                        <pre class="overflow-x-auto rounded bg-black/40 p-2">docker exec -it coolify php artisan start:horizon</pre>
                        <div>4) Check workers are running:</div>
                        <pre class="overflow-x-auto rounded bg-black/40 p-2">docker exec -it coolify php artisan horizon:status</pre>
                        <div>5) If still failing, inspect logs:</div>
                        <pre class="overflow-x-auto rounded bg-black/40 p-2">docker exec -it coolify php artisan horizon:list
tail -n 100 /data/coolify/source/storage/logs/laravel.log</pre>
                    </div>
                </details>
            </x-callout>
        @endif
        <x-modal-confirmation title="Confirm Resource Deletion?" buttonTitle="Delete" isErrorButton submitAction="delete"
            buttonTitle="Delete" :checkboxes="$checkboxes" :actions="['Permanently delete all containers of this resource.']" confirmationText="{{ $resourceName }}"
            confirmationLabel="Please confirm the execution of the actions by entering the Resource Name below" :disabled="!$queueWorkersAvailable"
            shortConfirmationLabel="Resource Name" />
    @else
        <x-callout type="danger" title="Insufficient Permissions">
            You don't have permission to delete this resource. Contact your team administrator for access.
        </x-callout>
    @endif
</div>
