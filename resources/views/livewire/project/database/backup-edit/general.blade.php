<form wire:submit="submit" class="flex flex-col gap-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <h2>General</h2>
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            <x-forms.button type="submit" class="w-full sm:w-auto">Save</x-forms.button>
            @if (!$backupEnabled)
                <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                    wire:target="toggleEnabled" isHighlighted>Enable Backup</x-forms.button>
            @else
                <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                    wire:target="toggleEnabled">Disable Backup</x-forms.button>
            @endif
            @if (str($status)->startsWith('running'))
                <x-forms.button wire:click="backupNow" class="w-full sm:w-auto">Backup Now</x-forms.button>
            @endif
        </div>
    </div>

    <div class="flex flex-col gap-2">
        <div class="flex flex-col gap-2">
            @if ($backup->database_type === 'App\Models\StandalonePostgresql' && $backup->database_id !== 0)
                <div class="w-48">
                    <x-forms.checkbox label="Backup All Databases" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="Databases To Backup"
                        helper="Comma separated list of databases to backup. Empty will include the default one."
                        id="databasesToBackup" />
                @endif
            @elseif ($backup->database_type === 'App\Models\StandaloneMongodb')
                <x-forms.input label="Databases To Include"
                    helper="A list of databases to backup. You can specify which collection(s) per database to exclude from the backup. Empty will include all databases and collections.<br><br>Example:<br><br>database1:collection1,collection2|database2:collection3,collection4<br><br> database1 will include all collections except collection1 and collection2. <br>database2 will include all collections except collection3 and collection4.<br><br>Another Example:<br><br>database1:collection1|database2<br><br> database1 will include all collections except collection1.<br>database2 will include ALL collections."
                    id="databasesToBackup" />
            @elseif ($backup->database_type === 'App\Models\StandaloneMysql')
                <div class="w-48">
                    <x-forms.checkbox label="Backup All Databases" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="Databases To Backup"
                        helper="Comma separated list of databases to backup. Empty will include the default one."
                        id="databasesToBackup" />
                @endif
            @elseif ($backup->database_type === 'App\Models\StandaloneMariadb')
                <div class="w-48">
                    <x-forms.checkbox label="Backup All Databases" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="Databases To Backup"
                        helper="Comma separated list of databases to backup. Empty will include the default one."
                        id="databasesToBackup" />
                @endif
            @elseif ($backup->database_type === 'App\Models\StandaloneClickhouse')
                <x-forms.input label="Databases To Backup"
                    helper="Comma separated list of databases to backup. Empty will include the default one."
                    id="databasesToBackup" />
            @endif
        </div>
        <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
            <x-forms.input label="Frequency" id="frequency" required />
            <x-forms.input label="Timezone" id="timezone" disabled
                helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)" required />
            <x-forms.input label="Timeout" id="timeout" type="number" min="60"
                helper="The timeout of the backup job in seconds." required />
        </div>
    </div>
</form>
