<div x-data="{ deleteStorage: false }">
    <form wire:submit.prevent='submit' class="flex flex-col px-2 max-w-fit">
        <div class="flex gap-2">
            <x-forms.input id="storage.name" label="Name" required />
            <x-forms.input id="storage.host_path" label="Source Path" />
            <x-forms.input id="storage.mount_path" label="Destination Path" required />
        </div>
        <div class="pt-2">
            <x-forms.button type="submit">
                Update
            </x-forms.button>
            <x-forms.button x-on:click.prevent="deleteStorage = true">
                Delete
            </x-forms.button>
        </div>
    </form>
    <x-naked-modal show="deleteStorage" message="Are you sure you want to delete {{ $storage->name }}?" />
</div>
