<div class="application-settings-form">
    <x-application.settings-section title="Delete backup schedule"
        description="Permanently delete this schedule and optionally delete its local and S3 archives."
        class="danger-section">
        <x-danger-zone title="This action cannot be undone.">
            <p>You can select which backup archives to remove.</p>
            <x-slot:action>
            @if ($backup)
                <x-modal-confirmation title="Delete backup schedule?" isErrorButton submitAction="delete"
                    :checkboxes="$deleteScheduleCheckboxes"
                    :actions="['Delete the selected backup schedule.']"
                    confirmationText="{{ $backup->targetName() }}"
                    confirmationLabel="Enter the {{ $backup->targetType() }} identifier to confirm."
                    shortConfirmationLabel="{{ $backup->targetType() }} identifier">
                    <x-slot:trigger>
                        <x-forms.button isError>Delete schedule</x-forms.button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            @endif
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
</div>
