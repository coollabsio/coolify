<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency"
        helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." label="Frequency"
        required />
    @if ($isPostgres)
        <x-forms.select id="backupMethod" label="Backup Method" wire:model.live="backupMethod"
            helper="pgBackRest stores incremental PostgreSQL backups in S3 and is recommended for large databases.">
            <option value="dump">pg_dump</option>
            <option value="pgbackrest">pgBackRest (incremental, S3 required)</option>
        </x-forms.select>
        @if ($backupMethod === 'pgbackrest')
            <div class="text-xs text-warning">pgBackRest backs up the whole PostgreSQL cluster. WAL archive verification is strongly recommended for recoverable/PITR-safe backups.</div>
            <x-forms.select id="pgBackRestBackupType" label="pgBackRest Backup Type" wire:model.live="pgBackRestBackupType"
                helper="Use incr for scheduled incremental backups. pgBackRest will create a full backup automatically when needed.">
                <option value="incr">Incremental</option>
                <option value="diff">Differential</option>
                <option value="full">Full</option>
            </x-forms.select>
            <x-forms.checkbox wire:model.live="pgBackRestRequireWalArchive" label="Require WAL archive verification"
                helper="Recommended. Requires archive_mode=on and archive_command using pgbackrest archive-push. If disabled, Coolify may run pgBackRest with --archive-check=n when WAL archiving is not configured." />
        @endif
    @endif
    <h2>S3</h2>
    @if ($definedS3s->count() === 0)
        <div class="text-red-500">No validated S3 Storages found.</div>
    @else
        <x-forms.checkbox wire:model.live="saveToS3" label="Save to S3" :disabled="$backupMethod === 'pgbackrest'" />
        @if ($backupMethod === 'pgbackrest')
            <div class="text-xs text-warning">pgBackRest requires S3 because it writes an incremental repository instead of a single local dump file.</div>
        @endif
        @if ($saveToS3 || $backupMethod === 'pgbackrest')
            <x-forms.select id="s3StorageId" label="Select a S3 Storage">
                @foreach ($definedS3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            </x-forms.select>
        @endif
    @endif
    <x-forms.button type="submit" @click="modalOpen=false">
        Save
    </x-forms.button>
</form>
