<div class="w-full px-2">
    <x-modal-confirmation buttonFullWidth title="{{ __('modal.confirm_team_deletion') }}" buttonTitle="{{ __('modal.delete_team') }}" isErrorButton
        submitAction="delete" :actions="['The current Team will be permanently deleted.']" confirmationText="{{ $team }}"
        confirmationLabel="{{ __('modal.confirm_team_name_label') }}"
        shortConfirmationLabel="{{ __('teams.team_name') }}" />
</div>
