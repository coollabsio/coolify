<x-modal-confirmation 
    title="Confirm Project Deletion?"
    buttonTitle="Delete Project"
    isErrorButton
    action="delete_project"
    :actions="['This will delete the selected project.']"
    confirmationLabel="Please confirm the execution of the actions by entering the Project Name below"
    shortConfirmationLabel="Project Name"
    submitAction="delete_project"
    buttonTitle="Delete Project"
    confirmText="{{ $projectName }}"
    step3ButtonText="Permanently Delete Project"
/>
