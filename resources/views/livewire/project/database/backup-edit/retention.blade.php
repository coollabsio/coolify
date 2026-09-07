<form wire:submit="submit">
    <x-unsaved-bar action="submit" />

    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>Retention</h2>
                <p>The first reached limit removes the oldest backup. Use 0 for unlimited retention.</p>
            </div>
        </div>
        <div class="application-settings-section-body space-y-6">
            <div>
                <h3 class="mb-3 text-sm font-semibold text-black dark:text-fg">Local backups</h3>
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

            <div class="border-t border-neutral-200 pt-6 dark:border-white/[0.06]">
                <h3 class="mb-3 text-sm font-semibold text-black dark:text-fg">S3 backups</h3>
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
    </section>
</form>
