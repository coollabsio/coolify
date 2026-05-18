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
        @if ($isPostgresql && $backup->database_id !== 0)
            <x-forms.checkbox instantSave label="Use pgBackRest" 
                id="usePgbackrest"
                :checked="$engine === 'pgbackrest'"
                wire:click="togglePgbackrestEngine"
                helper="Use pgBackRest instead of pg_dump for efficient incremental backups with point-in-time recovery support." />
        @endif
        @if ($engine !== 'pgbackrest')
            @if ($s3s->count() > 0)
                <x-forms.checkbox instantSave label="S3 Enabled" id="saveS3" />
            @else
                <x-forms.checkbox instantSave helper="No validated S3 storage available." label="S3 Enabled" id="saveS3"
                    disabled />
            @endif
            @if ($saveS3)
                <x-forms.checkbox instantSave label="Disable Local Backup" id="disableLocalBackup"
                    helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3." />
            @else
                <x-forms.checkbox disabled label="Disable Local Backup" id="disableLocalBackup"
                    helper="Requires S3 backup to be enabled." />
            @endif
        @endif
    </div>
    @if ($engine !== 'pgbackrest' && $saveS3)
        <div class="pb-6">
            <x-forms.select id="s3StorageId" label="S3 Storage" required>
                <option value="" disabled>Select a S3 storage</option>
                @foreach ($s3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    @endif

    @if ($engine === 'pgbackrest')
        {{-- PgBackRest Settings Panel --}}
        <div class="flex flex-col gap-4 p-4 mb-6 border border-coolgray-300 dark:border-coolgray-400 rounded-lg bg-white dark:bg-coolgray-100">
            <div class="flex items-center gap-1">
                <h3 class="text-lg font-medium">pgBackRest Settings</h3>
                <span class="px-2 py-1 text-xs font-medium rounded-md bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200">
                    Enabled
                </span>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                pgBackRest provides efficient incremental backups with compression, parallel operations, and point-in-time recovery support.
            </p>

            {{-- Backup Type --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-forms.select id="pgbackrestBackupType" label="Backup Type" required>
                    <option value="full">Full - Complete database backup</option>
                    <option value="diff">Differential - Changes since last full backup</option>
                    <option value="incr">Incremental - Changes since last backup</option>
                </x-forms.select>
            </div>

            {{-- Compression Settings --}}
            <h4 class="mt-4 font-medium">Compression</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-forms.select id="pgbackrestCompressType" label="Compression Type">
                    <option value="lz4">LZ4 (Recommended - Fast)</option>
                    <option value="zst">Zstandard (Better compression)</option>
                    <option value="gz">Gzip (Compatible)</option>
                    <option value="bz2">Bzip2 (High compression)</option>
                    <option value="none">None</option>
                </x-forms.select>
                <x-forms.input type="number" id="pgbackrestCompressLevel" label="Compression Level" min="0" max="9"
                    helper="0 = no compression, 9 = maximum compression. Default: 6" />
            </div>

            {{-- Log Level --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-forms.select id="pgbackrestLogLevel" label="Log Level">
                    <option value="info">Info (Default)</option>
                    <option value="warn">Warning</option>
                    <option value="error">Error</option>
                    <option value="detail">Detail</option>
                    <option value="debug">Debug</option>
                    <option value="trace">Trace</option>
                    <option value="off">Off</option>
                </x-forms.select>
            </div>

            {{-- Point-in-Time Recovery Mode --}}
            <h4 class="mt-4 font-medium">Point-in-Time Recovery</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-forms.select id="pgbackrestArchiveMode" label="Archive Mode">
                    <option value="standard">Standard - Restore to any point in time</option>
                    <option value="reduced">Reduced - Limited PITR range, less storage</option>
                    <option value="minimal">Minimal - Backup points only, minimal storage</option>
                </x-forms.select>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                'Standard' keeps full WAL history for restoring to any moment. 'Minimal' only allows restoring to exact backup points but uses less storage.
            </p>

            {{-- Repository Configuration --}}
            <h3 class="mt-6">Backup Repositories</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Configure where backups are stored. You can enable both local and S3 storage for redundancy.
            </p>

            <div class="w-64 mb-6">
                @if ($s3s->count() > 0)
                    <x-forms.checkbox instantSave label="S3 Enabled" id="saveS3" 
                        helper="Store backups in S3 using pgBackRest's native S3 support." />
                @else
                    <x-forms.checkbox instantSave helper="No validated S3 storage available." label="S3 Enabled" id="saveS3"
                        disabled />
                @endif
                @if ($saveS3)
                    <x-forms.checkbox instantSave label="Disable Local Backup" id="disableLocalBackup"
                        helper="When enabled, backups will only be stored in S3." />
                @else
                    <x-forms.checkbox disabled label="Disable Local Backup" id="disableLocalBackup"
                        helper="Requires S3 to be enabled." />
                @endif
            </div>

            {{-- S3 Repository Settings --}}
            @if ($saveS3)
                <div class="mb-6">
                    <h4 class="mb-3">S3 Repository</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <x-forms.select id="s3RepoStorageId" label="S3 Storage" required>
                            <option value="" disabled>Select S3 storage</option>
                            @foreach ($s3s as $s3)
                                <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <x-forms.select id="s3RepoRetentionFullType" label="Full Retention Type">
                            <option value="count">Count (number of backups)</option>
                            <option value="time">Time (days)</option>
                        </x-forms.select>
                        <x-forms.input type="number" id="s3RepoRetentionFull"
                            label="{{ $s3RepoRetentionFullType === 'time' ? 'Days to Keep' : 'Backups to Keep' }}"
                            min="1" />
                        <x-forms.input type="number" id="s3RepoRetentionDiff" label="Diff Backups to Keep" min="1" />
                    </div>
                </div>
            @endif

            {{-- Local Repository Settings --}}
            @if ($this->showLocalRepoSettings)
                <div class="mb-6">
                    <h4 class="mb-3">Local Repository</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <x-forms.select id="localRepoRetentionFullType" label="Full Retention Type">
                            <option value="count">Count (number of backups)</option>
                            <option value="time">Time (days)</option>
                        </x-forms.select>
                        <x-forms.input type="number" id="localRepoRetentionFull"
                            label="{{ $localRepoRetentionFullType === 'time' ? 'Days to Keep' : 'Backups to Keep' }}"
                            min="1" />
                        <x-forms.input type="number" id="localRepoRetentionDiff" label="Diff Backups to Keep" min="1" />
                    </div>
                </div>
            @endif
        </div>
    @endif

    <div class="flex flex-col gap-2">
        <h3>Settings</h3>
        @if ($engine !== 'pgbackrest')
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
        @endif
        <div class="flex gap-2">
            <x-forms.input label="Frequency" id="frequency" required />
            <x-forms.input label="Timezone" id="timezone" disabled
                helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)" required />
            <x-forms.input label="Timeout" id="timeout" type="number" min="60" helper="The timeout of the backup job in seconds." required />
        </div>

<<<<<<< HEAD
        @if ($engine !== 'pgbackrest')
            <h3 class="mt-6 mb-2 text-lg font-medium">Backup Retention Settings</h3>
            <div class="mb-4">
                <ul class="list-disc pl-6 space-y-2">
                    <li>Setting a value to 0 means unlimited retention.</li>
                    <li>The retention rules work independently - whichever limit is reached first will trigger cleanup.</li>
                </ul>
=======
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
                        helper="Keeps only the specified number of most recent backups on the server. Set to 0 for unlimited backups." required />
                    <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysLocally" type="number"
                        min="0"
                        helper="Automatically removes backups older than the specified number of days. Set to 0 for no time limit." required />
                    <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageLocally"
                        type="number" min="0"
                        helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.001 for 1MB). Set to 0 for unlimited storage." required />
                </div>
>>>>>>> origin/next
            </div>

            <div class="flex gap-6 flex-col">
                <div>
                    <h4 class="mb-3 font-medium">Local Backup Retention</h4>
                    <div class="flex gap-2">
                        <x-forms.input label="Number of backups to keep" id="databaseBackupRetentionAmountLocally"
                            type="number" min="0"
<<<<<<< HEAD
                            helper="Keeps only the specified number of most recent backups on the server. Set to 0 for unlimited backups." />
                        <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysLocally" type="number"
                            min="0"
                            helper="Automatically removes backups older than the specified number of days. Set to 0 for no time limit." />
                        <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageLocally"
                            type="number" min="0"
                            helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.001 for 1MB). Set to 0 for unlimited storage." />
=======
                            helper="Keeps only the specified number of most recent backups on S3 storage. Set to 0 for unlimited backups." required />
                        <x-forms.input label="Days to keep backups" id="databaseBackupRetentionDaysS3" type="number"
                            min="0"
                            helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit." required />
                        <x-forms.input label="Maximum storage (GB)" id="databaseBackupRetentionMaxStorageS3"
                            type="number" min="0"
                            helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage." required />
>>>>>>> origin/next
                    </div>
                </div>

                @if ($saveS3)
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
        @endif
    </div>
</form>
