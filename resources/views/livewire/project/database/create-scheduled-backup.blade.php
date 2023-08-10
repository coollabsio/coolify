<dialog id="createScheduledBackup" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit.prevent='submit'>
        <x-forms.input placeholder="1 * * * *" id="frequency" label="Frequency" required/>
        <x-forms.checkbox id="save_s3" label="Save to preconfigured S3"/>
        <x-forms.button onclick="createScheduledBackup.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
