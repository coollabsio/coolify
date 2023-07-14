<dialog id="newStorage" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit.prevent='submit'>
        <h3 class="text-lg font-bold">Add Storage Volume</h3>
        <x-forms.input placeholder="pv-name" id="name" label="Name" required />
        <x-forms.input placeholder="/root" id="host_path" label="Source Path" />
        <x-forms.input placeholder="/tmp/root" id="mount_path" label="Destination Path" required />
        <x-forms.button onclick="newStorage.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
