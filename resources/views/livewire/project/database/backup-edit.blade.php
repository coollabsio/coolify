<form wire:submit.prevent="submit">
    <div class="flex gap-2 pb-2">
        <h2>Scheduled Backup</h2>
        <x-forms.button type="submit">
            Save
        </x-forms.button>
        @if ($backup->database_id !== 0)
            <x-forms.button isError wire:click="delete">Delete</x-forms.button>
        @endif
    </div>
    <div class="w-32 pb-2">
        <x-forms.checkbox instantSave label="Backup Enabled" id="backup.enabled"/>
        <x-forms.checkbox instantSave label="S3 Enabled" id="backup.save_s3"/>
    </div>
    @if($backup->save_s3)
        <div class="pb-6">
            <x-forms.select id="backup.s3_storage_id" label="S3 Storage" required>
                <option value="default" disabled>Select a S3 storage</option>
                @foreach($s3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    @endif
    <div class="flex gap-2">
        <x-forms.input label="Frequency" id="backup.frequency"/>
        <x-forms.input label="Number of backups to keep (locally)" id="backup.number_of_backups_locally"/>
    </div>
</form>
