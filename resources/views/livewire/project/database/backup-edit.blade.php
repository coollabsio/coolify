<form wire:submit.prevent="submit">
    <div class="flex gap-2 pb-2">
        <h2>Scheduled Backup</h2>
        <x-forms.button type="submit">
            Save
        </x-forms.button>
        <x-forms.button isError wire:click="delete">Delete</x-forms.button>
    </div>
    <div class="flex py-2 gap-10">
        <x-forms.checkbox instantSave label="Enabled" id="backup.enabled"/>
        <x-forms.checkbox instantSave label="Save to S3" id="backup.save_s3"/>
    </div>
    <div class="flex gap-2">
        <x-forms.input label="Frequency" id="backup.frequency"/>
        <x-forms.input label="Number of backups to keep (locally)" id="backup.number_of_backups_locally"/>
    </div>
</form>
