<x-modal-confirmation 
    title="Confirm Project Deletion?"
    buttonTitle="Delete Project"
    isErrorButton
    submitAction="delete"
    :actions="['This will delete the selected project.']"
    confirmationLabel="Please confirm the execution of the actions by entering the Project Name below"
    shortConfirmationLabel="Project Name"
    buttonTitle="Delete Project"
    confirmationText="{{ $projectName }}"
    step3ButtonText="Permanently Delete Project"
/>
