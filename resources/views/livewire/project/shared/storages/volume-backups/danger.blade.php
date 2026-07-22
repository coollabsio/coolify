<div class="flex flex-col gap-6">
    <div>
        <h2>Danger Zone</h2>
        <div class="text-neutral-500">Woah. I hope you know what you are doing.</div>
    </div>

    <div>
        <h3>Delete Scheduled Backup</h3>
        <div class="pb-4 text-neutral-500">
            This permanently deletes the schedule and all local and S3 archives created by it. There is no coming back.
        </div>
        @if ($backup)
            <x-modal-confirmation title="Confirm Backup Schedule Deletion?" isErrorButton submitAction="delete"
                :actions="['The selected backup schedule will be deleted.', 'All local and S3 archives created by this schedule will be deleted.']"
                confirmationText="{{ $backup->targetName() }}"
                confirmationLabel="Please confirm by entering the {{ $backup->targetType() }} identifier below"
                shortConfirmationLabel="{{ $backup->targetType() }} identifier">
                <x-slot:trigger>
                    <x-forms.button isError>Delete Backups and Schedule</x-forms.button>
                </x-slot:trigger>
            </x-modal-confirmation>
        @endif
    </div>
</div>
