<dialog id="createScheduledBackup" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit.prevent='submit'>
        <h3>Details</h3>
        <x-forms.input placeholder="1 * * * *" id="frequency" label="Frequency" required/>
        <h3>S3 Storage</h3>
        <x-forms.checkbox id="save_s3" label="Save to S3"/>
        <x-forms.select label="S3 Storages" id="selected_storage_id">
            @foreach($s3s as $s3)
                <option value="{{ $s3->id }}">{{ $s3->name }}</option>
            @endforeach
        </x-forms.select>
        <x-forms.button onclick="createScheduledBackup.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
