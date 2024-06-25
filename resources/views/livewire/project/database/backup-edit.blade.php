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
            <x-modal-confirmation isErrorButton>
                <x-slot:button-title>
                    Delete
                </x-slot:button-title>
                This will stop the scheduled backup for this database.<br>Please think again.
            </x-modal-confirmation>
        @endif
    </div>
    <div class="w-48 pb-2">
        <x-forms.checkbox instantSave label="Backup Enabled" id="backup.enabled" />
        <x-forms.checkbox instantSave label="S3 Enabled" id="backup.save_s3" />
    </div>
    @if ($backup->save_s3)
        <div class="pb-6">
            <x-forms.select id="backup.s3_storage_id" label="S3 Storage" required>
                <option value="default">Select a S3 storage</option>
                @foreach ($s3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    @endif
    <div class="flex flex-col gap-2">
        <div class="flex gap-2">
            @if ($backup->database_type === 'App\Models\StandalonePostgresql')
                <x-forms.input label="Databases To Backup"
                    helper="Comma separated list of databases to backup. Empty will include the default one."
                    id="backup.databases_to_backup" />
            @elseif($backup->database_type === 'App\Models\StandaloneMongodb')
                <x-forms.input label="Databases To Include"
                    helper="A list of databases to backup. You can specify which collection(s) per database to exclude from the backup. Empty will include all databases and collections.<br><br>Example:<br><br>database1:collection1,collection2|database2:collection3,collection4<br><br> database1 will include all collections except collection1 and collection2. <br>database2 will include all collections except collection3 and collection4.<br><br>Another Example:<br><br>database1:collection1|database2<br><br> database1 will include all collections except collection1.<br>database2 will include ALL collections."
                    id="backup.databases_to_backup" />
            @elseif($backup->database_type === 'App\Models\StandaloneMysql')
                <x-forms.input label="Databases To Backup"
                    helper="Comma separated list of databases to backup. Empty will include the default one."
                    id="backup.databases_to_backup" />
            @elseif($backup->database_type === 'App\Models\StandaloneMariadb')
                <x-forms.input label="Databases To Backup"
                    helper="Comma separated list of databases to backup. Empty will include the default one."
                    id="backup.databases_to_backup" />
            @endif
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Frequency" id="backup.frequency" />
            <x-forms.input label="Number of backups to keep (locally)" id="backup.number_of_backups_locally" />
        </div>
    </div>
</form>
