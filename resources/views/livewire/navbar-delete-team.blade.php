<div class="w-full min-w-0">
    <x-modal-confirmation title="Confirm Team Deletion?" buttonFullWidth isErrorButton submitAction="delete"
        :actions="['The current Team will be permanently deleted.']" confirmationText="{{ $team }}"
        confirmationLabel="Please confirm the execution of the actions by entering the Team Name below"
        shortConfirmationLabel="Team Name">
        <x-slot:trigger>
            <button type="button" title="Delete Team" aria-label="Delete Team"
                class="menu-item justify-start text-left !text-error hover:!bg-error/10 dark:!text-error dark:hover:!bg-error/15">
                <x-reicon name="trash" class="menu-item-icon" />
                <span class="menu-item-label sidebar-collapsed-label text-left">Delete Team</span>
            </button>
        </x-slot:trigger>
    </x-modal-confirmation>
</div>
