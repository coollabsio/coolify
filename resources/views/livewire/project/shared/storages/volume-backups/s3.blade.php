@if ($availableS3Storages->isEmpty())
    <x-application.settings-section title="S3 storage"
        description="Send volume backup archives to a validated object storage destination." flush>
        <x-empty title="No validated S3 storage"
            description="Add and validate an S3 storage destination before enabling remote backups."
            icon-name="storages">
            <x-slot:contents>
                <a class="button" {{ wireNavigate() }} href="{{ route('storage.index') }}">Open S3 storage</a>
            </x-slot:contents>
        </x-empty>
    </x-application.settings-section>
@else
    <form wire:submit="save">
        <x-unsaved-bar action="save" />

        <x-application.settings-section title="S3 storage"
            description="Choose where remote copies are stored and whether local archives are retained.">
            <x-slot:actions>
                @if (! $saveToS3)
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3" isHighlighted canGate="update" :canResource="$resource">
                        Enable S3
                    </x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3" canGate="update" :canResource="$resource">
                        Disable S3
                    </x-forms.button>
                @endif
            </x-slot:actions>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.listbox canGate="update" :canResource="$resource" id="s3StorageId" label="S3 storage" :required="$saveToS3"
                    :disabled="! auth()->user()?->can('update', $resource)"
                    :options="$availableS3Storages->map(fn ($s3Storage) => [
                        'value' => $s3Storage->id,
                        'label' => $s3Storage->name,
                    ])->values()->all()" />
                <x-forms.listbox canGate="update" :canResource="$resource" id="disableLocalBackup" label="Local copy"
                    :disabled="! $saveToS3 || ! auth()->user()?->can('update', $resource)" :options="[
                        ['value' => false, 'label' => 'Keep local backup'],
                        ['value' => true, 'label' => 'Delete after S3 upload'],
                    ]" />
            </div>
        </x-application.settings-section>
    </form>
@endif
