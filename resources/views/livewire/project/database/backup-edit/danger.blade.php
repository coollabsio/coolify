<div class="application-settings-form">
    <x-application.settings-section title="Delete backup schedule"
        description="Permanently remove this schedule and optionally its backup archives.">
        <x-danger-zone title="This action cannot be undone.">
            <p>You can select which backup archives to remove.</p>
            <x-slot:action>
        @if ($backup->database_id !== 0)
            <x-modal-confirmation title="Confirm Backup Schedule Deletion?" isErrorButton submitAction="delete"
                :checkboxes="$checkboxes" :actions="[
                    'The selected backup schedule will be deleted.',
                    'Scheduled backups for this database will stop if this is its only schedule.',
                ]"
                confirmationText="{{ $backup->database->name }}"
                confirmationLabel="Enter the database name to confirm deletion."
                shortConfirmationLabel="Database Name">
                <x-slot:trigger>
                    <x-forms.button isError>Delete schedule</x-forms.button>
                </x-slot:trigger>
            </x-modal-confirmation>
        @endif
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
</div>
