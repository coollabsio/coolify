<div>
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

    <x-modal-confirmation title="Confirm Environment Deletion?" buttonTitle="Delete Environment" isErrorButton
        submitAction="delete" :actions="['This will delete the selected environment and all resources inside it.']"
        confirmationLabel="Please confirm the execution of the actions by entering the Environment Name below"
        shortConfirmationLabel="Environment Name" confirmationText="{{ $environmentName }}" :confirmWithPassword="false"
        step2ButtonText="Permanently Delete" :disabled="!$queueWorkersAvailable" />
</div>
