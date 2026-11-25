<x-application.settings-section title="Retention"
    description="The first reached limit removes the oldest backup. Use 0 for unlimited retention.">
    <div class="space-y-6">
        <div>
            <h4 class="mb-2 text-xs! font-medium! text-neutral-500! dark:text-fg-dim!">Local backups</h4>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-forms.input label="Backups to keep" id="databaseBackupRetentionAmountLocally"
                    type="number" min="0" helper="Maximum number of recent local backups." required />
                <x-forms.input label="Days to keep" id="databaseBackupRetentionDaysLocally"
                    type="number" min="0" helper="Remove local backups older than this many days." required />
                <x-forms.input label="Maximum storage (GB)"
                    id="databaseBackupRetentionMaxStorageLocally" type="number" min="0" step="any"
                    helper="Remove oldest local backups after this total size is reached." required />
            </div>
        </div>

        <div>
            <h4 class="mb-2 text-xs! font-medium! text-neutral-500! dark:text-fg-dim!">S3 backups</h4>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-forms.input label="Backups to keep" id="databaseBackupRetentionAmountS3"
                    type="number" min="0" helper="Maximum number of recent S3 backups." required />
                <x-forms.input label="Days to keep" id="databaseBackupRetentionDaysS3"
                    type="number" min="0" helper="Remove S3 backups older than this many days." required />
                <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageS3"
                    type="number" min="0" step="any"
                    helper="Remove oldest S3 backups after this total size is reached." required />
            </div>
        </div>
    </div>
</x-application.settings-section>
