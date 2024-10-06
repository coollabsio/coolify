<x-modal-confirmation title="Confirm Environment Deletion?" buttonTitle="Delete Environment" isErrorButton
    submitAction="delete" :actions="['This will delete the selected environment.']"
    confirmationLabel="Please confirm the execution of the actions by entering the Environment Name below"
    shortConfirmationLabel="Environment Name" confirmationText="{{ $environmentName }}" :confirmWithPassword="false"
    step2ButtonText="Permanently Delete" />
