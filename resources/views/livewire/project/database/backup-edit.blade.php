<form wire:submit="submit">
    <div class="flex flex-col gap-3 pb-4 sm:flex-row sm:items-center">
        <h2>Scheduled Backup</h2>
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            <x-forms.button type="submit" class="w-full sm:w-auto">
                Save
            </x-forms.button>
            @if (str($status)->startsWith('running'))
                <x-forms.button wire:click='backupNow' class="w-full sm:w-auto">Backup Now</x-forms.button>
            @endif
            @if ($backup->database_id !== 0)
                <div class="w-full sm:w-auto">
                    <x-modal-confirmation title="Confirm Backup Schedule Deletion?" isErrorButton submitAction="delete"
                        :checkboxes="$checkboxes" :actions="[
                            'The selected backup schedule will be deleted.',
                            'Scheduled backups for this database will be stopped (if this is the only backup schedule for this database).',
                        ]"
                        confirmationText="{{ $backup->database->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Database Name of the scheduled backups below"
                        shortConfirmationLabel="Database Name">
                        <x-slot:trigger>
                            <x-forms.button isError class="w-full sm:w-auto">Delete Backups and Schedule</x-forms.button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                </div>
            @endif
        </div>
    </div>
    <div class="w-full max-w-md pb-2">
        <x-forms.checkbox instantSave label="Backup Enabled" id="backupEnabled" />
        @if ($availableS3Storages->count() > 0)
            <x-forms.checkbox instantSave label="S3 Enabled" id="saveS3" />
        @else
            <x-forms.checkbox instantSave helper="No validated S3 storage available." label="S3 Enabled" id="saveS3"
                disabled />
        @endif
        @if ($saveS3)
            <x-forms.checkbox instantSave label="Disable Local Backup" id="disableLocalBackup"
                helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
        @else
            <x-forms.checkbox disabled label="Disable Local Backup" id="disableLocalBackup"
                helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
        @endif
    </div>
    <div class="w-full max-w-md pb-6">
        <div class="flex gap-1 items-center mb-1 text-sm font-medium">
            <span>S3 Storage</span>
            @if (!$saveS3)
                <span class="text-xs font-normal text-warning">(currently disabled)</span>
            @endif
            @if ($saveS3)
                <x-highlighted text="*" />
            @endif
        </div>
        <x-forms.select id="s3StorageId" wire:model.live="s3StorageId" :required="$saveS3"
            :disabled="$availableS3Storages->isEmpty()">
            @if ($availableS3Storages->isEmpty())
                <option value="">No S3 storage available</option>
            @else
                @foreach ($availableS3Storages as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            @endif
        </x-forms.select>
    </div>
    <div class="flex flex-col gap-2">
        <h3>Settings</h3>
        <div class="flex gap-2 flex-col ">
            @if ($backup->database_type === 'App\Models\StandalonePostgresql' && $backup->database_id !== 0)
                <div class="w-48">
                    <x-forms.checkbox label="Backup All Databases" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="Databases To Backup"
                        helper="Comma separated list of databases to backup. Empty will include the default one."
                        id="databasesToBackup" />
                @endif
            @elseif($backup->database_type === 'App\Models\StandaloneMongodb')
                <x-forms.input label="Databases To Include"
                    helper="A list of databases to backup. You can specify which collection(s) per database to exclude from the backup. Empty will include all databases and collections.<br><br>Example:<br><br>database1:collection1,collection2|database2:collection3,collection4<br><br> database1 will include all collections except collection1 and collection2. <br>database2 will include all collections except collection3 and collection4.<br><br>Another Example:<br><br>database1:collection1|database2<br><br> database1 will include all collections except collection1.<br>database2 will include ALL collections."
                    id="databasesToBackup" />
            @elseif($backup->database_type === 'App\Models\StandaloneMysql')
                <div class="w-48">
                    <x-forms.checkbox label="Backup All Databases" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="Databases To Backup"
                        helper="Comma separated list of databases to backup. Empty will include the default one."
                        id="databasesToBackup" />
                @endif
            @elseif($backup->database_type === 'App\Models\StandaloneMariadb')
                <div class="w-48">
                    <x-forms.checkbox label="Backup All Databases" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="Databases To Backup"
                        helper="Comma separated list of databases to backup. Empty will include the default one."
                        id="databasesToBackup" />
                @endif
            @elseif($backup->database_type === 'App\Models\StandaloneClickhouse')
                <x-forms.input label="Databases To Backup"
                    helper="Comma separated list of databases to backup. Empty will include the default one."
                    id="databasesToBackup" />
            @endif
        </div>
        <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
            <x-forms.input label="Frequency" id="frequency" required />
            <x-forms.input label="Timezone" id="timezone" disabled
                helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)" required />
            <x-forms.input label="Timeout" id="timeout" type="number" min="60" helper="The timeout of the backup job in seconds." required />
        </div>

        <h3 class="mt-6 mb-2 text-lg font-medium">Backup Retention Settings</h3>
        <div class="mb-4">
            <ul class="list-disc pl-6 space-y-2">
                <li>Setting a value to 0 means unlimited retention.</li>
                <li>The retention rules work independently - whichever limit is reached first will trigger cleanup.</li>
            </ul>
        </div>

        <div class="flex gap-6 flex-col">
            <div>
                <h4 class="mb-3 font-medium">Local Backup Retention</h4>
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

            @if ($saveS3)
                <div>
                    <h4 class="mb-3 font-medium">S3 Storage Retention</h4>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                        <x-forms.input label="Number of backups to keep" id="databaseBackupRetentionAmountS3"
                            type="number" min="0"
                            helper="Keeps only the specified number of most recent backups on S3 storage. Set to 0 for unlimited backups." required />
                        <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysS3" type="number"
                            min="0"
                            helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit." required />
                        <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageS3"
                            type="number" min="0" step="any"
                            helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage." required />
                    </div>
                </div>
            @endif
        </div>
    </div>
</form>
