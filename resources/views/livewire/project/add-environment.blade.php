<dialog id="newEnvironment" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit='submit'>
        <x-forms.input placeholder="production" id="name" label="Name" required />
        <x-forms.button onclick="newEnvironment.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
