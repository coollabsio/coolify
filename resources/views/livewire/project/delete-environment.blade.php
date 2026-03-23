<div>
    @if (! $queueWorkersAvailable)
        <x-callout type="warning" title="Queue Worker Not Running" class="mb-4">
            Destructive cleanup jobs require active queue workers. Start Horizon/queue workers first, then retry.
        </x-callout>
    @endif

    <x-modal-confirmation title="Confirm Environment Deletion?" buttonTitle="Delete Environment" isErrorButton
        submitAction="delete" :actions="['This will delete the selected environment and all resources inside it.']"
        confirmationLabel="Please confirm the execution of the actions by entering the Environment Name below"
        shortConfirmationLabel="Environment Name" confirmationText="{{ $environmentName }}" :confirmWithPassword="false"
        step2ButtonText="Permanently Delete" :disabled="!$queueWorkersAvailable" />
</div>
