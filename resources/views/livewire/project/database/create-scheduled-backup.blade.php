<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency"
        helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." label="Frequency"
        required />

    @if ($pgbackrestAvailable)
        <h2>pgBackRest</h2>
        <x-forms.checkbox wire:model.live="usePgbackrest" label="Use pgBackRest for backup"
            helper="Use pgBackRest instead of pg_dump for more efficient incremental backups." />
        @if ($usePgbackrest)
            <x-forms.select id="pgbackrestBackupType" label="Backup Type">
                <option value="full">Full - Complete backup of the database</option>
                <option value="diff">Differential - Changes since last full backup</option>
                <option value="incr">Incremental - Changes since last backup</option>
            </x-forms.select>
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
