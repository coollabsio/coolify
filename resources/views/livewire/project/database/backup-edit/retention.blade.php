<form wire:submit="submit" class="flex flex-col gap-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <h2>Retention</h2>
        <x-forms.button type="submit" class="w-full sm:w-auto">Save</x-forms.button>
    </div>

    <ul class="pl-6 space-y-2 list-disc">
        <li>Setting a value to 0 means unlimited retention.</li>
        <li>The retention rules work independently - whichever limit is reached first will trigger cleanup.</li>
    </ul>

    <div class="flex flex-col gap-6">
        <div>
            <h3 class="mb-3">Local Backup Retention</h3>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <x-forms.input label="Number of backups to keep" id="databaseBackupRetentionAmountLocally"
                    type="number" min="0"
                    helper="Keeps only the specified number of most recent backups on the server. Set to 0 for unlimited backups." required />
                <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysLocally" type="number"
                    min="0"
                    helper="Automatically removes backups older than the specified number of days. Set to 0 for no time limit." required />
                <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageLocally"
                    type="number" min="0" step="any"
                    helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.001 for 1MB). Set to 0 for unlimited storage." required />
            </div>
        </div>

        <div>
            <h3 class="mb-3">S3 Storage Retention</h3>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <x-forms.input label="Number of backups to keep" id="databaseBackupRetentionAmountS3" type="number"
                    min="0"
                    helper="Keeps only the specified number of most recent backups on S3 storage. Set to 0 for unlimited backups." required />
                <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysS3" type="number"
                    min="0"
                    helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit." required />
                <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageS3"
                    type="number" min="0" step="any"
                    helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage." required />
            </div>
        </div>
    </div>
</form>
