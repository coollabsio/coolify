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
    @if ($backup->database_type === 'App\Models\StandalonePostgresql' && $backup->database_id !== 0)
        <div class="pb-4">
            <x-forms.select id="backupEngine" label="Backup Engine"
                helper="Choose the backup engine. 'pg_dump' uses traditional dump/restore. 'pgBackRest' enables WAL-based incremental backups with point-in-time recovery.">
                <option value="dump">pg_dump (Traditional)</option>
                <option value="pgbackrest">pgBackRest (Incremental / PITR)</option>
            </x-forms.select>
        </div>

        @if ($backupEngine === 'pgbackrest')
            <div class="pb-4 border-l-2 border-blue-500/50 pl-4">
                <h3 class="mb-3">pgBackRest Configuration</h3>
                <div class="flex flex-col gap-3">
                    <div class="flex gap-2">
                        <x-forms.select id="pgbackrestBackupType" label="Backup Type"
                            helper="Full: complete backup every time. Differential: changes since last full backup. Incremental: changes since last backup of any type.">
                            <option value="full">Full</option>
                            <option value="diff">Differential</option>
                            <option value="incr">Incremental</option>
                        </x-forms.select>
                        <x-forms.input label="Stanza Name" id="pgbackrestStanza"
                            helper="Custom stanza name. Leave empty to use the auto-generated name based on the database UUID."
                            placeholder="Auto-generated" />
                    </div>
                    <div class="flex gap-2">
                        <x-forms.select id="pgbackrestRepoType" label="Repository Type"
                            helper="Posix: store backups on local disk. S3: store backups in an S3-compatible bucket.">
                            <option value="posix">Local Disk (posix)</option>
                            <option value="s3">S3 Storage</option>
                        </x-forms.select>
                        <x-forms.select id="pgbackrestCompressType" label="Compression">
                            <option value="gz">gzip</option>
                            <option value="lz4">LZ4</option>
                            <option value="zst">Zstandard</option>
                            <option value="none">None</option>
                        </x-forms.select>
                        <x-forms.input label="Compression Level" id="pgbackrestCompressLevel" type="number" min="0"
                            max="9" />
                    </div>

                    @if ($pgbackrestRepoType === 's3')
                        <h4 class="mt-2 font-medium">S3 Repository Settings</h4>
                        <div class="flex gap-2">
                            <x-forms.input label="S3 Bucket" id="pgbackrestS3Bucket" required />
                            <x-forms.input label="S3 Endpoint" id="pgbackrestS3Endpoint" required
                                placeholder="s3.amazonaws.com" />
                            <x-forms.input label="S3 Region" id="pgbackrestS3Region" placeholder="us-east-1" />
                        </div>
                        <div class="flex gap-2">
                            <x-forms.input label="S3 Access Key" id="pgbackrestS3Key" type="password" />
                            <x-forms.input label="S3 Secret Key" id="pgbackrestS3Secret" type="password" />
                        </div>
                    @endif

                    <h4 class="mt-2 font-medium">Retention</h4>
                    <div class="flex gap-2">
                        <x-forms.input label="Full Backup Retention" id="pgbackrestRetentionFull" type="number"
                            min="1"
                            helper="Number of full backups to retain. Older full backups and their dependent incremental/differential backups will be expired." />
                        <x-forms.input label="Differential Backup Retention" id="pgbackrestRetentionDiff" type="number"
                            min="1"
                            helper="Number of differential backups to retain. Only applies when using differential backup type." />
                    </div>

                    <h4 class="mt-2 font-medium">Logging</h4>
                    <div class="flex gap-2">
                        <x-forms.select id="pgbackrestLogLevelConsole" label="Console Log Level">
                            <option value="off">Off</option>
                            <option value="error">Error</option>
                            <option value="warn">Warn</option>
                            <option value="info">Info</option>
                            <option value="detail">Detail</option>
                            <option value="debug">Debug</option>
                            <option value="trace">Trace</option>
                        </x-forms.select>
                        <x-forms.select id="pgbackrestLogLevelFile" label="File Log Level">
                            <option value="off">Off</option>
                            <option value="error">Error</option>
                            <option value="warn">Warn</option>
                            <option value="info">Info</option>
                            <option value="detail">Detail</option>
                            <option value="debug">Debug</option>
                            <option value="trace">Trace</option>
                        </x-forms.select>
                    </div>
                </div>
            </div>
        @endif
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
