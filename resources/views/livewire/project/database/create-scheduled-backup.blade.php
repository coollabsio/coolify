<dialog id="createScheduledBackup" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit='submit'>
        <h2>New Backup</h2>
        <x-forms.input placeholder="0 0 * * * or daily" id="frequency" label="Frequency" required />
        <h3>S3 Storage</h3>
        <x-forms.checkbox id="save_s3" label="Save to S3" />
        <x-forms.select id="selected_storage_id">
            @if ($s3s->count() === 0)
                <option value="0">No S3 Storages found.</option>
            @else
                @foreach ($s3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            @endif
        </x-forms.select>
        <x-forms.button onclick="createScheduledBackup.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
