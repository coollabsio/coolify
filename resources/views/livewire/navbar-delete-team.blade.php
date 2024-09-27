<div>
    <x-modal-confirmation
        title="Confirm Team Deletion?"
        buttonTitle="Delete Team"
        isErrorButton
        submitAction="delete"
        :actions="['The current Team will be permanently deleted.']"
        confirmationText="{{ $team }}"
        confirmationLabel="Please confirm the execution of the actions by entering the Team Name below"
        shortConfirmationLabel="Team Name"
        step3ButtonText="Permanently Delete"
    />
</div>
