<section
    class="overflow-hidden rounded-[10px] border border-red-300 bg-red-50/80 dark:border-red-500/25 dark:bg-red-500/[0.06]">
    <div
        class="flex items-start justify-between gap-4 border-b border-red-200 px-5 py-4 dark:border-red-500/20">
        <div>
            <h2 class="text-sm font-semibold text-red-800 dark:text-red-300">Delete backup schedule</h2>
            <p class="mt-1 max-w-2xl text-sm text-red-700/80 dark:text-red-200/70">
                Permanently remove this schedule and optionally its backup archives. This cannot be undone.
            </p>
        </div>
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
    </div>
</section>
