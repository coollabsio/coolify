<form wire:submit="save" class="flex flex-col gap-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <h2>S3</h2>
        <x-forms.button type="submit" class="w-full sm:w-auto">Save</x-forms.button>
        @if (!$saveToS3)
            <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                wire:target="toggleS3" isHighlighted :disabled="$availableS3Storages->isEmpty()">Enable S3</x-forms.button>
        @else
            <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                wire:target="toggleS3">Disable S3</x-forms.button>
        @endif
    </div>

    <div class="w-full max-w-md pb-2">
        <div class="flex items-center gap-1 mb-1 text-sm font-medium">
            <span>S3 Storage</span>
            @if (!$saveToS3)
                <span class="text-xs font-normal text-warning">(currently disabled)</span>
            @else
                <x-highlighted text="*" />
            @endif
        </div>
        <x-forms.select id="s3StorageId" wire:model.live="s3StorageId" :required="$saveToS3"
            :disabled="$availableS3Storages->isEmpty()">
            @if ($availableS3Storages->isEmpty())
                <option value="">No S3 storage available</option>
            @else
                @foreach ($availableS3Storages as $s3Storage)
                    <option value="{{ $s3Storage->id }}">{{ $s3Storage->name }}</option>
                @endforeach
            @endif
        </x-forms.select>
    </div>

    <div class="w-full max-w-md">
        @if ($saveToS3)
            <x-forms.checkbox instantSave id="disableLocalBackup" label="Disable Local Backup"
                helper="When enabled, backup files are deleted locally after a successful S3 upload." />
        @else
            <x-forms.checkbox id="disableLocalBackup" label="Disable Local Backup"
                helper="When enabled, backup files are deleted locally after a successful S3 upload." disabled />
        @endif
    </div>

    <div>
        <h3 class="mb-3">S3 Storage Retention</h3>
        <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
            <x-forms.input label="Number of backups to keep" id="retentionAmountS3" type="number" min="0"
                helper="Keeps only the specified number of most recent backups on S3 storage. Set to 0 for unlimited backups."
                required />
            <x-forms.input label="Days to keep backups" id="retentionDaysS3" type="number" min="0"
                helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit."
                required />
            <x-forms.input label="Maximum storage (GB)" id="retentionMaxStorageS3" type="number" min="0"
                step="any"
                helper="When total size of all backups in the current backup job exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage."
                required />
        </div>
    </div>
</form>
