<div class="application-settings-form">
    <x-application.settings-section title="Delete backup schedule"
        description="Permanently delete this schedule and every local and S3 archive created by it."
        class="danger-section">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-[13px] font-medium text-red-700 dark:text-red-300">This action cannot be undone.</p>
                <p class="mt-1 text-[12px] text-red-600/80 dark:text-red-300/70">
                    Existing archives created by this schedule are removed with it.
                </p>
            </div>
            @if ($backup)
                <x-modal-confirmation title="Delete backup schedule?" isErrorButton submitAction="delete"
                    :actions="['Delete the selected backup schedule.', 'Delete every local and S3 archive created by this schedule.']"
                    confirmationText="{{ $backup->targetName() }}"
                    confirmationLabel="Enter the {{ $backup->targetType() }} identifier to confirm."
                    shortConfirmationLabel="{{ $backup->targetType() }} identifier">
                    <x-slot:trigger>
                        <x-forms.button isError>Delete schedule</x-forms.button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            @endif
        </div>
    </x-application.settings-section>
</div>
