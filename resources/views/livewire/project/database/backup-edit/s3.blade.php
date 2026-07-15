@if ($availableS3Storages->isEmpty())
    <div class="flex flex-col gap-4">
        <h2>S3</h2>
        <div>
            No validated S3 available. Configure one <a class="underline dark:text-white" {{ wireNavigate() }}
                href="{{ route('storage.index') }}">here</a>.
        </div>
    </div>
@else
<form wire:submit="submit" class="flex flex-col gap-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <h2>S3</h2>
        <x-forms.button type="submit" class="w-full sm:w-auto">Save</x-forms.button>
        @if (!$saveS3)
            <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                wire:target="toggleS3" isHighlighted>Enable S3</x-forms.button>
        @else
            <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                wire:target="toggleS3">Disable S3</x-forms.button>
        @endif
    </div>

    <div class="w-full max-w-md pb-2">
        <div class="flex items-center gap-1 mb-1 text-sm font-medium">
            <span>S3 Storage</span>
            @if (!$saveS3)
                <span class="text-xs font-normal text-warning">(currently disabled)</span>
            @else
                <x-highlighted text="*" />
            @endif
        </div>
        <x-forms.select id="s3StorageId" wire:model.live="s3StorageId" :required="$saveS3">
            @foreach ($availableS3Storages as $s3)
                <option value="{{ $s3->id }}">{{ $s3->name }}</option>
            @endforeach
        </x-forms.select>
    </div>

    <div class="w-full max-w-md">
        @if ($saveS3)
            <x-forms.checkbox instantSave label="Disable Local Backup" id="disableLocalBackup"
                helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
        @else
            <x-forms.checkbox disabled label="Disable Local Backup" id="disableLocalBackup"
                helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
        @endif
    </div>

</form>
@endif
