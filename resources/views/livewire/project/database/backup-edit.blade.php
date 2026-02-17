<form wire:submit="submit">
    <div class="flex gap-2 pb-2">
        <h2>Scheduled Backup</h2>
        <x-forms.button type="submit">
            Save
        </x-forms.button>
        @if (str($status)->startsWith('running'))
            <livewire:project.database.backup-now :backup="$backup" />
        @endif
        @if ($backup->database_id !== 0)
            <x-modal-confirmation title="Confirm Backup Schedule Deletion?" buttonTitle="Delete Backups and Schedule"
                isErrorButton submitAction="delete" :checkboxes="$checkboxes" :actions="[
                    'The selected backup schedule will be deleted.',
                    'Scheduled backups for this database will be stopped (if this is the only backup schedule for this database).',
                ]"
                confirmationText="{{ $backup->database->name }}"
                confirmationLabel="Please confirm the execution of the actions by entering the Database Name of the scheduled backups below"
                shortConfirmationLabel="Database Name" />
        @endif
    </div>
    <div class="w-64 pb-2">
        <x-forms.checkbox instantSave label="Backup Enabled" id="backupEnabled" />
        @if ($s3s->count() > 0)
            <x-forms.checkbox instantSave label="S3 Enabled" id="saveS3" />
        @else
            <x-forms.checkbox instantSave helper="No validated S3 storage available." label="S3 Enabled" id="saveS3"
                disabled />
        @endif
        @if ($backup->save_s3)
            <x-forms.checkbox instantSave label="Disable Local Backup" id="disableLocalBackup"
                helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
        @else
            <x-forms.checkbox disabled label="Disable Local Backup" id="disableLocalBackup"
                helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
        @endif
    </div>
    @if ($backup->save_s3)
        <div class="pb-6">
            <x-forms.select id="s3StorageId" label="S3 Storage" required>
                <option value="default" disabled>Select a S3 storage</option>
                @foreach ($s3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    @endif
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
            @endif
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Frequency" id="frequency" />
            <x-forms.input label="Timezone" id="timezone" disabled
                helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)" />
            <x-forms.input label="Timeout" id="timeout" helper="The timeout of the backup job in seconds." />
        </div>

        {{-- pgBackRest Section (PostgreSQL only) --}}
        @if ($backup->database_type === 'App\Models\StandalonePostgresql' && $backup->database_id !== 0)
            <div class="mt-6 border border-gray-200 dark:border-coolgray-300 rounded-md p-4">
                <h3 class="text-lg font-medium mb-2">pgBackRest (Incremental Backups)</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Enable pgBackRest for efficient incremental and differential backups. This is recommended for large databases (100GB+) as it significantly reduces backup time and storage costs.
                    When enabled, this replaces the standard pg_dump backup method with pgBackRest's WAL-based backup system.
                </p>
                <div class="w-64 pb-4">
                    <x-forms.checkbox instantSave label="Enable pgBackRest" id="usePgbackrest"
                        helper="Use pgBackRest instead of pg_dump for backups. Enables WAL archiving, incremental backups, and efficient restores." />
                </div>

                @if ($usePgbackrest)
                    <div class="flex flex-col gap-4">
                        <div class="flex gap-2">
                            <x-forms.select id="pgbackrestBackupType" label="Backup Type"
                                helper="Full: Complete backup of all data. Differential: Only changes since last full backup. Incremental: Only changes since last backup of any type.">
                                <option value="full">Full</option>
                                <option value="diff">Differential</option>
                                <option value="incr">Incremental</option>
                            </x-forms.select>
                            <x-forms.input label="Full Backup Retention" id="pgbackrestRetentionFull" type="number"
                                min="1"
                                helper="Number of full backups to retain. Older full backups and their dependent differential/incremental backups will be removed." />
                            <x-forms.input label="Differential Backup Retention" id="pgbackrestRetentionDiff"
                                type="number" min="1"
                                helper="Number of differential backups to retain per full backup." />
                        </div>

                        <div class="flex gap-2">
                            <x-forms.select id="pgbackrestRepoType" label="Repository Type" wire:model.live="pgbackrestRepoType"
                                helper="Where to store pgBackRest backups. Local (posix) stores on the server's filesystem. S3 stores directly to an S3-compatible service (MinIO, AWS S3, etc).">
                                <option value="posix">Local (posix)</option>
                                <option value="s3">S3 / MinIO</option>
                            </x-forms.select>
                        </div>

                        @if ($pgbackrestRepoType === 's3')
                            <div class="border border-gray-200 dark:border-coolgray-300 rounded-md p-4">
                                <h4 class="font-medium mb-3">S3 / MinIO Configuration</h4>
                                <div class="flex flex-col gap-2">
                                    <div class="flex gap-2">
                                        <x-forms.input label="S3 Bucket" id="pgbackrestS3Bucket" required
                                            helper="The S3 bucket name for storing backups." />
                                        <x-forms.input label="S3 Endpoint" id="pgbackrestS3Endpoint" required
                                            helper="The S3 endpoint URL (e.g., https://s3.amazonaws.com or http://minio:9000 for MinIO)." />
                                        <x-forms.input label="S3 Region" id="pgbackrestS3Region"
                                            helper="The S3 region (default: us-east-1)." />
                                    </div>
                                    <div class="flex gap-2">
                                        <x-forms.input label="S3 Access Key" id="pgbackrestS3Key" required
                                            helper="The S3 access key ID." />
                                        <x-forms.input label="S3 Secret Key" id="pgbackrestS3Secret" type="password"
                                            required helper="The S3 secret access key." />
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="flex gap-2 mt-2">
                            <x-forms.button wire:click.prevent="pgbackrestInfo" type="button">
                                View Backup Info
                            </x-forms.button>
                            <x-forms.button wire:click.prevent="pgbackrestRestore" type="button" isError
                                confirm="Are you sure you want to restore from the latest pgBackRest backup? This will stop the database, restore data, and restart it.">
                                Restore Latest Backup
                            </x-forms.button>
                        </div>

                        <div x-data="{ pgbackrestInfoText: '' }"
                            x-on:pgbackrest-info.window="pgbackrestInfoText = $event.detail.info">
                            <div x-show="pgbackrestInfoText" x-cloak>
                                <h4 class="font-medium mt-2 mb-1">pgBackRest Repository Info</h4>
                                <pre
                                    class="text-xs bg-gray-100 dark:bg-coolgray-200 p-3 rounded-md overflow-x-auto whitespace-pre-wrap"
                                    x-text="pgbackrestInfoText"></pre>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

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
                <div class="flex gap-2">
                    <x-forms.input label="Number of backups to keep" id="databaseBackupRetentionAmountLocally"
                        type="number" min="0"
                        helper="Keeps only the specified number of most recent backups on the server. Set to 0 for unlimited backups." />
                    <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysLocally" type="number"
                        min="0"
                        helper="Automatically removes backups older than the specified number of days. Set to 0 for no time limit." />
                    <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageLocally"
                        type="number" min="0"
                        helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.001 for 1MB). Set to 0 for unlimited storage." />
                </div>
            </div>

            @if ($backup->save_s3)
                <div>
                    <h4 class="mb-3 font-medium">S3 Storage Retention</h4>
                    <div class="flex gap-2">
                        <x-forms.input label="Number of backups to keep" id="databaseBackupRetentionAmountS3"
                            type="number" min="0"
                            helper="Keeps only the specified number of most recent backups on S3 storage. Set to 0 for unlimited backups." />
                        <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysS3" type="number"
                            min="0"
                            helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit." />
                        <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageS3"
                            type="number" min="0"
                            helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage." />
                    </div>
                </div>
            @endif
        </div>
    </div>
</form>
