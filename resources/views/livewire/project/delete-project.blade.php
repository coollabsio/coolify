<div>
    <x-modal-confirmation title="Confirm Project Deletion?" buttonTitle="Delete Project" isErrorButton submitAction="delete"
        :actions="[
            'This will delete the selected project.',
            'All environments and resources inside this project will be deleted as well.',
        ]" confirmationLabel="Please confirm the execution of the actions by entering the Project Name below"
        shortConfirmationLabel="Project Name" confirmationText="{{ $projectName }}" :confirmWithPassword="false"
        step2ButtonText="Permanently Delete" />
</div>
