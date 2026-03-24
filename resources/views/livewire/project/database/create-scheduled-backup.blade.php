<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency"
        helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." label="Frequency"
        required />

    @if ($database && method_exists($database, 'type') && $database->type() === 'standalone-postgresql')
        <h2>Backup Engine</h2>
        <x-forms.select id="engine" label="Engine" wire:model.live="engine">
            <option value="dump">pg_dump (Standard)</option>
            <option value="pgbackrest">pgBackRest (Incremental)</option>
        </x-forms.select>
        @if ($engine === 'pgbackrest')
            <x-forms.select id="backupType" label="Backup Type">
                <option value="full">Full</option>
                <option value="incr">Incremental</option>
                <option value="diff">Differential</option>
            </x-forms.select>
            <div class="text-sm text-neutral-400">
                pgBackRest enables incremental backups, reducing storage costs and backup time for large databases.
                The first backup will always be a full backup regardless of the selected type.
            </div>
        @endif
    @endif

    <h2>S3</h2>
    @if ($definedS3s->count() === 0)
        <div class="text-red-500">No validated S3 Storages found.</div>
    @else
        <x-forms.checkbox wire:model.live="saveToS3" label="Save to S3" />
        @if ($saveToS3)
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
