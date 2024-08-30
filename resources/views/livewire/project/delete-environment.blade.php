<x-modal-confirmation 
    title="Confirm Delete Environment?"
    buttonTitle="Delete Environment"
    isErrorButton
    action="delete_environment"
    :actions="['This will delete the selected environment.']"
    confirmationLabel="Please confirm the execution of the actions by entering the Environment Name below"
    shortConfirmationLabel="Environment Name"
    submitAction="delete_environment"
    buttonTitle="Delete Environment"
    confirmText="{{ $environmentName }}"
    step3ButtonText="Permanently Delete Environment"
>
</x-modal-confirmation>
