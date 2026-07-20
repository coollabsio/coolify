<div class="flex flex-col gap-6">
    <div>
        <h2>Danger Zone</h2>
        <div class="text-neutral-500">Woah. I hope you know what you are doing.</div>
    </div>

    <div>
        <h3>Delete Scheduled Backup</h3>
        <div class="pb-4 text-neutral-500">
            This permanently deletes the schedule. You can also delete its associated backup archives. There is no coming back.
        </div>
        @if ($backup->database_id !== 0)
            <x-modal-confirmation title="Confirm Backup Schedule Deletion?" isErrorButton submitAction="delete"
                :checkboxes="$checkboxes" :actions="[
                    'The selected backup schedule will be deleted.',
                    'Scheduled backups for this database will be stopped (if this is the only backup schedule for this database).',
                ]"
                confirmationText="{{ $backup->database->name }}"
                confirmationLabel="Please confirm the execution of the actions by entering the Database Name of the scheduled backups below"
                shortConfirmationLabel="Database Name">
                <x-slot:trigger>
                    <x-forms.button isError>Delete Backups and Schedule</x-forms.button>
                </x-slot:trigger>
            </x-modal-confirmation>
        @endif
    </div>
</div>
