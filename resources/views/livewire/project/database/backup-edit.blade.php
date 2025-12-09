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
                @if ($pgbackrestAvailable)
                    <div class="pb-4 border-b border-neutral-700">
                        <h4 class="font-medium pb-2">pgBackRest</h4>
                        <div class="w-64">
                            <x-forms.checkbox label="Use pgBackRest" id="usePgbackrest" instantSave
                                helper="Use pgBackRest for efficient incremental backups instead of pg_dump." />
                        </div>
                        @if ($usePgbackrest)
                            <div class="pt-2 w-64">
                                <x-forms.select label="Backup Type" id="pgbackrestBackupType">
                                    <option value="full">Full</option>
                                    <option value="diff">Differential</option>
                                    <option value="incr">Incremental</option>
                                </x-forms.select>
                            </div>
                            <div class="pt-4" x-data="{ retentionType: '{{ $pgbackrestRetentionFullType }}' }">
                                <h5 class="font-medium pb-2">pgBackRest Retention</h5>
                                <p class="text-sm text-neutral-400 pb-3">
                                    pgBackRest manages its own backup retention. These settings apply to all repositories attached to this schedule.
                                </p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div>
                                        <x-forms.select label="Full Retention Type" id="pgbackrestRetentionFullType"
                                            x-model="retentionType"
                                            helper="'Count' retains a fixed number of backups. 'Time' retains backups for a number of days.">
                                            <option value="count">Count (number of backups)</option>
                                            <option value="time">Time (days)</option>
                                        </x-forms.select>
                                    </div>
                                    <div x-show="retentionType === 'time'">
                                        <x-forms.input label="Days to Keep Full Backups" id="pgbackrestRetentionFull"
                                            type="number" min="1"
                                            helper="Full backups older than this many days will be removed." />
                                    </div>
                                    <div x-show="retentionType === 'count'" x-cloak>
                                        <x-forms.input label="Full Backups to Keep" id="pgbackrestRetentionFull"
                                            type="number" min="1"
                                            helper="Number of full backups to retain. Oldest beyond this count are deleted." />
                                    </div>
                                    <div>
                                        <x-forms.input label="Diff Backups to Keep" id="pgbackrestRetentionDiff"
                                            type="number" min="1"
                                            helper="Number of differential backups to retain per full backup." />
                                    </div>
                                    <div>
                                        <x-forms.select label="Point-in-Time Recovery" id="pgbackrestRetentionArchiveType"
                                            helper="Controls WAL archive retention for point-in-time recovery (PITR). 'Standard' lets you restore to any moment in time. 'Minimal' only allows restoring to exact backup points, not times in between - but uses less storage. Backups are always valid regardless of this setting.">
                                            <option value="full">Standard (restore to any time)</option>
                                            <option value="diff">Reduced (less PITR range)</option>
                                            <option value="incr">Minimal (backup points only)</option>
                                        </x-forms.select>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
                @if (!$usePgbackrest)
                    <div class="w-48">
                        <x-forms.checkbox label="Backup All Databases" id="dumpAll" instantSave />
                    </div>
                    @if (!$backup->dump_all)
                        <x-forms.input label="Databases To Backup"
                            helper="Comma separated list of databases to backup. Empty will include the default one."
                            id="databasesToBackup" />
                    @endif
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

        @if (!$usePgbackrest)
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
        @endif
    </div>
</form>
