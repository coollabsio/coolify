<div class="w-full px-2">
    <x-modal-confirmation buttonFullWidth title="{{ __('modal.confirm_team_deletion') }}" buttonTitle="{{ __('modal.delete_team') }}" isErrorButton
        submitAction="delete" :actions="['The current Team will be permanently deleted.']" confirmationText="{{ $team }}"
        confirmationLabel="Please confirm the execution of the actions by entering the Team Name below"
        shortConfirmationLabel="Team Name" />
</div>
