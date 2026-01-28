<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency"
        helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." label="Frequency"
        required />

    @if ($isPostgresql)
        <h2>Backup Engine</h2>
        <x-forms.select id="backupEngine" label="Engine" wire:model.live="backupEngine">
            <option value="pg_dump">pg_dump (default)</option>
            @if ($pgbackrestAvailable)
                <option value="pgbackrest">pgBackRest (incremental)</option>
            @else
                <option value="pgbackrest" disabled>pgBackRest (enable in database settings first)</option>
            @endif
        </x-forms.select>

        @if ($backupEngine === 'pgbackrest')
            <x-forms.select id="backupType" label="Backup Type"
                helper="Full: complete backup. Differential: changes since last full. Incremental: changes since last backup of any type.">
                <option value="full">Full</option>
                <option value="diff">Differential</option>
                <option value="incr">Incremental</option>
            </x-forms.select>
        @endif
    @endif

    <h2>S3</h2>
    @if ($definedS3s->count() === 0)
        <div class="text-red-500">No validated S3 Storages found.</div>
    @else
        @if ($backupEngine === 'pgbackrest')
            <div class="text-sm dark:text-gray-400">
                pgBackRest handles S3 storage natively via database-level configuration. Configure S3 in the database's pgBackRest settings.
            </div>
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
    @endif
    <x-forms.button type="submit" @click="modalOpen=false">
        Save
    </x-forms.button>
</form>
