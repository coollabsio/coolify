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
        </x-callout>
    @endif

    <x-modal-confirmation title="Confirm Environment Deletion?" buttonTitle="Delete Environment" isErrorButton
        submitAction="delete" :actions="['This will delete the selected environment and all resources inside it.']"
        confirmationLabel="Please confirm the execution of the actions by entering the Environment Name below"
        shortConfirmationLabel="Environment Name" confirmationText="{{ $environmentName }}" :confirmWithPassword="false"
        step2ButtonText="Permanently Delete" :disabled="!$queueWorkersAvailable" />
</div>
