<div x-data="{ deleteStorage: false }">
    <x-naked-modal show="deleteStorage" title="Delete Storage"
        message='This storage will be deleted. It is not reversible. <br>Please think again.' />
    <form wire:submit.prevent='submit' class="flex flex-col gap-2 xl:items-end xl:flex-row">
        <x-forms.input id="storage.name" label="Name" required />
        <x-forms.input id="storage.host_path" label="Source Path" />
        <x-forms.input id="storage.mount_path" label="Destination Path" required />
        <div class="flex gap-2">
            <x-forms.button type="submit">
                Update
            </x-forms.button>
            <x-forms.button x-on:click.prevent="deleteStorage = true">
                Delete
            </x-forms.button>
        </div>
    </form>
</div>
